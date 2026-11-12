<?php

use Paymenter\Extensions\Gateways\CoinsPay\Lib\Module;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Payment;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * The webhook: signature, mode, unknown invoices, and the answers CoinsPay acts on.
 */
final class WebhookTest extends TestCase
{
    private const ID = '00000000-0000-4000-8000-000000000001';

    private FakeHost $host;

    private MemoryRepo $repo;

    private FakeClient $client;

    protected function setUp(): void
    {
        $this->host = new FakeHost;
        $this->repo = new MemoryRepo;
        $this->client = new FakeClient;
        (new Payment(fake_module($this->host, $this->repo, $this->client)))->start(42);
    }

    private function webhook(?Module $module = null): Webhook
    {
        return new Webhook($module ?? fake_module($this->host, $this->repo, $this->client));
    }

    private function event(string $type, string $id = self::ID): string
    {
        return json_encode(['id' => 'e1', 'type' => $type, 'created_at' => '2026-11-12T00:00:00.000Z', 'shop_id' => 's', 'test' => false, 'data' => ['invoice' => ['id' => $id, 'status' => 'paid']]]);
    }

    private function send(string $body, string $secret = 'whsec_test', ?int $time = null, ?Webhook $webhook = null): array
    {
        $time ??= time();

        return ($webhook ?? $this->webhook())->handle($body, 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, $secret));
    }

    public function test_the_invoice_from_the_api_is_applied_not_the_event()
    {
        // The event says paid; the API still says open: nothing is added.
        $this->assertSame([200, 'none'], $this->send($this->event('invoice.paid')));
        $this->assertSame([], $this->host->records);

        $this->client->set(self::ID, ['status' => 'paid', 'amount_paid' => '100', 'amount_due' => '0']);
        $answer = $this->send($this->event('invoice.paid'));
        $this->assertSame(200, $answer[0]);
        $this->assertSame('paid', $this->host->invoice['status']);
    }

    public function test_bad_signatures_are_refused()
    {
        $body = $this->event('invoice.paid');
        $this->assertSame(401, $this->webhook()->handle($body, '')[0]);
        $this->assertSame(401, $this->send($body, 'whsec_other')[0]);
        $this->assertSame(401, $this->send($body, 'whsec_test', time() - 400)[0]);
        $this->assertSame(401, $this->webhook()->handle(str_replace('paid', 'open', $body), 't=' . time() . ',v1=' . hash_hmac('sha256', time() . '.' . $body, 'whsec_test'))[0]);

        // No secret in the settings: nothing is signed by it.
        $this->host->settings['webhook_secret'] = '';
        $this->assertSame(401, $this->send($body, '')[0]);
    }

    public function test_other_events_are_accepted_and_left_alone()
    {
        $this->assertSame([200, 'test event received'], $this->send($this->event('webhook.test')));
        $this->assertNotNull($this->repo->kv['webhook_test']);
        $this->assertSame([200, 'not an invoice of this Paymenter'], $this->send($this->event('invoice.paid', '00000000-0000-4000-8000-000000000999')));
        $this->assertSame(400, $this->send('[1,2]')[0]);
    }

    public function test_a_switched_key_or_a_404_makes_coinspay_retry()
    {
        // The invoice was made with the live key; the admin put a test key in since.
        $this->host->settings['api_key'] = 'cpk_test_key';
        $this->assertSame(503, $this->send($this->event('invoice.paid'))[0]);

        $this->host->settings['api_key'] = 'cpk_live_key';
        unset($this->client->invoices[self::ID]);
        $this->assertSame([503, 'the invoice is not found for this key'], $this->send($this->event('invoice.paid')));
        $this->assertSame([], $this->host->records);
    }

    public function test_a_deleted_gateway_asks_coinspay_to_retry()
    {
        $module = new Module($this->host, $this->host->settings, false);
        $module->setRepo($this->repo);
        $this->assertSame(503, $this->send($this->event('invoice.paid'), webhook: $this->webhook($module))[0]);
    }

    public function test_a_failed_recording_makes_coinspay_retry()
    {
        $this->client->set(self::ID, ['status' => 'paid', 'amount_paid' => '100', 'amount_due' => '0']);
        $this->host->fail = 'silent';
        $this->assertSame([500, 'the invoice was not applied'], $this->send($this->event('invoice.paid')));
        $this->assertSame(200, $this->send($this->event('invoice.paid'))[0]);
        $this->assertSame('paid', $this->host->invoice['status']);
    }
}
