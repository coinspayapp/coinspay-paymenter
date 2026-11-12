<?php

use Paymenter\Extensions\Gateways\CoinsPay\Lib\ApiClient;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\ApiError;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Host;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Module;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Repo;

/**
 * Paymenter for unit tests: one invoice, its transactions in an array, and a record() that can
 * fail or claim success without recording anything, as asked.
 */
final class FakeHost implements Host
{
    public array $settings = ['api_url' => 'https://pay.example/api/v1', 'api_key' => 'cpk_live_key', 'webhook_secret' => 'whsec_test'];

    public array $invoice = ['id' => 42, 'number' => 'INV-42', 'user_id' => 7, 'status' => 'pending', 'total' => '100.00', 'currency_code' => 'USD'];

    /** Transaction id → {status, amount}. */
    public array $transactions = [];

    /** Every record() call: [transaction id, amount, status]. */
    public array $records = [];

    public array $log = [];

    public bool $admin = false;

    public string $locale = 'en';

    /** "throw" — fail before recording; "throw_after" — record, then fail; "silent" — record nothing. */
    public ?string $fail = null;

    public function pdo(): PDO
    {
        throw new LogicException('no database in unit tests');
    }

    public function tablePrefix(): string
    {
        return '';
    }

    public function siteKey(): string
    {
        return 'https://billing.example|paymenter';
    }

    /** A database transaction of Paymenter is open. */
    public bool $inTx = false;

    public function inTransaction(): bool
    {
        return $this->inTx;
    }

    public function adminLanguage(): string
    {
        return 'en';
    }

    public function userLanguage(): string
    {
        return $this->locale;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    /** Called before every read of the invoice with the number of the read: a change by Paymenter. */
    public ?Closure $onRead = null;

    private int $reads = 0;

    public function invoice(int $invoiceId): ?array
    {
        if ($this->onRead) {
            ($this->onRead)(++$this->reads, $this);
        }
        if ($invoiceId !== $this->invoice['id']) {
            return null;
        }

        return $this->invoice + ['remaining' => $this->remaining()];
    }

    public function remaining(): string
    {
        $paid = 0.0;
        foreach ($this->transactions as $transaction) {
            if ($transaction['status'] === 'succeeded') {
                $paid += (float) $transaction['amount'];
            }
        }

        return number_format((float) $this->invoice['total'] - $paid, 2, '.', '');
    }

    public function transaction(int $invoiceId, string $transactionId): ?array
    {
        return $invoiceId === $this->invoice['id'] ? ($this->transactions[$transactionId] ?? null) : null;
    }

    public function record(int $invoiceId, string $transactionId, string $amount, string $status): void
    {
        $fail = $this->fail;
        $this->fail = null;
        $this->records[] = [$transactionId, $amount, $status];
        if ($fail === 'throw') {
            throw new RuntimeException('the invoice could not be saved');
        }
        if ($fail !== 'silent') {
            $this->transactions[$transactionId] = ['status' => $status, 'amount' => number_format((float) $amount, 2, '.', '')];
            // As the listener of Paymenter does.
            if ((float) $this->remaining() <= 0 && $this->invoice['status'] !== 'paid') {
                $this->invoice['status'] = 'paid';
            }
        }
        if ($fail === 'throw_after') {
            throw new RuntimeException('a listener of another extension failed');
        }
    }

    public function currencies(): array
    {
        return ['USD', 'EUR'];
    }

    public function invoiceUrl(int $invoiceId, bool $checkPayment = false): string
    {
        return 'https://billing.example/invoices/' . $invoiceId . ($checkPayment ? '?checkPayment=true' : '');
    }

    public function adminInvoiceUrl(int $invoiceId): string
    {
        return 'https://billing.example/admin/invoices/' . $invoiceId . '/edit';
    }

    public function routeUrl(string $name, array $query = []): string
    {
        $url = 'https://billing.example/extensions/gateways/coinspay/' . $name;

        return $query ? $url . '?' . http_build_query($query, '', '&') : $url;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->log[] = [$level, $message];
    }

    /** Messages of a level. */
    public function logged(string $level): array
    {
        return array_values(array_map(fn ($line) => $line[1], array_filter($this->log, fn ($line) => $line[0] === $level)));
    }
}

/** The tables of the module in memory. */
final class MemoryRepo extends Repo
{
    public array $rows = [];

    public array $kv = [];

    public array $turns = [];

    public function __construct() {}

    public function install(): void {}

    public function ready(): bool
    {
        return true;
    }

    public function add(array $row): void
    {
        $this->rows[$row['invoice_id']] = $row + ['due' => number_format((float) $row['amount'], 2, '.', ''), 'pm_user' => 7, 'apply_state' => 'none', 'claimed' => null, 'fingerprint' => null, 'attention' => null, 'snapshot' => null, 'updated' => time()];
    }

    public function find(string $invoiceId): ?array
    {
        return $this->rows[strtolower($invoiceId)] ?? null;
    }

    public function forInvoice(int $pmInvoice): array
    {
        $rows = array_values(array_filter($this->rows, fn ($row) => (int) $row['pm_invoice'] === $pmInvoice));
        usort($rows, fn ($a, $b) => $b['attempt'] <=> $a['attempt']);

        return $rows;
    }

    public function update(string $invoiceId, array $columns): void
    {
        $columns['updated'] = time();
        $this->rows[strtolower($invoiceId)] = $columns + $this->rows[strtolower($invoiceId)];
    }

    public function due(int $limit): array
    {
        $now = time();

        return array_slice(array_values(array_filter($this->rows, fn ($row) => $row['next_poll'] !== null && $row['next_poll'] <= $now && $row['poll_until'] > $now)), 0, $limit);
    }

    public function attention(int $limit, int $since): array
    {
        return array_slice(array_values(array_filter($this->rows, fn ($row) => $row['attention'] !== null && $row['updated'] >= $since)), 0, $limit);
    }

    public function kvGet(string $key, int $maxAge = 0): ?string
    {
        return $this->kv[$key] ?? null;
    }

    public function kvSet(string $key, string $value): void
    {
        $this->kv[$key] = $value;
    }

    public function takeTurn(string $key, int $every): bool
    {
        if (isset($this->turns[$key]) && $this->turns[$key] > time() - $every) {
            return false;
        }
        $this->turns[$key] = time();

        return true;
    }

    public function claim(string $invoiceId, int $stale): bool
    {
        $row = $this->rows[strtolower($invoiceId)];
        if ($row['apply_state'] === 'applying' && $row['claimed'] !== null && $row['claimed'] >= time() - $stale) {
            return false;
        }
        $this->update($invoiceId, ['apply_state' => 'applying', 'claimed' => time()]);

        return true;
    }

    public function lock(int $pmInvoice): bool
    {
        return true;
    }

    public function unlock(int $pmInvoice): void {}
}

/** The CoinsPay API in memory: invoices by id, and the requests that came. */
final class FakeClient extends ApiClient
{
    public array $invoices = [];

    public array $created = [];

    public array $cancelled = [];

    public int $reads = 0;

    /** Invoices are made by a test key. */
    public bool $test = false;

    public array $shop = ['shop' => ['id' => 's1', 'name' => 'Northwind Hosting'], 'key' => ['test' => false, 'access' => 'invoices']];

    public function __construct() {}

    public function shop(): array
    {
        return $this->shop;
    }

    /** An error every request of the kind gets: invoice, currencies. */
    public array $errors = [];

    /** Status of the invoices "made": closed ones stand for an idempotency key met before. */
    public string $createStatus = 'open';

    /** pay_url of the invoices made, '' for the usual one. */
    public string $payUrl = '';

    public int $currencyCalls = 0;

    public function currencies(): array
    {
        $this->currencyCalls++;
        if (isset($this->errors['currencies'])) {
            throw $this->errors['currencies'];
        }

        return ['fiat' => [['code' => 'USD', 'scale' => 2], ['code' => 'EUR', 'scale' => 2], ['code' => 'JPY', 'scale' => 0]], 'crypto' => [['code' => 'USDT', 'scale' => 6], ['code' => 'ETH', 'scale' => 18]]];
    }

    public function createInvoice(array $body, string $idempotencyKey): array
    {
        $this->created[] = [$body, $idempotencyKey];
        $id = sprintf('00000000-0000-4000-8000-%012d', count($this->created));

        return $this->invoices[$id] = [
            'id' => $id, 'number' => count($this->created), 'status' => $this->createStatus, 'test' => $this->test,
            'currency' => $body['currency'], 'amount' => $body['amount'], 'amount_paid' => '0', 'amount_pending' => '0',
            'amount_due' => $body['amount'], 'amount_overpaid' => null, 'paid_late' => false, 'paid_manually' => false,
            'pay_url' => $this->payUrl !== '' ? $this->payUrl : 'https://pay.example/pay/' . $id, 'payments' => [],
        ];
    }

    public function invoice(string $id): array
    {
        $this->reads++;
        if (isset($this->errors['invoice'])) {
            throw $this->errors['invoice'];
        }
        if (!isset($this->invoices[$id])) {
            throw new ApiError(404, 'NOT_FOUND', 'Invoice not found');
        }

        return $this->invoices[$id];
    }

    public function cancelInvoice(string $id): array
    {
        $this->cancelled[] = $id;
        $this->invoices[$id]['status'] = 'cancelled';

        return $this->invoices[$id];
    }

    /** Sets fields of an invoice, as a payment or the dashboard would. */
    public function set(string $id, array $fields): array
    {
        return $this->invoices[$id] = $fields + $this->invoices[$id];
    }
}

/** A module on the fakes. */
function fake_module(FakeHost $host, MemoryRepo $repo, ?FakeClient $client = null): Module
{
    $module = new Module($host, $host->settings);
    $module->setRepo($repo);
    if ($client) {
        $module->setClient($client);
    }

    return $module;
}
