<?php

namespace Paymenter\Extensions\Gateways\CoinsPay;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Models\Invoice;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\ApiClient;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Bridge;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Module;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\PaymenterHost;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Settings;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Status;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Text;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Util;
use Throwable;

#[ExtensionMeta(
    name: 'CoinsPay',
    description: 'Accept USDT, USDC, BTC, ETH, TRX and other cryptocurrencies with CoinsPay.',
    version: '0.1.0',
    author: 'CoinsPay',
    url: 'https://coinspay.app/plugins/paymenter',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAzMiAzMiI+PHJlY3Qgd2lkdGg9IjMyIiBoZWlnaHQ9IjMyIiByeD0iOSIgZmlsbD0iIzA2N2Y1YyIvPjxnIHN0cm9rZT0iI2ZmZiIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48cGF0aCBkPSJNMjIuMSAxMC45QTggOCAwIDEgMCAyMi4xIDIxLjEiIHN0cm9rZS13aWR0aD0iMi44Ii8+PHBhdGggZD0iTTE5IDEzLjRBNCA0IDAgMSAwIDE5IDE4LjYiIHN0cm9rZS13aWR0aD0iMi40IiBvcGFjaXR5PSIwLjciLz48L2c+PC9zdmc+',
)]
class CoinsPay extends Gateway
{
    public function boot()
    {
        Bridge::boot();
    }

    /**
     * Settings of the gateway, with the "Status" block on top.
     *
     * @param  array  $values  Settings saved so far; empty when the gateway is being created.
     */
    public function getConfig($values = [])
    {
        $values = is_array($values) ? $values : [];
        $text = new Text((string) config('app.locale', 'en'));

        return [
            [
                'name' => 'status',
                'type' => 'placeholder',
                'label' => new HtmlString($this->status($values, $text)),
            ],
            [
                'name' => 'api_key',
                'label' => $text->t('field_api_key'),
                'type' => 'password',
                'description' => $text->t('field_api_key_help'),
                'required' => true,
                'encrypted' => true,
            ],
            [
                'name' => 'webhook_secret',
                'label' => $text->t('field_webhook_secret'),
                'type' => 'password',
                'description' => $text->t('field_webhook_secret_help'),
                'required' => true,
                'encrypted' => true,
            ],
            [
                'name' => 'api_url',
                'label' => $text->t('field_api_url'),
                'type' => 'text',
                'placeholder' => Settings::DEFAULT_API_URL,
                'description' => $text->t('field_api_url_help'),
                'required' => false,
            ],
            [
                'name' => 'lifetime',
                'label' => $text->t('field_lifetime'),
                'type' => 'number',
                'default' => Settings::LIFETIME_DEFAULT,
                'min_value' => Settings::LIFETIME_MIN,
                'max_value' => Settings::LIFETIME_MAX,
                'suffix' => $text->t('field_minutes'),
                'description' => $text->t('field_lifetime_help'),
                'required' => false,
            ],
            $this->box('auto_return', $text),
            $this->box('stablecoins_only', $text),
            $this->box('buyer_pays_fee', $text),
            $this->box('buyer_pays_network', $text),
            $this->box('test_admin_only', $text),
            $this->box('apply_test', $text),
            $this->box('debug', $text),
        ];
    }

    /**
     * Hides the gateway when it cannot take the payment: no key, a currency CoinsPay does not
     * take, or a test key and a client who is not an admin.
     */
    public function canUseGateway($total, $currency, $type, $items = [])
    {
        try {
            $module = $this->module(5);
            $settings = $module->settings();
            if ($settings->apiKey() === '') {
                return false;
            }
            if ($settings->isTestMode() && $settings->flag('test_admin_only') && !$module->host()->isAdmin()) {
                return false;
            }
            if (!is_string($currency) || $currency === '') {
                return true;
            }
            // Never waits on the network here: the page lists the gateways. Without the list in the
            // cache the gateway shows, and the list is fetched after the page is sent.
            $accepted = $module->currencies()->acceptsCached($currency);
            if ($accepted === null) {
                app()->terminating(function () use ($module): void {
                    try {
                        $module->currencies()->load();
                    } catch (Throwable) {
                        // Asked again on a later page.
                    }
                });
            }

            return $accepted ?? true;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * The client pressed "Pay": the address of the CoinsPay payment page, or a message in the
     * payment window when it cannot be opened.
     *
     * @param  float  $total  What is due on the invoice; the module reads it again under its lock.
     */
    public function pay(Invoice $invoice, $total)
    {
        $result = $this->module()->payment()->start((int) $invoice->id);
        if (isset($result['redirect'])) {
            return $result['redirect'];
        }

        return new HtmlString('<p>' . Util::html((string) $result['error']) . '</p>');
    }

    /** First upload of the extension as a ZIP. */
    public function installed()
    {
        $this->install();
    }

    /** A newer ZIP over the extension. */
    public function upgraded($oldVersion = null)
    {
        $this->install();
    }

    /** The gateway was created in the admin area. */
    public function enabled($gateway = null)
    {
        $this->install();
    }

    /** The settings were saved. */
    public function updated($gateway = null)
    {
        $this->install();
    }

    private function install(): void
    {
        try {
            (new Module(new PaymenterHost, []))->repo()->ensure();
        } catch (Throwable $e) {
            (new PaymenterHost)->log('error', 'creating the tables: ' . $e->getMessage());
        }
    }

    private function module(int $timeout = ApiClient::TIMEOUT): Module
    {
        return new Module(new PaymenterHost, is_array($this->config) ? $this->config : [], true, $timeout);
    }

    private function box(string $name, Text $text): array
    {
        return [
            'name' => $name,
            'label' => $text->t('field_' . $name),
            'type' => 'checkbox',
            'default' => Settings::FLAGS[$name],
            'database_type' => 'boolean',
            'description' => $text->t('field_' . $name . '_help'),
            'required' => false,
        ];
    }

    private function status(array $values, Text $text): string
    {
        if (!$values) {
            // A new gateway: the webhook address is already known, the rest comes after saving.
            $webhook = (new PaymenterHost)->routeUrl('webhook');

            return '<div>' . Util::html($text->t('status_webhook', $webhook)) . '</div><div>' . Util::html($text->t('status_new')) . '</div>';
        }
        try {
            return (new Status(new Module(new PaymenterHost, $values, true, 5)))->html();
        } catch (Throwable $e) {
            return Util::html($text->t('status_api_error', $e->getMessage()));
        }
    }
}
