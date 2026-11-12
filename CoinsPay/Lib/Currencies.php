<?php

namespace Paymenter\Extensions\Gateways\CoinsPay\Lib;

/**
 * Currencies CoinsPay accepts prices in, with their decimals; cached for 12 hours. From the WHMCS
 * module (Currencies.php).
 */
final class Currencies
{
    public const TTL = 43200;

    /** Seconds a failed request keeps the API from being asked again. */
    public const FAILURE_TTL = 60;

    /** Code → decimals, per request. */
    private ?array $list = null;

    public function __construct(private readonly Module $module) {}

    /**
     * Decimals of a currency.
     *
     * @throws ApiError CURRENCY_NOT_SUPPORTED or the list is not available.
     */
    public function scale(string $code): int
    {
        $list = $this->load();
        $code = strtoupper($code);
        if (!isset($list[$code])) {
            throw new ApiError(400, 'CURRENCY_NOT_SUPPORTED', 'CoinsPay does not accept prices in ' . $code);
        }

        return $list[$code];
    }

    /** Whether CoinsPay takes prices in the currency; true when the list cannot be read now. */
    public function accepts(string $code): bool
    {
        try {
            $this->scale($code);

            return true;
        } catch (ApiError $e) {
            return $e->errorCode() !== 'CURRENCY_NOT_SUPPORTED';
        }
    }

    /**
     * The same from the cache only, never waiting on the network: for pages that list the
     * gateways. Null when the list is not cached yet.
     */
    public function acceptsCached(string $code): ?bool
    {
        $list = $this->list ?? $this->cached();

        return $list === null ? null : isset($list[strtoupper($code)]);
    }

    /** @return array Code → decimals. */
    public function load(): array
    {
        if ($this->list !== null) {
            return $this->list;
        }
        $cached = $this->cached();
        if ($cached !== null) {
            return $this->list = $cached;
        }
        $repo = $this->module->repo();
        // While the API is away, pages do not wait on it again and again.
        if ($repo->kvGet($this->key() . '_fail', self::FAILURE_TTL) !== null) {
            throw new ApiError(0, 'UNAVAILABLE', 'The CoinsPay API was not reachable a moment ago');
        }

        try {
            $data = $this->module->client()->currencies();
        } catch (ApiError $e) {
            $repo->kvSet($this->key() . '_fail', (string) time());

            throw $e;
        }
        $list = [];
        foreach (['fiat', 'crypto'] as $kind) {
            if (empty($data[$kind]) || !is_array($data[$kind])) {
                continue;
            }
            foreach ($data[$kind] as $currency) {
                if (isset($currency['code'], $currency['scale'])) {
                    $list[strtoupper((string) $currency['code'])] = (int) $currency['scale'];
                }
            }
        }
        $repo->kvSet($this->key(), Util::json($list));

        return $this->list = $list;
    }

    private function cached(): ?array
    {
        $cached = $this->module->repo()->kvGet($this->key(), self::TTL);
        $list = $cached === null ? null : json_decode($cached, true);

        return is_array($list) ? $list : null;
    }

    private function key(): string
    {
        $settings = $this->module->settings();

        return 'currencies_' . substr(md5($settings->apiUrl() . '|' . ($settings->isTestMode() ? 'test' : 'live')), 0, 12);
    }
}
