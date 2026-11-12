<?php

namespace Paymenter\Extensions\Gateways\CoinsPay\Lib;

use RuntimeException;

/**
 * Polling of CoinsPay invoices when a webhook did not come: every minute from the scheduler of
 * Paymenter, and for one invoice when its page is opened. From the WHMCS module (Poller.php).
 */
final class Poller
{
    public const BATCH = 25;

    public const BUDGET = 20;

    /** Seconds to the next poll of an invoice the API failed on. */
    public const BACKOFF = 300;

    public function __construct(private readonly Module $module) {}

    /**
     * Checks the invoices that are due, within a limit of count and time.
     *
     * @return int How many were checked.
     */
    public function run(): int
    {
        $started = time();
        $failures = 0;
        $checked = 0;
        foreach ($this->module->repo()->due(self::BATCH) as $row) {
            if (time() - $started >= self::BUDGET || $failures >= 3) {
                break;
            }
            if ($this->check($row)) {
                $checked++;
            } else {
                $failures++;
            }
        }

        return $checked;
    }

    /** The newest CoinsPay invoice of a Paymenter invoice, at most once a minute: for the invoice page. */
    public function checkInvoice(int $pmInvoice): void
    {
        $row = $this->module->repo()->current($pmInvoice);
        // Done once it has no next poll: paid, or past its polling time. One whose payment failed
        // halfway keeps its next poll.
        if ($row && $row['next_poll'] !== null && $this->module->repo()->takeTurn('view_' . $pmInvoice, 60)) {
            $this->check($row);
        }
    }

    /** @return bool False when the API failed. */
    private function check(array $row): bool
    {
        // Made with a key of the other mode: the admin switched keys, and this one does not know
        // the invoice. Asked again later, when the key of its mode may be back.
        if ((bool) $row['test'] !== $this->module->settings()->isTestMode()) {
            $this->postpone($row);

            return true;
        }
        try {
            if ($row['status'] === 'open' && $this->unwanted($row)) {
                // Cancelled or paid otherwise, or its amount changed: Paymenter does that in bulk
                // without events, so polling finds it. Only this one: a newer invoice of the
                // client may be the one for the amount due now. Cancelling also applies its state.
                $this->module->payment()->cancelRow($row);

                return true;
            }
            $this->module->sync()->apply($this->module->client()->invoice($row['invoice_id']));

            return true;
        } catch (ApiError $e) {
            $this->module->host()->log('error', 'polling ' . $row['invoice_id'] . ': ' . $e->summary());
            if ($e->isNotFound()) {
                // Unknown to this key: a key of another shop now. Not dropped: the old key may come back.
                $this->postpone($row);

                return true;
            }
            // A few minutes later, so an invoice that keeps failing does not hold up the others.
            $this->postpone($row, self::BACKOFF);

            return false;
        } catch (RuntimeException $e) {
            // A busy invoice or a payment Paymenter did not record: the next run tries again.
            $this->module->host()->log('error', 'polling ' . $row['invoice_id'] . ': ' . $e->getMessage());

            return true;
        }
    }

    /** Whether an open CoinsPay invoice no longer stands for what the Paymenter invoice wants. */
    private function unwanted(array $row): bool
    {
        $pm = $this->module->host()->invoice((int) $row['pm_invoice']);
        if (!$pm) {
            return false;
        }
        if ($pm['status'] !== 'pending' || strtoupper((string) $pm['currency_code']) !== $row['currency']) {
            return true;
        }

        return Util::compare((string) $pm['remaining'], (string) $row['due'], Decision::SCALE) !== 0;
    }

    /** The next poll later (30 minutes by default), within the polling time of the invoice. */
    private function postpone(array $row, int $seconds = Sync::POLL_CLOSED): void
    {
        $next = time() + $seconds;
        $this->module->repo()->update($row['invoice_id'], ['next_poll' => $next < (int) $row['poll_until'] ? $next : null]);
    }
}
