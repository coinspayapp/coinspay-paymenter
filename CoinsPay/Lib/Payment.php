<?php

namespace Paymenter\Extensions\Gateways\CoinsPay\Lib;

use RuntimeException;

/**
 * Paying a Paymenter invoice: makes or reuses a CoinsPay invoice for what is due and sends the
 * client to its payment page. From the WHMCS module (Payment.php); Paymenter calls pay() itself
 * when the signed-in client presses "Pay", so no signed form is needed.
 */
final class Payment
{
    /** A closed invoice is polled one more day for a late payment. */
    public const POLL_AFTER_CLOSE = 86400;

    /** Currency of the CoinsPay invoice being made, for the error message. */
    private string $currency = '';

    public function __construct(private readonly Module $module) {}

    /**
     * @param  int  $pmInvoice  Paymenter invoice.
     * @return array {redirect} or {error}.
     */
    public function start(int $pmInvoice): array
    {
        $host = $this->module->host();
        $settings = $this->module->settings();
        $lang = Text::code($host->userLanguage());
        $text = new Text($lang);

        if (!$settings->active() || $settings->apiUrl() === '' || $settings->apiKey() === '') {
            return ['error' => $text->t('error_unavailable')];
        }
        if ($settings->isTestMode() && $settings->flag('test_admin_only') && !$host->isAdmin()) {
            return ['error' => $text->t('error_unavailable')];
        }

        $repo = $this->module->repo();
        $repo->ensure();
        if (!$repo->lock($pmInvoice)) {
            return ['error' => $text->t('error_busy')];
        }
        try {
            return $this->startLocked($pmInvoice, $lang, $text);
        } catch (ApiError $e) {
            $host->log('error', 'invoice #' . $pmInvoice . ': ' . $e->summary());
            $key = $e->errorCode() === 'CURRENCY_NOT_SUPPORTED' ? 'error_currency' : 'error_api';

            return ['error' => $text->t($key, $this->currency)];
        } catch (RuntimeException $e) {
            $host->log('error', 'invoice #' . $pmInvoice . ': ' . $e->getMessage());

            return ['error' => $text->t('error_busy')];
        } finally {
            $repo->unlock($pmInvoice);
        }
    }

    private function startLocked(int $pmInvoice, string $lang, Text $text): array
    {
        $host = $this->module->host();
        $repo = $this->module->repo();
        $settings = $this->module->settings();
        $back = ['redirect' => $host->invoiceUrl($pmInvoice)];

        // The invoice now, not as the page saw it: paid meanwhile or nothing left — back to it.
        $pm = $host->invoice($pmInvoice);
        if (!$pm || $pm['status'] !== 'pending' || !Util::positive($pm['remaining'])) {
            return $back;
        }

        $currency = $this->currency = strtoupper((string) $pm['currency_code']);
        [$due, $amount] = $this->amounts($pm['remaining'], $currency);
        if (!Util::positive($amount)) {
            return $back;
        }
        $test = $settings->isTestMode();
        $admin = $host->isAdmin();
        $client = $this->module->client();

        $current = $repo->current($pmInvoice);
        // A test invoice of an admin is not handed to a client: its payment would be added.
        if ($current && $current['status'] === 'open' && Util::compare($current['due'], $due, Decision::SCALE) === 0 && $current['currency'] === $currency
            && (bool) $current['test'] === $test && (!$test || (bool) $current['test_admin'] === $admin)
            && (int) $current['pm_user'] === (int) $pm['user_id']) {
            try {
                $invoice = $client->invoice($current['invoice_id']);
                if (Util::value($invoice, 'status') === 'open') {
                    return ['redirect' => self::payPage($invoice, $lang)];
                }
                // Paid or closed meanwhile: its state goes to Paymenter, and a new invoice is made if still due.
                $this->module->sync()->apply($invoice);
                $pm = $host->invoice($pmInvoice);
                if (!$pm || $pm['status'] !== 'pending' || !Util::positive($pm['remaining'])) {
                    return $back;
                }
                [$due, $amount] = $this->amounts($pm['remaining'], $currency);
            } catch (ApiError $e) {
                if (!$e->isNotFound()) {
                    throw $e;
                }
            }
        }

        $attempt = count($repo->forInvoice($pmInvoice)) + 1;
        $body = InvoiceRequest::body(
            [
                'reference' => (string) $pmInvoice,
                'currency' => $currency,
                'amount' => $amount,
                'description' => $text->t('invoice_description', (string) $pm['number']),
                'return_url' => $this->module->returnUrl($pmInvoice),
            ],
            $settings
        );
        $invoice = $client->createInvoice($body, InvoiceRequest::idempotencyKey($host->siteKey(), $pmInvoice, $attempt, $body));
        if (Util::value($invoice, 'status') !== 'open' && !$repo->find(Util::value($invoice, 'id'))) {
            // The key met an invoice made earlier and closed since — the tables were wiped, say. One more attempt.
            $attempt++;
            $invoice = $client->createInvoice($body, InvoiceRequest::idempotencyKey($host->siteKey(), $pmInvoice, $attempt, $body));
            if (Util::value($invoice, 'status') !== 'open' && !$repo->find(Util::value($invoice, 'id'))) {
                // Never adopt an old invoice: its money belongs to another payment.
                throw new ApiError(0, 'INVOICE_NOT_OPEN', 'The idempotency key met closed invoices twice');
            }
        }

        $invoiceId = ApiClient::id(Util::value($invoice, 'id'));
        $page = self::payPage($invoice, $lang);
        if (!$repo->find($invoiceId)) {
            $repo->add([
                'invoice_id' => $invoiceId,
                'pm_invoice' => $pmInvoice,
                'pm_user' => (int) $pm['user_id'],
                'attempt' => $attempt,
                'amount' => $amount,
                'due' => $due,
                'currency' => $currency,
                'test' => !empty($invoice['test']) ? 1 : 0,
                'test_admin' => $admin ? 1 : 0,
                'status' => Util::value($invoice, 'status'),
                'pay_url' => Util::value($invoice, 'pay_url'),
                'snapshot' => Util::json(Sync::snapshot($invoice)),
                'next_poll' => time() + Sync::POLL_OPEN,
                'poll_until' => time() + $settings->lifetime() * 60 + self::POLL_AFTER_CLOSE,
            ]);
            $this->module->debug(sprintf('invoice #%d: CoinsPay invoice %s for %s %s', $pmInvoice, $invoiceId, $amount, $currency));
        }

        $this->cancelReplaced($pmInvoice, $invoiceId);

        return ['redirect' => $page];
    }

    /**
     * What the CoinsPay invoice stands for on the Paymenter invoice (2 decimals, as Paymenter
     * keeps it) and what it asks: the same, in the decimals CoinsPay takes for the currency. For a
     * currency without cents the two differ, and the payment adds the first.
     *
     * @return array {0: due, 1: amount}
     */
    private function amounts(string $remaining, string $currency): array
    {
        $scale = min(Decision::SCALE, $this->module->currencies()->scale($currency));

        return [Util::formatAmount($remaining, Decision::SCALE), Util::formatAmount($remaining, $scale)];
    }

    /**
     * The payment page in the client's language. Only an http(s) address goes to the browser.
     *
     * @throws ApiError BAD_RESPONSE for anything else.
     */
    private static function payPage(array $invoice, string $lang): string
    {
        $url = Util::value($invoice, 'pay_url');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['https', 'http'], true) || (string) parse_url($url, PHP_URL_HOST) === '') {
            throw new ApiError(0, 'BAD_RESPONSE', 'The payment page address is not a web address');
        }

        return self::withLang($url, $lang);
    }

    /**
     * Cancels the open CoinsPay invoices of a Paymenter invoice that hold no money: replaced by a
     * new one, or not wanted any more — the invoice was cancelled, changed or paid otherwise.
     * CoinsPay cancels an open invoice even with a payment on its way, so each one is read fresh
     * first; what still lands in the gap is decided as for any closed invoice.
     *
     * @param  string  $keep  CoinsPay invoice to leave open; '' cancels all.
     */
    public function cancelReplaced(int $pmInvoice, string $keep = ''): void
    {
        foreach ($this->module->repo()->forInvoice($pmInvoice) as $row) {
            if ($row['invoice_id'] !== $keep) {
                $this->cancelRow($row);
            }
        }
    }

    /** Cancels one open CoinsPay invoice if it holds no money, and applies its state. */
    public function cancelRow(array $row): void
    {
        if ($row['status'] !== 'open') {
            return;
        }
        $pmInvoice = (int) $row['pm_invoice'];
        try {
            $invoice = $this->module->client()->invoice($row['invoice_id']);
            $hasMoney = Util::positive(Util::value($invoice, 'amount_paid')) || Util::positive(Util::value($invoice, 'amount_pending'));
            if (Util::value($invoice, 'status') === 'open' && !$hasMoney) {
                $invoice = $this->module->client()->cancelInvoice($row['invoice_id']);
            }
            $this->module->sync()->apply($invoice);
        } catch (ApiError $e) {
            // Money came meanwhile (INVOICE_NOT_OPEN) or the API is away: its real state comes with polling.
            $this->module->host()->log(
                in_array($e->status(), [404, 409], true) ? 'info' : 'error',
                sprintf('invoice #%d: CoinsPay invoice %s not cancelled: %s', $pmInvoice, $row['invoice_id'], $e->summary())
            );
        } catch (RuntimeException $e) {
            // Busy, or a payment Paymenter did not record: the next webhook or poll applies it.
            $this->module->host()->log('error', sprintf('invoice #%d: CoinsPay invoice %s: %s', $pmInvoice, $row['invoice_id'], $e->getMessage()));
        }
    }

    /** The payment page in the client's language. */
    public static function withLang(string $payUrl, string $lang): string
    {
        if ($payUrl === '') {
            return '';
        }

        return $payUrl . (str_contains($payUrl, '?') ? '&' : '?') . 'lang=' . ($lang === 'ru' ? 'ru' : 'en');
    }
}
