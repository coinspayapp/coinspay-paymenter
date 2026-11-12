<?php

namespace Paymenter\Extensions\Gateways\CoinsPay\Lib;

use PDO;

/**
 * What the module needs of Paymenter. PaymenterHost is the real one; the unit tests have a fake.
 */
interface Host
{
    /** PDO of the Paymenter database (MySQL or MariaDB). */
    public function pdo(): PDO;

    /** Table prefix of the Paymenter database connection. */
    public function tablePrefix(): string;

    /**
     * What tells this Paymenter from another on the same CoinsPay shop or database server, the
     * same for every request: the configured address and the database.
     */
    public function siteKey(): string;

    /** Whether a database transaction is open on the connection. */
    public function inTransaction(): bool;

    /** Language of the admin texts: the default locale of Paymenter. */
    public function adminLanguage(): string;

    /** Language of the person on the page now. */
    public function userLanguage(): string;

    /** Whether the person on the page now is a Paymenter admin. */
    public function isAdmin(): bool;

    /**
     * A Paymenter invoice, fresh from the database.
     *
     * @return array|null {id, number, user_id, status (pending|paid|cancelled…), total, remaining, currency_code}
     */
    public function invoice(int $invoiceId): ?array;

    /**
     * The transaction of the module on an invoice.
     *
     * @return array|null {status: processing|succeeded|failed, amount}
     */
    public function transaction(int $invoiceId, string $transactionId): ?array;

    /**
     * Records the transaction of the module on an invoice, or changes it: Paymenter keeps one row
     * per transaction id, and a succeeded one that covers the invoice marks it paid.
     *
     * @param  string  $status  processing, succeeded or failed.
     */
    public function record(int $invoiceId, string $transactionId, string $amount, string $status): void;

    /** Currency codes of the shop. */
    public function currencies(): array;

    /** Address of an invoice page for the client. */
    public function invoiceUrl(int $invoiceId, bool $checkPayment = false): string;

    /** Address of an invoice in the admin area. */
    public function adminInvoiceUrl(int $invoiceId): string;

    /** Address of a route of the module: webhook or return. */
    public function routeUrl(string $name, array $query = []): string;

    /**
     * A line of the log.
     *
     * @param  string  $level  info, warning or error.
     */
    public function log(string $level, string $message, array $context = []): void;
}
