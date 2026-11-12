<?php

namespace Paymenter\Extensions\Gateways\CoinsPay\Lib;

/**
 * What a state of a CoinsPay invoice means for its Paymenter invoice. A pure function: unit tests
 * check every rule without Paymenter.
 *
 * Each CoinsPay invoice has one transaction on the Paymenter invoice, with the CoinsPay id as its
 * transaction id. Its amount is what the CoinsPay invoice brought:
 * - paid: the whole amount it was made for;
 * - expired or cancelled with confirmed money: the part received — Paymenter keeps the rest due,
 *   and the client pays it with a new CoinsPay invoice;
 * - open with money: a "processing" transaction, so the client sees the payment on its way.
 * The amount only grows: a late payment of an expired invoice raises it to the whole.
 */
final class Decision
{
    /** Record a succeeded transaction of the amount. */
    public const CREDIT = 'credit';

    /** Record a processing transaction: money on its way, not counted yet. */
    public const PROCESSING = 'processing';

    /** The processing transaction failed: the money never came. */
    public const FAILED = 'failed';

    /** Money came, but it needs a decision of the admin. */
    public const HOLD = 'hold';

    /** Only a line of the log. */
    public const LOG = 'log';

    public const NONE = 'none';

    /** Paymenter keeps amounts with 2 decimals. */
    public const SCALE = 2;

    /**
     * @param  array  $invoice  CoinsPay invoice from the API.
     * @param  array  $state  The Paymenter invoice now and what the module knows:
     *                        status, remaining, currency_changed, user_changed — of the invoice;
     *                        requested — what the CoinsPay invoice stands for on it;
     *                        credited — the succeeded amount already recorded, or '';
     *                        recorded — the status of the transaction, or '';
     *                        test_admin, apply_test.
     * @param  Text  $text  Language of the admin.
     * @return array {action, amount, note, attention}: attention — the note goes to the admin.
     */
    public static function decide(array $invoice, array $state, Text $text): array
    {
        $status = Util::value($invoice, 'status');
        $number = Util::value($invoice, 'number');
        $currency = Util::value($invoice, 'currency');
        $paid = Util::value($invoice, 'amount_paid');
        $pending = Util::value($invoice, 'amount_pending');
        $total = Util::value($invoice, 'amount');
        $requested = (string) $state['requested'];
        $credited = (string) ($state['credited'] ?? '');
        $credited = $credited === '' ? '0' : $credited;
        $recorded = (string) ($state['recorded'] ?? '');
        $pmStatus = (string) $state['status'];
        $test = !empty($invoice['test']);
        $countsTest = !$test || (!empty($state['test_admin']) && !empty($state['apply_test']));
        $hasMoney = Util::positive($paid) || Util::positive($pending);
        $closed = $status === 'expired' || $status === 'cancelled';

        $target = match (true) {
            $status === 'paid' => Util::formatAmount($requested, self::SCALE),
            $closed => Util::share($paid, $requested, $total, self::SCALE),
            default => '0',
        };
        $grows = Util::positive($target) && Util::compare($target, $credited, self::SCALE) > 0;

        if ($test && $grows) {
            if (empty($state['test_admin'])) {
                return self::result(self::HOLD, '', $text->t('log_test_foreign', $number), true);
            }
            if (empty($state['apply_test'])) {
                return self::result(self::LOG, '', $text->t('log_test_not_applied', $number));
            }
        }

        if ($grows) {
            $amount = $target . ' ' . $currency;
            if (!empty($state['user_changed'])) {
                return self::result(self::HOLD, '', $text->t('log_client_changed', $number, $amount), true);
            }
            if (!empty($state['currency_changed'])) {
                return self::result(self::HOLD, '', $text->t('log_currency_changed', $number, $amount), true);
            }
            if ($pmStatus !== 'pending') {
                return self::result(self::HOLD, '', $text->t('log_not_pending', $number, $amount, $pmStatus), true);
            }
            if ($status === 'paid') {
                $note = $text->t('log_paid', $number, $amount, self::payments($invoice, $text));
                if (!empty($invoice['paid_manually'])) {
                    $note .= ' ' . $text->t('log_paid_manually');
                }
                if (!empty($invoice['paid_late'])) {
                    $note .= ' ' . $text->t('log_paid_late');
                }
            } else {
                $note = $text->t($status === 'expired' ? 'log_expired_credited' : 'log_cancelled_credited', $number, $paid . ' ' . $currency, $total . ' ' . $currency, $amount);
            }
            $attention = false;
            if (Util::positive(Util::value($invoice, 'amount_overpaid'))) {
                $note .= ' ' . $text->t('log_overpaid', Util::value($invoice, 'amount_overpaid') . ' ' . $currency);
                $attention = true;
            }
            // Never past what the invoice still owes: its amount may have dropped, or another
            // payment covered a part meanwhile. The rest stays in CoinsPay for the admin.
            $room = Util::add((string) ($state['remaining'] ?? $target), $credited, self::SCALE);
            if (Util::compare($target, $room, self::SCALE) > 0) {
                if (Util::compare($room, $credited, self::SCALE) <= 0) {
                    return self::result(self::HOLD, '', $text->t('log_no_room', $number, $amount), true);
                }
                $note .= ' ' . $text->t('log_capped', Util::formatAmount((float) $target - (float) $room, self::SCALE) . ' ' . $currency);
                $target = Util::formatAmount($room, self::SCALE);
                $attention = true;
            }

            return self::result(self::CREDIT, $target, $note, $attention);
        }

        if ($status === 'open' && $hasMoney) {
            $seen = Util::share(Util::add($paid === '' ? '0' : $paid, $pending === '' ? '0' : $pending), $requested, $total, self::SCALE);
            $note = Util::positive($paid)
                ? $text->t('log_partial', $number, $paid . ' ' . $currency, Util::value($invoice, 'amount_due') . ' ' . $currency)
                : $text->t('log_pending', $number, self::payments($invoice, $text));
            // Shown to the client only while nothing is counted and the invoice still waits;
            // a test payment only when it would be counted.
            if (!Util::positive($credited) && $pmStatus === 'pending' && Util::positive($seen) && $countsTest) {
                return self::result(self::PROCESSING, $seen, $note);
            }

            return self::result(self::LOG, '', $note);
        }

        if ($closed) {
            if (Util::positive($pending)) {
                // Money still on its way: counted when confirmed, polling goes on.
                return self::result(self::LOG, '', $text->t('log_closed_pending', $number, self::payments($invoice, $text)));
            }
            if (Util::positive($paid)) {
                // Its part is credited already, or held for the admin.
                return self::result(self::NONE, '', '');
            }
            if ($recorded === 'processing') {
                return self::result(self::FAILED, '', $text->t('log_failed', $number));
            }

            return self::result(self::LOG, '', $text->t($status === 'expired' ? 'log_expired' : 'log_cancelled', $number));
        }

        return self::result(self::NONE, '', '');
    }

    /** What of the invoice changes the decision: equal for repeated webhooks of one state. */
    public static function fingerprint(array $invoice): string
    {
        $parts = [];
        foreach (['status', 'amount_paid', 'amount_pending', 'paid_late', 'paid_manually'] as $field) {
            $parts[] = isset($invoice[$field]) ? Util::json($invoice[$field]) : 'null';
        }
        if (!empty($invoice['payments']) && is_array($invoice['payments'])) {
            foreach ($invoice['payments'] as $payment) {
                $parts[] = Util::value($payment, 'deposit_id') . ':' . Util::value($payment, 'status');
            }
        }

        return md5(implode('|', $parts));
    }

    /** Payments in one line: "49.9 USDT (tron, abc…123)". */
    private static function payments(array $invoice, Text $text): string
    {
        $lines = [];
        if (!empty($invoice['payments']) && is_array($invoice['payments'])) {
            foreach ($invoice['payments'] as $payment) {
                $hash = Util::value($payment, 'tx_hash');
                $lines[] = sprintf(
                    '%s %s (%s, %s)',
                    Util::value($payment, 'amount'),
                    Util::value($payment, 'asset'),
                    Util::value($payment, 'chain'),
                    strlen($hash) > 16 ? substr($hash, 0, 8) . "\u{2026}" . substr($hash, -6) : $hash
                );
            }
        }

        return $lines ? implode(', ', $lines) : $text->t('log_no_payments');
    }

    private static function result(string $action, string $amount, string $note, bool $attention = false): array
    {
        return ['action' => $action, 'amount' => $amount, 'note' => $note, 'attention' => $attention];
    }
}
