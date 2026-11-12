<?php

use Paymenter\Extensions\Gateways\CoinsPay\Lib\Module;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Sync;
use PHPUnit\Framework\TestCase;

/**
 * A payment reaches the Paymenter invoice exactly once and only for what came, whatever
 * recording it does.
 */
final class SyncTest extends TestCase
{
    private const ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    private FakeHost $host;

    private MemoryRepo $repo;

    private FakeClient $client;

    private Module $module;

    protected function setUp(): void
    {
        $this->host = new FakeHost;
        $this->repo = new MemoryRepo;
        $this->client = new FakeClient;
        $this->module = fake_module($this->host, $this->repo, $this->client);
        $this->repo->add([
            'invoice_id' => self::ID, 'pm_invoice' => 42, 'attempt' => 1, 'amount' => '100', 'currency' => 'USD',
            'test' => 0, 'test_admin' => 0, 'status' => 'open', 'pay_url' => '', 'next_poll' => time(), 'poll_until' => time() + 90000,
        ]);
    }

    private function invoice(array $overrides = []): array
    {
        return $overrides + [
            'id' => self::ID, 'number' => 12, 'status' => 'open', 'test' => false, 'currency' => 'USD', 'amount' => '100',
            'amount_paid' => '0', 'amount_pending' => '0', 'amount_due' => '100', 'amount_overpaid' => null,
            'paid_late' => false, 'paid_manually' => false, 'payments' => [],
        ];
    }

    private function paid(array $overrides = []): array
    {
        return $this->invoice($overrides + ['status' => 'paid', 'amount_paid' => '100', 'amount_due' => '0']);
    }

    private function fresh(): Module
    {
        return $this->module = fake_module($this->host, $this->repo, $this->client);
    }

    public function test_a_paid_invoice_adds_its_amount_once()
    {
        $result = $this->module->sync()->apply($this->paid());
        $this->assertStringStartsWith('credit', $result);
        $this->assertSame([self::ID => ['status' => 'succeeded', 'amount' => '100.00']], $this->host->transactions);
        $this->assertSame('paid', $this->host->invoice['status']);
        $this->assertSame('applied', $this->repo->rows[self::ID]['apply_state']);
        $this->assertNull($this->repo->rows[self::ID]['next_poll']);

        $this->assertSame('no change', $this->module->sync()->apply($this->paid()));
        // Another state of the same invoice (a manual mark, say) still adds nothing.
        $this->assertSame('none', $this->module->sync()->apply($this->paid(['paid_manually' => true])));
        $this->assertCount(1, $this->host->records);
    }

    public function test_money_on_its_way_shows_as_processing_then_becomes_the_payment()
    {
        $this->assertStringStartsWith('processing', $this->module->sync()->apply($this->invoice(['amount_pending' => '100'])));
        $this->assertSame(['status' => 'processing', 'amount' => '100.00'], $this->host->transactions[self::ID]);
        $this->assertSame('pending', $this->host->invoice['status']);
        $this->assertNotNull($this->repo->rows[self::ID]['next_poll']);

        $this->module->sync()->apply($this->paid());
        $this->assertSame(['status' => 'succeeded', 'amount' => '100.00'], $this->host->transactions[self::ID]);
        $this->assertCount(1, $this->host->transactions, 'one row per CoinsPay invoice');
        $this->assertSame('paid', $this->host->invoice['status']);
    }

    public function test_a_part_is_credited_when_the_invoice_expires_and_a_late_rest_raises_it()
    {
        $this->module->sync()->apply($this->invoice(['amount_paid' => '40', 'amount_due' => '60']));
        $this->assertSame('processing', $this->host->transactions[self::ID]['status']);

        $this->module->sync()->apply($this->invoice(['status' => 'expired', 'amount_paid' => '40', 'amount_due' => '60']));
        $this->assertSame(['status' => 'succeeded', 'amount' => '40.00'], $this->host->transactions[self::ID]);
        $this->assertSame('60.00', $this->host->remaining());
        $this->assertSame('pending', $this->host->invoice['status']);
        $this->assertGreaterThan(time() + 1000, $this->repo->rows[self::ID]['next_poll'], 'a late payment may still come');

        // The rest came late: the same transaction covers the whole now.
        $this->module->sync()->apply($this->paid(['paid_late' => true]));
        $this->assertSame(['status' => 'succeeded', 'amount' => '100.00'], $this->host->transactions[self::ID]);
        $this->assertSame('paid', $this->host->invoice['status']);
    }

    public function test_a_payment_on_its_way_that_never_came_fails()
    {
        $this->module->sync()->apply($this->invoice(['amount_pending' => '100']));
        $this->module->sync()->apply($this->invoice(['status' => 'expired']));
        $this->assertSame(['status' => 'failed', 'amount' => '100.00'], $this->host->transactions[self::ID]);
    }

    public function test_an_expired_invoice_without_money_records_nothing()
    {
        $this->assertStringStartsWith('log', $this->module->sync()->apply($this->invoice(['status' => 'expired'])));
        $this->assertSame([], $this->host->records);
    }

    public function test_a_failure_before_recording_is_retried()
    {
        $this->host->fail = 'throw';
        try {
            $this->module->sync()->apply($this->paid());
            $this->fail('no error');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('did not record', $e->getMessage());
        }
        // The claim is released: the next webhook or poll tries again at once.
        $this->assertSame('none', $this->repo->rows[self::ID]['apply_state']);
        $this->assertNull($this->repo->rows[self::ID]['fingerprint']);
        $this->assertSame('error', $this->host->log[0][0]);

        $this->assertStringStartsWith('credit', $this->module->sync()->apply($this->paid()));
        $this->assertCount(1, $this->host->transactions);
    }

    public function test_a_failure_after_recording_does_not_add_a_second_payment()
    {
        $this->host->fail = 'throw_after';
        $this->assertStringStartsWith('credit', $this->module->sync()->apply($this->paid()));
        $this->assertFalse(Sync::$applying);
        $this->assertSame('applied', $this->repo->rows[self::ID]['apply_state']);

        $this->assertSame('no change', $this->module->sync()->apply($this->paid()));
        $this->assertCount(1, $this->host->records);
    }

    public function test_a_payment_paymenter_did_not_record_is_an_error_and_is_retried()
    {
        $this->host->fail = 'silent';
        try {
            $this->module->sync()->apply($this->paid());
            $this->fail('no error');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('did not record', $e->getMessage());
        }
        $this->assertStringStartsWith('credit', $this->module->sync()->apply($this->paid()));
        $this->assertSame('succeeded', $this->host->transactions[self::ID]['status']);
    }

    public function test_a_cancelled_invoice_is_left_to_the_admin()
    {
        $this->host->invoice['status'] = 'cancelled';
        $this->assertStringStartsWith('hold', $this->module->sync()->apply($this->paid()));
        $this->assertSame([], $this->host->records);
        $this->assertCount(1, $this->host->logged('warning'));
        $this->assertStringContainsString('#42', $this->host->logged('warning')[0]);
        $this->assertStringContainsString('"cancelled"', $this->repo->rows[self::ID]['attention']);
        // The same state again does not repeat the alarm.
        $this->assertSame('no change', $this->module->sync()->apply($this->paid()));
        $this->assertCount(1, $this->host->logged('warning'));
    }

    public function test_a_test_payment_is_added_only_when_allowed_with_its_own_transaction_id()
    {
        $test = $this->paid(['test' => true]);
        $this->repo->update(self::ID, ['test' => 1, 'test_admin' => 1]);
        $this->assertStringStartsWith('log', $this->module->sync()->apply($test));
        $this->assertSame([], $this->host->records);

        // The admin turns "Add test payments" on; the next request reads the new settings.
        $this->host->settings['apply_test'] = true;
        $this->repo->update(self::ID, ['fingerprint' => null]);
        $this->assertStringStartsWith('credit', $this->fresh()->sync()->apply($test));
        $this->assertSame(['test-' . self::ID], array_keys($this->host->transactions));
    }

    public function test_a_payment_claimed_by_another_request_is_not_added_again()
    {
        // Another request is recording it right now, whatever the named lock did.
        $this->repo->update(self::ID, ['apply_state' => 'applying', 'claimed' => time()]);
        try {
            $this->module->sync()->apply($this->paid());
            $this->fail('no error');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('being added by another request', $e->getMessage());
        }
        $this->assertSame([], $this->host->records);

        // That request died without recording: after CLAIM_STALE the claim is taken over.
        $this->repo->update(self::ID, ['claimed' => time() - Sync::CLAIM_STALE - 1]);
        $this->assertStringStartsWith('credit', $this->module->sync()->apply($this->paid()));
        $this->assertCount(1, $this->host->transactions);
        $this->assertSame('applied', $this->repo->rows[self::ID]['apply_state']);
        $this->assertNull($this->repo->rows[self::ID]['claimed']);
    }

    public function test_an_invoice_of_another_client_now_is_left_to_the_admin()
    {
        $this->repo->update(self::ID, ['pm_user' => 8]);
        $this->assertStringStartsWith('hold', $this->module->sync()->apply($this->paid()));
        $this->assertSame([], $this->host->records);
        $this->assertStringContainsString('another client', $this->repo->rows[self::ID]['attention']);
    }

    public function test_an_overpayment_adds_the_invoice_amount_and_tells_the_admin()
    {
        $this->assertStringStartsWith('credit', $this->module->sync()->apply($this->paid(['amount_paid' => '105', 'amount_overpaid' => '5'])));
        $this->assertSame('100.00', $this->host->transactions[self::ID]['amount']);
        $this->assertStringContainsString('Overpaid by 5 USD', $this->repo->rows[self::ID]['attention']);
    }

    public function test_money_for_a_deleted_invoice_is_reported_once()
    {
        $this->repo->update(self::ID, ['pm_invoice' => 43]);
        $this->assertSame('the Paymenter invoice is gone', $this->module->sync()->apply($this->paid()));
        $this->assertSame('the Paymenter invoice is gone', $this->module->sync()->apply($this->paid()));
        $this->assertSame([], $this->host->records);
        $this->assertCount(1, $this->host->logged('warning'));
        $this->assertStringContainsString('#43 is gone', $this->repo->rows[self::ID]['attention']);
        $this->assertNull($this->repo->rows[self::ID]['next_poll']);
    }

    public function test_a_paid_invoice_cancels_the_other_open_coinspay_invoices_without_money()
    {
        $other = $this->client->createInvoice(['currency' => 'USD', 'amount' => '100'], 'k');
        $this->repo->add([
            'invoice_id' => $other['id'], 'pm_invoice' => 42, 'attempt' => 2, 'amount' => '100', 'currency' => 'USD',
            'test' => 0, 'test_admin' => 0, 'status' => 'open', 'pay_url' => '', 'next_poll' => time(), 'poll_until' => time() + 90000,
        ]);
        $this->module->sync()->apply($this->paid());
        $this->assertSame([$other['id']], $this->client->cancelled);
        $this->assertSame('cancelled', $this->repo->rows[$other['id']]['status']);
    }

    public function test_an_invoice_cancelled_between_the_decision_and_the_record_is_not_credited()
    {
        // The second read is the one inside the claim, right before recording.
        $this->host->onRead = function (int $read, FakeHost $host): void {
            if ($read === 2) {
                $host->invoice['status'] = 'cancelled';
            }
        };
        $this->assertStringStartsWith('hold', $this->module->sync()->apply($this->paid()));
        $this->assertSame([], $this->host->records);
        $this->assertStringContainsString('"cancelled"', $this->repo->rows[self::ID]['attention']);
        $this->assertSame('none', $this->repo->rows[self::ID]['apply_state']);
        $this->assertNull($this->repo->rows[self::ID]['claimed']);
    }

    public function test_a_currency_without_cents_pays_the_whole_invoice()
    {
        $this->host->invoice = ['total' => '1078.32', 'currency_code' => 'JPY'] + $this->host->invoice;
        $this->repo->update(self::ID, ['amount' => '1078', 'due' => '1078.32', 'currency' => 'JPY']);
        $this->module->sync()->apply($this->paid(['currency' => 'JPY', 'amount' => '1078', 'amount_paid' => '1078']));
        $this->assertSame('1078.32', $this->host->transactions[self::ID]['amount']);
        $this->assertSame('paid', $this->host->invoice['status']);
    }

    public function test_a_changed_currency_is_left_to_the_admin()
    {
        $this->host->invoice['currency_code'] = 'EUR';
        $this->assertStringStartsWith('hold', $this->module->sync()->apply($this->paid()));
        $this->assertSame([], $this->host->records);
    }

    public function test_a_lowered_invoice_gets_only_what_it_owes()
    {
        $this->host->invoice['total'] = '80.00';
        $this->module->sync()->apply($this->paid());
        $this->assertSame('80.00', $this->host->transactions[self::ID]['amount']);
        $this->assertSame('paid', $this->host->invoice['status']);
        $this->assertStringContainsString('20 USD more stays in CoinsPay', $this->repo->rows[self::ID]['attention']);
    }

    public function test_an_unknown_invoice_is_not_touched()
    {
        $this->assertSame('not an invoice of this Paymenter', $this->module->sync()->apply($this->paid(['id' => '00000000-0000-4000-8000-000000000999'])));
    }
}
