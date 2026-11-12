<?php

namespace Paymenter\Extensions\Gateways\CoinsPay\Lib;

use RuntimeException;
use Throwable;

/**
 * Applies the state of a CoinsPay invoice to its Paymenter invoice: lock → fresh invoice →
 * decision → transaction or log. From the WHMCS module (Sync.php).
 *
 * Paymenter keeps one transaction per transaction id, but before 1.5.7 without a lock and
 * without a unique index: two requests at once could add two rows. The named lock and the claim
 * of the row keep that from happening.
 */
final class Sync
{
    /** Open invoices are polled every 2 minutes, closed ones every 30 until the end of polling. */
    public const POLL_OPEN = 120;

    public const POLL_CLOSED = 1800;

    /** A claim older than this belongs to a request that died: see Repo::claim(). */
    public const CLAIM_STALE = 600;

    /** A transaction of the module is being recorded now: its own invoice listener keeps out. */
    public static bool $applying = false;

    public function __construct(private readonly Module $module) {}

    /**
     * @param  array  $invoice  CoinsPay invoice from the API.
     * @return string What happened, for the log and the webhook answer.
     *
     * @throws RuntimeException The Paymenter invoice is busy, or the transaction did not get
     *                          recorded; the caller retries later.
     */
    public function apply(array $invoice): string
    {
        $repo = $this->module->repo();
        $row = $repo->find(Util::value($invoice, 'id'));
        if (!$row) {
            return 'not an invoice of this Paymenter';
        }
        $pmInvoice = (int) $row['pm_invoice'];
        if (!$repo->lock($pmInvoice)) {
            throw new RuntimeException('the invoice is busy');
        }
        try {
            $result = $this->applyLocked($row['invoice_id'], $invoice);
        } finally {
            $repo->unlock($pmInvoice);
        }
        if ($result[1]) {
            // The invoice got paid: other open CoinsPay invoices of it are not wanted any more.
            $this->module->payment()->cancelReplaced($pmInvoice, $row['invoice_id']);
        }

        return $result[0];
    }

    /** @return array {0: what happened, 1: whether the Paymenter invoice got paid by it} */
    private function applyLocked(string $invoiceId, array $invoice): array
    {
        $host = $this->module->host();
        $repo = $this->module->repo();
        // Fresh copies: another request may have changed both while we waited for the lock.
        $row = $repo->find($invoiceId);
        if (!$row) {
            return ['not an invoice of this Paymenter', false];
        }

        $status = Util::value($invoice, 'status');
        $repo->update($invoiceId, ['status' => $status, 'snapshot' => Util::json(self::snapshot($invoice))]);
        $fingerprint = Decision::fingerprint($invoice);
        $pm = $host->invoice((int) $row['pm_invoice']);
        if (!$pm) {
            return [$this->gone($row, $invoice, $status, $fingerprint), false];
        }
        // A transaction interrupted halfway is never "no change": it is checked again below.
        if ($row['fingerprint'] === $fingerprint && $row['apply_state'] !== 'applying') {
            $this->settle($invoiceId, $status, $row);

            return ['no change', false];
        }

        $transactionId = self::transactionId($invoiceId, !empty($invoice['test']));
        $transaction = $host->transaction((int) $pm['id'], $transactionId);
        $credited = $transaction && $transaction['status'] === 'succeeded' ? (string) $transaction['amount'] : '';
        $decision = Decision::decide(
            $invoice,
            [
                'status' => $pm['status'],
                'remaining' => $pm['remaining'],
                'requested' => $row['due'],
                'credited' => $credited,
                'recorded' => $transaction['status'] ?? '',
                // The invoice was deleted and its id given to an invoice of another client.
                'user_changed' => (int) $pm['user_id'] !== (int) $row['pm_user'],
                'currency_changed' => strtoupper((string) $pm['currency_code']) !== strtoupper((string) $row['currency']),
                'test_admin' => !empty($row['test_admin']),
                'apply_test' => $this->module->settings()->flag('apply_test'),
            ],
            $this->module->adminText()
        );
        $action = $decision['action'];
        $note = $decision['note'];
        $paidNow = false;
        $context = self::logContext($invoice, $row);

        if ($action === Decision::CREDIT) {
            $hold = $this->credit($row, (int) $pm['id'], $transactionId, $decision['amount']);
            if ($hold !== null) {
                // Cancelled or paid meanwhile, between the decision and the record.
                [$action, $note] = [Decision::HOLD, $this->module->adminText()->t('log_not_pending', Util::value($invoice, 'number'), $decision['amount'] . ' ' . Util::value($invoice, 'currency'), $hold)];
                $decision['attention'] = true;
            } else {
                $after = $host->invoice((int) $pm['id']);
                $paidNow = $after !== null && $after['status'] === 'paid';
            }
        } elseif ($action === Decision::PROCESSING) {
            $this->guarded(fn () => $host->record((int) $pm['id'], $transactionId, $decision['amount'], 'processing'));
        } elseif ($action === Decision::FAILED) {
            $this->guarded(fn () => $host->record((int) $pm['id'], $transactionId, (string) ($transaction['amount'] ?? '0'), 'failed'));
        }

        // Before the log: a log the web server cannot write must not lose the note for the admin.
        if (!empty($decision['attention'])) {
            $repo->update($invoiceId, ['attention' => $note]);
        }
        if ($note !== '') {
            $host->log($action === Decision::HOLD ? 'warning' : 'info', sprintf('#%d: %s', $pm['id'], $note), $context);
        }

        // Only after the action went through: had it failed, the next webhook or poll must decide
        // again, not see "no change" and lose the payment.
        $repo->update($invoiceId, ['fingerprint' => $fingerprint]);
        $this->settle($invoiceId, $status, $row);
        $this->module->debug(sprintf('invoice #%d, CoinsPay %s (%s): %s', $pm['id'], $invoiceId, $status, $action));

        return [$action . ($note !== '' ? ': ' . $note : ''), $paidNow];
    }

    /** Transaction id of a CoinsPay invoice on the Paymenter invoice. */
    public static function transactionId(string $invoiceId, bool $test): string
    {
        return ($test ? 'test-' : '') . strtolower($invoiceId);
    }

    /**
     * A succeeded transaction of $amount, exactly once: Paymenter runs its listeners, emails and
     * provisioning inside and they may fail; the transaction itself tells whether it got recorded.
     *
     * @return string|null The status of the invoice when it no longer waits for a payment: then
     *                     nothing is recorded.
     */
    private function credit(array $row, int $pmInvoice, string $transactionId, string $amount): ?string
    {
        $host = $this->module->host();
        $repo = $this->module->repo();
        if (!$repo->claim($row['invoice_id'], self::CLAIM_STALE)) {
            throw new RuntimeException('the payment of invoice #' . $pmInvoice . ' is being added by another request');
        }
        $recorded = false;
        try {
            // The request that held the claim before may have recorded it and died.
            if (!$this->recorded($pmInvoice, $transactionId, $amount)) {
                // The admin or the cron of Paymenter may have cancelled the invoice since the
                // decision: Paymenter would mark a cancelled invoice paid.
                $now = $host->invoice($pmInvoice);
                if ($now === null || $now['status'] !== 'pending') {
                    $recorded = true;

                    return (string) ($now['status'] ?? 'deleted');
                }
                $this->guarded(fn () => $host->record($pmInvoice, $transactionId, $amount, 'succeeded'));
            }
            $recorded = $this->recorded($pmInvoice, $transactionId, $amount);
        } finally {
            $done = $recorded && $this->recorded($pmInvoice, $transactionId, $amount);
            $repo->update($row['invoice_id'], ['apply_state' => $done ? 'applied' : 'none', 'claimed' => null]);
        }
        if (!$recorded) {
            throw new RuntimeException('Paymenter did not record the payment of invoice #' . $pmInvoice);
        }

        return null;
    }

    private function recorded(int $pmInvoice, string $transactionId, string $amount): bool
    {
        $transaction = $this->module->host()->transaction($pmInvoice, $transactionId);

        return $transaction !== null && $transaction['status'] === 'succeeded'
            && Util::compare((string) $transaction['amount'], $amount, 2) >= 0;
    }

    /** Runs a write to Paymenter with the invoice listener of the module kept out. */
    private function guarded(callable $write): void
    {
        $was = self::$applying;
        self::$applying = true;
        try {
            $write();
        } catch (Throwable $e) {
            // A listener of Paymenter or of another extension failed after the row was saved:
            // whether it got recorded is checked by the caller.
            $this->module->host()->log('error', 'recording a transaction: ' . $e->getMessage());
            if (!$e instanceof \Exception) {
                throw $e;
            }
        } finally {
            self::$applying = $was;
        }
    }

    /**
     * The Paymenter invoice was deleted. Money on its CoinsPay invoice is not added anywhere: the
     * admin is told once per state.
     */
    private function gone(array $row, array $invoice, string $status, string $fingerprint): string
    {
        $paid = Util::value($invoice, 'amount_paid');
        $pending = Util::value($invoice, 'amount_pending');
        if ((Util::positive($paid) || Util::positive($pending)) && $row['fingerprint'] !== $fingerprint) {
            $received = Util::add($paid === '' ? '0' : $paid, $pending === '' ? '0' : $pending) . ' ' . Util::value($invoice, 'currency');
            $note = $this->module->adminText()->t('log_invoice_gone', Util::value($invoice, 'number'), $received, (int) $row['pm_invoice']);
            $this->module->repo()->update($row['invoice_id'], ['attention' => $note, 'fingerprint' => $fingerprint]);
            $this->module->host()->log('warning', sprintf('#%d: %s', $row['pm_invoice'], $note), self::logContext($invoice, $row));
        }
        $this->settle($row['invoice_id'], $status, $row);

        return 'the Paymenter invoice is gone';
    }

    /**
     * Polling of the invoice after this state: open — every 2 minutes, paid — never, expired or
     * cancelled — every 30 minutes until the end of polling: a late payment may come.
     */
    private function settle(string $invoiceId, string $status, array $row): void
    {
        $next = match ($status) {
            'open' => time() + self::POLL_OPEN,
            'paid' => null,
            default => time() + self::POLL_CLOSED,
        };
        if ($next !== null && $next >= (int) $row['poll_until']) {
            $next = null;
        }
        $this->module->repo()->update($invoiceId, ['next_poll' => $next]);
    }

    /** What the module keeps of an invoice: for the status block and the next decision. */
    public static function snapshot(array $invoice): array
    {
        $copy = [];
        foreach (['id', 'number', 'status', 'test', 'currency', 'amount', 'amount_paid', 'amount_pending', 'amount_due', 'amount_overpaid', 'pay_url', 'expires_at', 'paid_late', 'paid_manually'] as $field) {
            $copy[$field] = $invoice[$field] ?? null;
        }

        return $copy;
    }

    private static function logContext(array $invoice, array $row): array
    {
        return [
            'coinspay_invoice' => Util::value($invoice, 'id'),
            'number' => Util::value($invoice, 'number'),
            'status' => Util::value($invoice, 'status'),
            'test' => !empty($invoice['test']),
            'amount' => Util::value($invoice, 'amount') . ' ' . Util::value($invoice, 'currency'),
            'paid' => Util::value($invoice, 'amount_paid'),
            'pending' => Util::value($invoice, 'amount_pending'),
            'requested' => (string) $row['due'],
            'sent' => (string) $row['amount'],
        ];
    }
}
