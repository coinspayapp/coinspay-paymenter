<?php

use Paymenter\Extensions\Gateways\CoinsPay\Lib\Payment;
use PHPUnit\Framework\TestCase;

/**
 * "Pay": what is due now, reuse and replacement of CoinsPay invoices, the test key.
 */
final class PaymentTest extends TestCase
{
    private const FIRST = '00000000-0000-4000-8000-000000000001';

    private const SECOND = '00000000-0000-4000-8000-000000000002';

    private FakeHost $host;

    private MemoryRepo $repo;

    private FakeClient $client;

    protected function setUp(): void
    {
        $this->host = new FakeHost;
        $this->repo = new MemoryRepo;
        $this->client = new FakeClient;
    }

    private function payment(): Payment
    {
        return new Payment(fake_module($this->host, $this->repo, $this->client));
    }

    public function test_pay_takes_the_client_to_the_payment_page()
    {
        $result = $this->payment()->start(42);
        $this->assertSame(['redirect' => 'https://pay.example/pay/' . self::FIRST . '?lang=en'], $result);
        [$body, $key] = $this->client->created[0];
        $this->assertSame('USD', $body['currency']);
        $this->assertSame('100', $body['amount']);
        $this->assertSame('42', $body['reference']);
        $this->assertSame('Invoice #INV-42', $body['description']);
        $this->assertSame(60, $body['lifetime_minutes']);
        $this->assertTrue($body['auto_return']);
        $this->assertStringStartsWith('https://billing.example/extensions/gateways/coinspay/return?invoice=42&cp_sig=', $body['return_url']);
        $this->assertArrayNotHasKey('title', $body);
        $this->assertMatchesRegularExpression('/^paymenter-[0-9a-f]{8}-42-1-[0-9a-f]{12}$/', $key);

        $row = $this->repo->rows[self::FIRST];
        $this->assertSame('100', $row['amount']);
        $this->assertSame('USD', $row['currency']);
        $this->assertSame(7, $row['pm_user']);
    }

    public function test_the_payment_page_speaks_the_language_of_the_client()
    {
        $this->host->locale = 'ru';
        $this->assertSame('https://pay.example/pay/' . self::FIRST . '?lang=ru', $this->payment()->start(42)['redirect']);
        $this->assertSame('Счёт #INV-42', $this->client->created[0][0]['description']);
    }

    public function test_a_repeated_pay_reuses_the_open_invoice()
    {
        $this->payment()->start(42);
        $this->payment()->start(42);
        $this->assertCount(1, $this->client->created);
    }

    public function test_what_is_left_after_a_part_is_asked_with_a_new_invoice()
    {
        $this->host->transactions['credit'] = ['status' => 'succeeded', 'amount' => '40.00'];
        $this->payment()->start(42);
        $this->assertSame('60', $this->client->created[0][0]['amount']);
    }

    public function test_an_invoice_that_does_not_wait_goes_back_to_its_page()
    {
        $this->host->invoice['status'] = 'paid';
        $this->assertSame(['redirect' => 'https://billing.example/invoices/42'], $this->payment()->start(42));
        $this->host->invoice['status'] = 'cancelled';
        $this->assertSame(['redirect' => 'https://billing.example/invoices/42'], $this->payment()->start(42));
        $this->assertSame(['redirect' => 'https://billing.example/invoices/43'], $this->payment()->start(43));
        $this->assertCount(0, $this->client->created);
    }

    public function test_a_test_key_works_for_an_admin_only()
    {
        $this->host->settings['api_key'] = 'cpk_test_key';
        $this->client->test = true;
        $this->assertArrayHasKey('error', $this->payment()->start(42));
        $this->host->admin = true;
        $this->assertArrayHasKey('redirect', $this->payment()->start(42));
        $this->assertSame(1, $this->repo->rows[self::FIRST]['test_admin']);
        $this->assertSame(1, $this->repo->rows[self::FIRST]['test']);
    }

    public function test_a_new_sum_replaces_the_open_invoice_and_cancels_it()
    {
        $this->payment()->start(42);
        $this->host->invoice['total'] = '120.00';
        $this->payment()->start(42);
        $this->assertCount(2, $this->client->created);
        $this->assertSame('120', $this->client->created[1][0]['amount']);
        $this->assertSame([self::FIRST], $this->client->cancelled);
        $this->assertSame(2, $this->repo->rows[self::SECOND]['attempt']);
    }

    public function test_an_open_invoice_with_money_is_not_cancelled()
    {
        $this->payment()->start(42);
        $this->client->set(self::FIRST, ['amount_pending' => '50']);
        $this->host->invoice['total'] = '120.00';
        $this->payment()->start(42);
        $this->assertSame([], $this->client->cancelled);
        $this->assertSame('processing', $this->host->transactions[self::FIRST]['status']);
    }

    public function test_a_paid_open_invoice_is_applied_before_a_new_one()
    {
        $this->payment()->start(42);
        $this->client->set(self::FIRST, ['status' => 'paid', 'amount_paid' => '100', 'amount_due' => '0']);
        $this->assertSame(['redirect' => 'https://billing.example/invoices/42'], $this->payment()->start(42));
        $this->assertSame('paid', $this->host->invoice['status']);
        $this->assertCount(1, $this->client->created);
    }

    public function test_a_currency_coinspay_does_not_take_is_explained()
    {
        $this->host->invoice['currency_code'] = 'XYZ';
        $result = $this->payment()->start(42);
        $this->assertStringContainsString('XYZ', $result['error']);
        $this->assertCount(0, $this->client->created);
    }

    public function test_a_test_invoice_of_an_admin_is_not_handed_to_a_client()
    {
        $this->host->settings['api_key'] = 'cpk_test_key';
        $this->host->settings['test_admin_only'] = false;
        $this->client->test = true;
        $this->host->admin = true;
        $this->payment()->start(42);
        $this->host->admin = false;
        $result = $this->payment()->start(42);
        $this->assertSame('https://pay.example/pay/' . self::SECOND . '?lang=en', $result['redirect']);
        $this->assertSame(0, $this->repo->rows[self::SECOND]['test_admin']);
        $this->assertSame([self::FIRST], $this->client->cancelled);
    }

    public function test_a_currency_without_cents_is_asked_whole_and_remembers_the_cents()
    {
        $this->host->invoice = ['total' => '1078.32', 'currency_code' => 'JPY'] + $this->host->invoice;
        $this->payment()->start(42);
        $this->assertSame('1078', $this->client->created[0][0]['amount']);
        $this->assertSame('1078.32', $this->repo->rows[self::FIRST]['due']);
        // Asked again: the same invoice, not a new one for the cents.
        $this->payment()->start(42);
        $this->assertCount(1, $this->client->created);
    }

    public function test_only_a_web_address_goes_to_the_browser()
    {
        $this->client->payUrl = 'javascript:alert(1)';
        $result = $this->payment()->start(42);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('redirect', $result);
    }

    public function test_an_old_invoice_met_by_the_idempotency_key_is_never_adopted()
    {
        // The tables were wiped: the keys of both attempts meet closed invoices made before.
        $this->client->createStatus = 'paid';
        $result = $this->payment()->start(42);
        $this->assertArrayHasKey('error', $result);
        $this->assertCount(2, $this->client->created);
        $this->assertSame([], $this->repo->rows);
        $this->assertSame([], $this->host->records);
    }

    public function test_no_key_no_payment()
    {
        $this->host->settings['api_key'] = '';
        $this->assertArrayHasKey('error', $this->payment()->start(42));
    }
}
