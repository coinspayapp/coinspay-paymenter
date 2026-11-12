<?php

use Paymenter\Extensions\Gateways\CoinsPay\Lib\ApiClient;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\ApiError;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\InvoiceRequest;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Settings;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Signature;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Status;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Text;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Tokens;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Util;
use PHPUnit\Framework\TestCase;

/**
 * The small parts: signature, tokens, settings, amounts, texts and the status block.
 */
final class PartsTest extends TestCase
{
    // Computed by the CoinsPay server code (signPayload), as in the tests of the other plugins.
    private const BODY = '{"id":"0b0e1c1e-6f0c-4c56-9a8e-2d6c1e1f0a01","type":"invoice.paid","created_at":"2026-10-28T08:00:00.000Z","shop_id":"5a1f6b9e-1c2d-4e3f-8a9b-0c1d2e3f4a5b","test":true,"data":{"invoice":{"id":"7c9e6679-7425-40de-944b-e07fc1f90ae7","status":"paid"}}}';

    private const SECRET = 'whsec_plugin_test_secret';

    private const TIME = 1761638400;

    private const V1 = '34c05e780e334839328753e5d1b2fa20f7705b67b4489d2f29abda8695af92d9';

    public function test_the_signature_of_coinspay_is_accepted()
    {
        $header = 't=' . self::TIME . ',v1=' . self::V1;
        $this->assertTrue(Signature::verify(self::BODY, $header, self::SECRET, self::TIME));
        $this->assertTrue(Signature::verify(self::BODY, $header, self::SECRET, self::TIME + 300));
        $this->assertTrue(Signature::verify(self::BODY, ' t=' . self::TIME . ' , v1=' . self::V1 . ' ', self::SECRET, self::TIME));
        $this->assertTrue(Signature::verify(self::BODY, 't=' . self::TIME . ',v1=' . str_repeat('0', 64) . ',v1=' . self::V1, self::SECRET, self::TIME));
    }

    public function test_stale_foreign_changed_and_malformed_signatures_are_not()
    {
        $header = 't=' . self::TIME . ',v1=' . self::V1;
        $this->assertFalse(Signature::verify(self::BODY, $header, self::SECRET, self::TIME + 301));
        $this->assertFalse(Signature::verify(self::BODY, $header, 'whsec_other', self::TIME));
        $this->assertFalse(Signature::verify(str_replace('"paid"', '"open"', self::BODY), $header, self::SECRET, self::TIME));
        foreach (['', 'v1=' . self::V1, 't=abc,v1=' . self::V1, 't=' . self::TIME, 't=' . self::TIME . ',v1=XYZ', '=1,t=' . self::TIME . ',v1=' . self::V1, 'garbage', 't=' . self::TIME . ',v1=' . strtoupper(self::V1)] as $bad) {
            $this->assertFalse(Signature::verify(self::BODY, $bad, self::SECRET, self::TIME), $bad);
        }
        $this->assertFalse(Signature::verify(self::BODY, $header, '', self::TIME));
    }

    public function test_the_return_token()
    {
        $tokens = new Tokens('secret');
        $this->assertSame($tokens->returnToken(42), $tokens->returnToken(42));
        $this->assertTrue($tokens->checkReturn(42, $tokens->returnToken(42)));
        $this->assertFalse($tokens->checkReturn(43, $tokens->returnToken(42)));
        $this->assertFalse((new Tokens(''))->checkReturn(42, (new Tokens(''))->returnToken(42)));
        $this->assertSame('', (new Settings(['api_key' => 'cpk_x']))->tokenSecret());
        $this->assertNotSame(
            (new Settings(['api_key' => 'cpk_x', 'webhook_secret' => 'whsec_a']))->tokenSecret(),
            (new Settings(['api_key' => 'cpk_x', 'webhook_secret' => 'whsec_b']))->tokenSecret()
        );
    }

    public function test_settings_read_what_paymenter_keeps()
    {
        $this->assertSame('https://coinspay.app', (new Settings([]))->apiUrl());
        $this->assertSame('https://coinspay.app', (new Settings(['api_url' => '  ']))->apiUrl());
        $this->assertSame('https://coinspay.app/api/v1', ApiClient::base((new Settings([]))->apiUrl()));
        $this->assertSame(15, (new Settings(['lifetime' => '5']))->lifetime());
        $this->assertSame(60, (new Settings(['lifetime' => '']))->lifetime());
        $this->assertSame(1440, (new Settings(['lifetime' => 9999]))->lifetime());

        // A box saved by the create page, by the edit page, or never saved.
        foreach ([true, '1', 1, 'on', 'true'] as $on) {
            $this->assertTrue((new Settings(['debug' => $on]))->flag('debug'), var_export($on, true));
        }
        foreach ([false, '0', 0, '', 'off'] as $off) {
            $this->assertFalse((new Settings(['auto_return' => $off]))->flag('auto_return'), var_export($off, true));
        }
        $this->assertTrue((new Settings([]))->flag('test_admin_only'));
        $this->assertTrue((new Settings(['test_admin_only' => null]))->flag('test_admin_only'));
        $this->assertFalse((new Settings([]))->flag('apply_test'));
        $this->assertTrue((new Settings(['api_key' => ' cpk_test_x ']))->isTestMode());
    }

    public function test_the_api_address_is_one_the_key_may_go_to()
    {
        $this->assertSame('https://pay.example/api/v1', ApiClient::base('pay.example'));
        $this->assertSame('http://host.docker.internal:3410/api/v1', ApiClient::base('http://host.docker.internal:3410'));
        $this->assertSame('', ApiClient::base('http://pay.example/api/v1'), 'the key would go in clear text');
        $this->assertSame('', ApiClient::base('https://user:pw@pay.example'));
        $this->assertSame('', ApiClient::base('https://pay.example/api/v1?x=1'));
        $this->assertTrue((new ApiError(404, 'NOT_FOUND', ''))->isNotFound());
        $this->assertFalse((new ApiError(404, 'HTTP_404', ''))->isNotFound(), 'a 404 of a proxy or a wrong address');
    }

    public function test_the_invoice_body_and_its_idempotency_key()
    {
        $settings = new Settings(['buyer_pays_fee' => true, 'auto_return' => false, 'lifetime' => 30]);
        $body = InvoiceRequest::body(['reference' => '42', 'currency' => 'usd', 'amount' => '10', 'description' => '<b>Invoice</b> #42', 'return_url' => 'https://b.example/r'], $settings);
        $this->assertSame(['currency' => 'USD', 'amount' => '10', 'reference' => '42', 'lifetime_minutes' => 30, 'description' => 'Invoice #42', 'return_url' => 'https://b.example/r', 'buyer_pays_fee' => true], $body);

        $long = InvoiceRequest::body(['reference' => '42', 'currency' => 'USD', 'amount' => '10', 'description' => '', 'return_url' => 'https://b.example/' . str_repeat('x', 600)], $settings);
        $this->assertArrayNotHasKey('return_url', $long);

        $a = InvoiceRequest::idempotencyKey('https://b.example/', 42, 1, ['amount' => '100']);
        $this->assertSame($a, InvoiceRequest::idempotencyKey('https://b.example/', 42, 1, ['amount' => '100']));
        $this->assertNotSame($a, InvoiceRequest::idempotencyKey('https://b.example/', 42, 1, ['amount' => '101']));
        $this->assertNotSame($a, InvoiceRequest::idempotencyKey('https://staging.example/', 42, 1, ['amount' => '100']));
    }

    public function test_amounts()
    {
        $this->assertSame('49.9', Util::formatAmount('49.90', 2));
        $this->assertSame('10', Util::formatAmount(9.999, 2));
        $this->assertTrue(Util::positive('0.01'));
        $this->assertFalse(Util::positive('0.00'));
        $this->assertFalse(Util::positive('-5'));
        $this->assertFalse(Util::positive(null));
        $this->assertSame(0, Util::compare('100', '100.00', 2));
        $this->assertSame(-1, Util::compare('99.99', '100', 2));
        // The share of a payment in the invoice amount.
        $this->assertSame('40', Util::share('40', '100', '100', 2));
        $this->assertSame('100', Util::share('120', '100', '100', 2), 'never more than asked');
        $this->assertSame('0.3', Util::share('0.3', '1', '1', 2));
        $this->assertSame('33.33', Util::share('34.33', '100', '103', 2));
        $this->assertSame('100', Util::share('103', '100', '103', 2));
        $this->assertSame('0', Util::share('0', '100', '100', 2));
    }

    public function test_amounts_of_eighteen_decimals_do_not_overflow()
    {
        // 0.5 and 0.05 in units of 10^-20 are past the range of an int.
        $this->assertSame(1, Util::compare('0.5', '0.05', 20));
        $this->assertSame(-1, Util::compare('0.05', '0.5', 20));
        $this->assertSame(0, Util::compare('0.50', '0.5', 20));
        $this->assertSame(-1, Util::compare('-3', '2', 20));
        $this->assertSame('0.05', Util::share('0.05', '0.5', '0.5', 2));
    }

    public function test_the_currency_list_is_not_asked_again_and_again_while_the_api_is_away()
    {
        $host = new FakeHost;
        $repo = new MemoryRepo;
        $client = new FakeClient;
        $client->errors['currencies'] = new ApiError(0, 'NETWORK', 'down');
        $module = fake_module($host, $repo, $client);
        $this->assertNull($module->currencies()->acceptsCached('USD'), 'unknown without the list');
        $this->assertTrue($module->currencies()->accepts('USD'), 'shown while the list cannot be read');
        $this->assertTrue(fake_module($host, $repo, $client)->currencies()->accepts('USD'));
        $this->assertSame(1, $client->currencyCalls, 'the failure is remembered');

        unset($client->errors['currencies']);
        unset($repo->kv[array_key_first(array_filter($repo->kv, fn ($k) => str_ends_with($k, '_fail'), ARRAY_FILTER_USE_KEY))]);
        $module = fake_module($host, $repo, $client);
        $this->assertFalse($module->currencies()->accepts('XYZ'));
        $this->assertTrue(fake_module($host, $repo, $client)->currencies()->acceptsCached('JPY'));
        $this->assertFalse(fake_module($host, $repo, $client)->currencies()->acceptsCached('XYZ'));
    }

    public function test_texts_and_long_texts()
    {
        $this->assertSame('Invoice #7', (new Text('en'))->t('invoice_description', '7'));
        $this->assertSame('Счёт #7', (new Text('ru_RU'))->t('invoice_description', '7'));
        $this->assertSame('Invoice #7', (new Text('de'))->t('invoice_description', '7'));
        $this->assertSame('no_such_key', (new Text('ru'))->t('no_such_key'));
        $this->assertSame("ab\u{2026}", Util::truncate('abcdef', 3));
        $this->assertSame("Счё\u{2026}", Util::truncate('Счёт № 12', 4));

        $en = include dirname(__DIR__, 2) . '/CoinsPay/lang/en.php';
        $ru = include dirname(__DIR__, 2) . '/CoinsPay/lang/ru.php';
        $this->assertSame(array_keys($en), array_keys($ru), 'the same keys in both languages');
        foreach ($en as $key => $value) {
            $this->assertSame(substr_count($value, '%'), substr_count($ru[$key], '%'), $key);
        }
    }

    public function test_the_status_block_tells_what_is_wrong()
    {
        $host = new FakeHost;
        $repo = new MemoryRepo;
        $client = new FakeClient;
        $html = (new Status(fake_module($host, $repo, $client)))->html();
        $this->assertStringContainsString('https://billing.example/extensions/gateways/coinspay/webhook', $html);
        $this->assertStringContainsString('shop &quot;Northwind Hosting&quot;, live mode', $html);
        $this->assertStringContainsString('No test event came yet', $html);
        $this->assertStringNotContainsString('full access', $html);

        $client->shop['key']['access'] = 'full';
        $repo->kv = ['webhook_test' => (string) time()];
        $repo->add(['invoice_id' => 'x', 'pm_invoice' => 5, 'attempt' => 1, 'amount' => '1', 'currency' => 'USD', 'test' => 0, 'test_admin' => 0, 'status' => 'paid', 'pay_url' => '', 'next_poll' => null, 'poll_until' => 0, 'attention' => 'Check <this>']);
        $html = (new Status(fake_module($host, $repo, $client)))->html();
        $this->assertStringContainsString('full access', $html);
        $this->assertStringContainsString('The last test event', $html);
        $this->assertStringContainsString('#5 — Check &lt;this&gt;', $html);
        $this->assertStringContainsString('href="https://billing.example/admin/invoices/5/edit"', $html);

        $host->settings['api_key'] = '';
        $this->assertStringContainsString('Put the API key', (new Status(fake_module($host, new MemoryRepo, $client)))->html());
    }
}
