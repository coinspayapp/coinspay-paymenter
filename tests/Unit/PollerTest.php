<?php

use Paymenter\Extensions\Gateways\CoinsPay\Lib\Module;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Poller;
use PHPUnit\Framework\TestCase;

/**
 * Polling brings what the webhook did not, and notices what Paymenter changes without events.
 */
final class PollerTest extends TestCase
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
        $this->client->invoices[self::ID] = [
            'id' => self::ID, 'number' => 12, 'status' => 'paid', 'test' => false, 'currency' => 'USD', 'amount' => '100',
            'amount_paid' => '100', 'amount_pending' => '0', 'amount_due' => '0', 'amount_overpaid' => null,
            'paid_late' => false, 'paid_manually' => false, 'payments' => [],
        ];
    }

    private function row(array $columns = []): void
    {
        $this->repo->add($columns + [
            'invoice_id' => self::ID, 'pm_invoice' => 42, 'attempt' => 1, 'amount' => '100', 'currency' => 'USD',
            'test' => 0, 'test_admin' => 0, 'status' => 'open', 'pay_url' => '', 'next_poll' => time() - 1, 'poll_until' => time() + 90000,
        ]);
    }

    public function test_a_paid_invoice_the_webhook_missed_comes_with_polling()
    {
        $this->row();
        $this->assertSame(1, (new Poller($this->module))->run());
        $this->assertSame('paid', $this->host->invoice['status']);
        $this->assertNull($this->repo->rows[self::ID]['next_poll']);
    }

    public function test_a_paid_invoice_whose_payment_failed_is_checked_again()
    {
        $this->row(['status' => 'paid', 'apply_state' => 'applying']);
        (new Poller($this->module))->checkInvoice(42);
        $this->assertSame(1, $this->client->reads);
        $this->assertSame('succeeded', $this->host->transactions[self::ID]['status']);
    }

    public function test_a_settled_paid_invoice_is_not_checked()
    {
        $this->row(['status' => 'paid', 'apply_state' => 'applied', 'next_poll' => null]);
        (new Poller($this->module))->checkInvoice(42);
        $this->assertSame(0, $this->client->reads);
    }

    public function test_an_invoice_the_key_cannot_see_stays_in_polling()
    {
        // Made with the test key; the live key is in now. Not asked, not dropped.
        $this->row(['test' => 1]);
        $poller = new Poller($this->module);
        $this->assertSame(1, $poller->run());
        $this->assertSame(0, $this->client->reads);
        $this->assertGreaterThan(time() + 1000, $this->repo->rows[self::ID]['next_poll']);

        // Of this mode, but unknown to the key (another shop now): asked later again.
        $this->repo->update(self::ID, ['test' => 0, 'next_poll' => time() - 10]);
        unset($this->client->invoices[self::ID]);
        $poller->run();
        $this->assertSame(1, $this->client->reads);
        $this->assertGreaterThan(time() + 1000, $this->repo->rows[self::ID]['next_poll']);
    }

    public function test_an_open_invoice_is_checked_at_most_once_a_minute()
    {
        $this->row();
        $this->client->invoices[self::ID]['status'] = 'open';
        $poller = new Poller($this->module);
        $poller->checkInvoice(42);
        $poller->checkInvoice(42);
        $this->assertSame(1, $this->client->reads);
    }

    public function test_an_old_invoice_does_not_take_the_new_one_with_it()
    {
        // A (100) has money on its way; the client paid 40 otherwise and pressed "Pay": B (60).
        $this->row(['amount' => '100']);
        $this->client->invoices[self::ID] = ['status' => 'open', 'amount_paid' => '0', 'amount_pending' => '30', 'amount_due' => '70'] + $this->client->invoices[self::ID];
        $b = $this->client->createInvoice(['currency' => 'USD', 'amount' => '60'], 'k');
        $this->repo->add([
            'invoice_id' => $b['id'], 'pm_invoice' => 42, 'attempt' => 2, 'amount' => '60', 'currency' => 'USD',
            'test' => 0, 'test_admin' => 0, 'status' => 'open', 'pay_url' => '', 'next_poll' => time() + 100, 'poll_until' => time() + 90000,
        ]);
        $this->host->transactions['other'] = ['status' => 'succeeded', 'amount' => '40.00'];
        (new Poller($this->module))->run();
        $this->assertSame([], $this->client->cancelled, 'A holds money, B is the one due now');
        $this->assertSame('open', $this->repo->rows[$b['id']]['status']);
    }

    public function test_an_invoice_the_api_keeps_failing_on_waits_its_turn()
    {
        $this->row();
        $this->client->errors['invoice'] = new Paymenter\Extensions\Gateways\CoinsPay\Lib\ApiError(500, 'INTERNAL', 'boom');
        (new Poller($this->module))->run();
        $next = $this->repo->rows[self::ID]['next_poll'];
        $this->assertGreaterThan(time() + 200, $next);
        $this->assertLessThan(time() + 400, $next);
    }

    public function test_an_invoice_cancelled_in_bulk_cancels_its_open_coinspay_invoice()
    {
        // The cron of Paymenter cancels overdue invoices with one query: no events.
        $this->row();
        $this->client->invoices[self::ID] = ['status' => 'open', 'amount_paid' => '0', 'amount_due' => '100'] + $this->client->invoices[self::ID];
        $this->host->invoice['status'] = 'cancelled';
        (new Poller($this->module))->run();
        $this->assertSame([self::ID], $this->client->cancelled);
        $this->assertSame('cancelled', $this->repo->rows[self::ID]['status']);
    }

    public function test_a_changed_amount_cancels_the_open_coinspay_invoice()
    {
        $this->row();
        $this->client->invoices[self::ID] = ['status' => 'open', 'amount_paid' => '0', 'amount_due' => '100'] + $this->client->invoices[self::ID];
        $this->host->invoice['total'] = '80.00';
        (new Poller($this->module))->run();
        $this->assertSame([self::ID], $this->client->cancelled);
    }
}
