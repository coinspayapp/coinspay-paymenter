<?php

namespace Paymenter\Extensions\Gateways\CoinsPay\Lib;

/**
 * The module in one place: settings, tables, API client and texts for one request.
 */
class Module
{
    private ?Settings $settings = null;

    private ?Repo $repo = null;

    private ?Currencies $currencies = null;

    private ?ApiClient $client = null;

    /**
     * @param  array  $values  Settings of the gateway.
     * @param  bool  $active  Whether the gateway exists in Paymenter.
     * @param  int  $timeout  Seconds of an API request: short inside pages.
     */
    public function __construct(
        private readonly Host $host,
        private readonly array $values,
        private readonly bool $active = true,
        private readonly int $timeout = ApiClient::TIMEOUT,
    ) {}

    public function host(): Host
    {
        return $this->host;
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings($this->values, $this->active);
    }

    public function repo(): Repo
    {
        return $this->repo ??= new Repo($this->host);
    }

    public function currencies(): Currencies
    {
        return $this->currencies ??= new Currencies($this);
    }

    public function client(): ApiClient
    {
        return $this->client ??= $this->settings()->client($this->userAgent(), $this->timeout);
    }

    /** For tests: an API client of their own. */
    public function setClient(ApiClient $client): void
    {
        $this->client = $client;
    }

    /** For tests: tables in memory. */
    public function setRepo(Repo $repo): void
    {
        $this->repo = $repo;
    }

    public function tokens(): Tokens
    {
        return new Tokens($this->settings()->tokenSecret());
    }

    /** Texts in the default language of Paymenter: the log and the status for the admin. */
    public function adminText(): Text
    {
        return new Text($this->host->adminLanguage());
    }

    public function sync(): Sync
    {
        return new Sync($this);
    }

    public function payment(): Payment
    {
        return new Payment($this);
    }

    public function poller(): Poller
    {
        return new Poller($this);
    }

    public function webhook(): Webhook
    {
        return new Webhook($this);
    }

    public function userAgent(): string
    {
        return 'CoinsPay-Paymenter/' . Settings::VERSION . '; PHP/' . PHP_VERSION;
    }

    /** The signed return link of an invoice. */
    public function returnUrl(int $pmInvoice): string
    {
        return $this->host->routeUrl('return', ['invoice' => $pmInvoice, Tokens::FIELD => $this->tokens()->returnToken($pmInvoice)]);
    }

    public function webhookUrl(): string
    {
        return $this->host->routeUrl('webhook');
    }

    /** A debug line of the log, when the admin turned debugging on. */
    public function debug(string $message): void
    {
        if ($this->settings()->flag('debug')) {
            $this->host->log('info', $message);
        }
    }
}
