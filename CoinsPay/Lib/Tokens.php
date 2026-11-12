<?php

namespace Paymenter\Extensions\Gateways\CoinsPay\Lib;

/**
 * Signed values of the module's own links.
 */
final class Tokens
{
    /** Name of the signature parameter. */
    public const FIELD = 'cp_sig';

    public function __construct(private readonly string $secret) {}

    /**
     * Signature of the return link of an invoice. Without time in it: the return link is part of
     * the invoice body, and the body is part of the idempotency key.
     */
    public function returnToken(int $invoiceId): string
    {
        return $this->sign('return|' . $invoiceId);
    }

    public function checkReturn(int $invoiceId, string $signature): bool
    {
        return $this->secret !== '' && Util::equals($this->returnToken($invoiceId), $signature);
    }

    private function sign(string $data): string
    {
        return substr(hash_hmac('sha256', $data, $this->secret), 0, 40);
    }
}
