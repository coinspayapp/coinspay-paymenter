<?php

namespace Paymenter\Extensions\Gateways\CoinsPay\Lib;

use Exception;

/**
 * Webhook of CoinsPay: checks the signature, then reads the invoice from the API instead of
 * trusting the event, and applies it. The event is only a reason to look. From the WHMCS module
 * (Webhook.php).
 */
final class Webhook
{
    public function __construct(private readonly Module $module) {}

    /**
     * @param  string  $body  Raw request body.
     * @param  string  $header  x-cpay-signature.
     * @param  int|null  $now  For tests.
     * @return array {0: HTTP status, 1: text}. 2xx — CoinsPay stops sending the event.
     */
    public function handle(string $body, string $header, ?int $now = null): array
    {
        $settings = $this->module->settings();
        // A deleted gateway has no settings: CoinsPay retries until it is back.
        if (!$settings->active()) {
            return [503, 'the CoinsPay gateway is not set up'];
        }
        if ($settings->webhookSecret() === '' || !Signature::verify($body, $header, $settings->webhookSecret(), $now)) {
            return [401, 'bad signature'];
        }
        $event = json_decode($body, true);
        if (!is_array($event) || !isset($event['type']) || !is_string($event['type'])) {
            return [400, 'not an event'];
        }
        $repo = $this->module->repo();
        if ($event['type'] === 'webhook.test') {
            $repo->ensure();
            $repo->kvSet('webhook_test', (string) time());

            return [200, 'test event received'];
        }
        $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : [];
        $invoiceId = '';
        if (isset($data['invoice']) && is_array($data['invoice'])) {
            $invoiceId = Util::value($data['invoice'], 'id');
        } elseif (isset($data['deposit']) && is_array($data['deposit'])) {
            $invoiceId = Util::value($data['deposit'], 'invoice_id');
        }
        $row = $invoiceId !== '' && $repo->ready() ? $repo->find($invoiceId) : null;
        if (!$row) {
            return [200, 'not an invoice of this Paymenter'];
        }
        // Ours, but made with a key of the other mode: the admin switched keys. 503 brings the event
        // again until the key of its mode is back, so a real payment is not dropped.
        if ((bool) $row['test'] !== $settings->isTestMode()) {
            $this->module->host()->log('error', sprintf('webhook: CoinsPay invoice %s is of the other mode than the key now', $row['invoice_id']));

            return [503, 'the invoice is of the other mode than the key now'];
        }

        try {
            $invoice = $this->module->client()->invoice($invoiceId);

            return [200, $this->module->sync()->apply($invoice)];
        } catch (ApiError $e) {
            $this->module->host()->log('error', 'webhook: ' . $row['invoice_id'] . ': ' . $e->summary());
            if ($e->isNotFound()) {
                // Ours, yet unknown to this key: a key of another shop now. CoinsPay sends it again.
                return [503, 'the invoice is not found for this key'];
            }

            return [503, 'the CoinsPay API is not reachable'];
        } catch (Exception $e) {
            $this->module->host()->log('error', 'webhook: ' . $e->getMessage());

            return [500, 'the invoice was not applied'];
        }
    }
}
