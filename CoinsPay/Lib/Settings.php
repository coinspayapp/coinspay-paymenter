<?php

namespace Paymenter\Extensions\Gateways\CoinsPay\Lib;

/**
 * Settings of the gateway, from the values Paymenter keeps for it.
 */
final class Settings
{
    public const VERSION = '0.1.0';

    public const LIFETIME_MIN = 15;

    public const LIFETIME_MAX = 1440;

    public const LIFETIME_DEFAULT = 60;

    /** The API address when the admin leaves the field empty. */
    public const DEFAULT_API_URL = 'https://coinspay.app';

    /** Defaults of the yes/no settings, used until the admin saves the gateway. */
    public const FLAGS = [
        'auto_return' => true,
        'stablecoins_only' => false,
        'buyer_pays_fee' => false,
        'buyer_pays_network' => false,
        'test_admin_only' => true,
        'apply_test' => false,
        'debug' => false,
    ];

    /**
     * @param  array  $values  Settings of the gateway record, key => value.
     * @param  bool  $active  Whether the gateway exists in Paymenter.
     */
    public function __construct(private readonly array $values, private readonly bool $active = true) {}

    public function active(): bool
    {
        return $this->active;
    }

    public function apiUrl(): string
    {
        $url = rtrim(trim($this->get('api_url')), '/');

        return $url === '' ? self::DEFAULT_API_URL : $url;
    }

    public function apiKey(): string
    {
        return trim($this->get('api_key'));
    }

    public function webhookSecret(): string
    {
        return trim($this->get('webhook_secret'));
    }

    public function isTestMode(): bool
    {
        return str_starts_with($this->apiKey(), 'cpk_test_');
    }

    public function lifetime(): int
    {
        return self::clampLifetime($this->get('lifetime'));
    }

    public static function clampLifetime(mixed $value): int
    {
        $value = (int) $value;
        if ($value <= 0) {
            return self::LIFETIME_DEFAULT;
        }

        return max(self::LIFETIME_MIN, min(self::LIFETIME_MAX, $value));
    }

    /**
     * A yes/no setting. Paymenter keeps a box as true/false, "1"/"0" or "" depending on the page
     * that saved it; a box never saved takes its default.
     */
    public function flag(string $key): bool
    {
        if (!array_key_exists($key, $this->values) || $this->values[$key] === null) {
            return !empty(self::FLAGS[$key]);
        }
        $value = $this->values[$key];
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['on', 'yes', '1', 'true'], true);
    }

    /**
     * Secret of the module's own signed links. Made of the key and the webhook secret, so it
     * needs no storage; replacing either makes old links invalid.
     */
    public function tokenSecret(): string
    {
        if ($this->apiKey() === '' || $this->webhookSecret() === '') {
            return '';
        }

        return hash('sha256', 'coinspay-paymenter|' . $this->apiKey() . '|' . $this->webhookSecret());
    }

    public function client(string $userAgent, int $timeout = ApiClient::TIMEOUT): ApiClient
    {
        return new ApiClient($this->apiUrl(), $this->apiKey(), $userAgent, $timeout);
    }

    private function get(string $key): string
    {
        return isset($this->values[$key]) && is_scalar($this->values[$key]) ? (string) $this->values[$key] : '';
    }
}
