<?php

namespace Paymenter\Extensions\Gateways\CoinsPay\Lib;

use App\Events\Invoice\Updated as InvoiceUpdated;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Where Paymenter meets the module outside pay(): the routes, the listener of invoices, the
 * scheduler and the polling of an opened invoice page. Registered once per process from boot().
 */
final class Bridge
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        if (!Route::has('extensions.gateways.coinspay.webhook')) {
            Route::post('/extensions/gateways/coinspay/webhook', [self::class, 'webhook'])->name('extensions.gateways.coinspay.webhook');
            Route::get('/extensions/gateways/coinspay/return', [self::class, 'back'])->name('extensions.gateways.coinspay.return');
        }

        // Cancelled, paid with credit or by another gateway: the open CoinsPay invoices go. After
        // the commit, so no API call runs inside a transaction of Paymenter.
        Event::listen(InvoiceUpdated::class, function (InvoiceUpdated $event): void {
            $invoice = $event->invoice;
            if (Sync::$applying || !$invoice->wasChanged('status') || $invoice->status === 'pending') {
                return;
            }
            $id = (int) $invoice->id;
            DB::afterCommit(fn () => self::safely('invoice listener', function () use ($id): void {
                $module = self::module(5);
                if ($module && $module->repo()->ready() && $module->repo()->forInvoice($id)) {
                    $module->payment()->cancelReplaced($id);
                }
            }));
        });

        // The page of an invoice asks CoinsPay about it, at most once a minute: after the answer
        // went out, and only for its client or an admin — the route matches before sign-in.
        Event::listen(RouteMatched::class, function (RouteMatched $event): void {
            if ($event->route->getName() !== 'invoices.show') {
                return;
            }
            $param = $event->route->parameter('invoice');
            $id = (int) (is_object($param) ? ($param->id ?? 0) : (is_scalar($param) ? $param : 0));
            if ($id <= 0) {
                return;
            }
            app()->terminating(fn () => self::safely('invoice page', function () use ($id): void {
                $user = Auth::user();
                $module = $user ? self::module(5) : null;
                if (!$module || !$module->repo()->ready()) {
                    return;
                }
                $invoice = $module->host()->invoice($id);
                if ($invoice && ((int) $invoice['user_id'] === (int) $user->id || $module->host()->isAdmin())) {
                    $module->poller()->checkInvoice($id);
                }
            }));
        });

        // Polling every minute from "php artisan schedule:run" (the cron of Paymenter).
        $add = function (Schedule $schedule): void {
            $schedule->call(fn () => self::poll())->everyMinute()->name('coinspay-poll')->withoutOverlapping(10);
        };
        app()->afterResolving(Schedule::class, $add);
        if (app()->resolved(Schedule::class)) {
            $add(app(Schedule::class));
        }
    }

    /** One run of the poller. */
    public static function poll(): void
    {
        self::safely('polling', function (): void {
            $module = self::module();
            if ($module && $module->settings()->apiKey() !== '') {
                if (!$module->repo()->ready()) {
                    return;
                }
                $module->poller()->run();
            }
        });
    }

    /** POST /extensions/gateways/coinspay/webhook */
    public static function webhook(Request $request): Response
    {
        $module = self::module();
        if (!$module) {
            return response('the CoinsPay gateway is not set up', 503);
        }
        try {
            [$status, $text] = $module->webhook()->handle((string) $request->getContent(), (string) $request->header('x-cpay-signature', ''));
        } catch (Throwable $e) {
            $module->host()->log('error', 'webhook: ' . $e->getMessage());
            [$status, $text] = [500, 'the invoice was not applied'];
        }

        return response($text, $status)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /** GET /extensions/gateways/coinspay/return?invoice=…&cp_sig=… — the client back from the payment page. */
    public static function back(Request $request): Response
    {
        $id = $request->query('invoice');
        $id = is_string($id) && ctype_digit($id) ? (int) $id : 0;
        $signature = $request->query(Tokens::FIELD);
        $host = new PaymenterHost;
        $module = self::module();
        if ($id <= 0 || !$module || !is_string($signature) || !$module->tokens()->checkReturn($id, $signature)) {
            return redirect($id > 0 ? $host->invoiceUrl($id) : url('/'));
        }
        self::safely('return', function () use ($module, $id): void {
            $row = $module->repo()->current($id);
            if ($row && (bool) $row['test'] === $module->settings()->isTestMode()) {
                $module->sync()->apply($module->client()->invoice($row['invoice_id']));
            }
        });
        $invoice = $host->invoice($id);

        // ?checkPayment makes the page of Paymenter watch the invoice until it is paid.
        return redirect($host->invoiceUrl($id, $invoice !== null && $invoice['status'] === 'pending'));
    }

    /**
     * The module with the settings of the gateway as stored now, or null when there is no gateway.
     */
    public static function module(int $timeout = ApiClient::TIMEOUT): ?Module
    {
        $gateway = Gateway::query()->where('extension', PaymenterHost::GATEWAY)->with('settings')->first();
        if (!$gateway) {
            return null;
        }

        return new Module(new PaymenterHost, ExtensionHelper::settingsToArray($gateway->settings), true, $timeout);
    }

    /** Runs a part of the module that must never break a page or a job of Paymenter. */
    private static function safely(string $where, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            try {
                (new PaymenterHost)->log('error', $where . ': ' . $e->getMessage());
            } catch (Throwable) {
                // Nothing more to do.
            }
        }
    }
}
