<?php

namespace Paymenter\Extensions\Gateways\CoinsPay\Lib;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * Paymenter for the module: the only class that knows Laravel and the models of Paymenter.
 */
final class PaymenterHost implements Host
{
    /** Name of the gateway extension: the folder and the class. */
    public const GATEWAY = 'CoinsPay';

    public function pdo(): PDO
    {
        return DB::connection()->getPdo();
    }

    public function tablePrefix(): string
    {
        return (string) DB::connection()->getTablePrefix();
    }

    public function siteKey(): string
    {
        return rtrim((string) config('app.url'), '/') . '|' . DB::connection()->getDatabaseName();
    }

    public function inTransaction(): bool
    {
        return DB::connection()->transactionLevel() > 0;
    }

    public function adminLanguage(): string
    {
        return (string) config('app.locale', 'en');
    }

    public function userLanguage(): string
    {
        return (string) app()->getLocale();
    }

    public function isAdmin(): bool
    {
        try {
            $user = Auth::user();

            // Paymenter lets a user into the admin area when the user has a role.
            return $user !== null && $user->role_id !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public function invoice(int $invoiceId): ?array
    {
        $invoice = Invoice::query()->with(['items', 'transactions'])->find($invoiceId);
        if (!$invoice) {
            return null;
        }

        return [
            'id' => (int) $invoice->id,
            'number' => (string) ($invoice->number ?? $invoice->id),
            'user_id' => (int) $invoice->user_id,
            'status' => (string) $invoice->status,
            'total' => self::decimal($invoice->total),
            'remaining' => self::decimal($invoice->remaining),
            'currency_code' => (string) $invoice->currency_code,
        ];
    }

    public function transaction(int $invoiceId, string $transactionId): ?array
    {
        // The newest row: before 1.5.7 Paymenter could keep two rows of one id.
        $transaction = InvoiceTransaction::query()
            ->where('invoice_id', $invoiceId)
            ->where('transaction_id', $transactionId)
            ->orderByDesc('id')
            ->first();
        if (!$transaction) {
            return null;
        }
        $status = $transaction->status;

        return [
            'status' => $status instanceof \BackedEnum ? (string) $status->value : (string) $status,
            'amount' => self::decimal($transaction->amount),
        ];
    }

    public function record(int $invoiceId, string $transactionId, string $amount, string $status): void
    {
        // The id, not the model: Paymenter before 1.5 looks the invoice up with findOrFail($invoice).
        ExtensionHelper::addPayment($invoiceId, self::GATEWAY, $amount, null, $transactionId, InvoiceTransactionStatus::from($status));
    }

    public function currencies(): array
    {
        return Currency::query()->pluck('code')->map(fn ($code) => strtoupper((string) $code))->all();
    }

    public function invoiceUrl(int $invoiceId, bool $checkPayment = false): string
    {
        return route('invoices.show', $invoiceId) . ($checkPayment ? '?checkPayment=true' : '');
    }

    public function adminInvoiceUrl(int $invoiceId): string
    {
        try {
            return route('filament.admin.resources.invoices.edit', $invoiceId);
        } catch (Throwable) {
            return url('/admin/invoices/' . $invoiceId . '/edit');
        }
    }

    public function routeUrl(string $name, array $query = []): string
    {
        $url = url('/extensions/gateways/coinspay/' . $name);

        return $query ? $url . '?' . http_build_query($query, '', '&') : $url;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::log(in_array($level, ['info', 'warning', 'error'], true) ? $level : 'info', '[CoinsPay] ' . $message, $context);
        } catch (Throwable) {
            // A log the web server may not write to (made by the cron as root, say) must not stop
            // a payment: the decision is in the tables of the module anyway.
        }
    }

    private static function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
