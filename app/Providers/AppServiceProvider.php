<?php

namespace App\Providers;

use App\Domain\Bills\Services\BillCatalogService;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Notifications\Services\OutboxService;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Providers\Services\CircuitBreaker;
use App\Domain\Providers\Services\OutcomeClassifier;
use App\Domain\Providers\Services\ProviderGateway;
use App\Domain\Reconciliation\Services\ReconciliationService;
use App\Domain\Risk\Services\RiskEngine;
use App\Domain\Transactions\Services\BillPaymentService;
use App\Domain\Transactions\Services\IdempotencyService;
use App\Domain\Transactions\Services\TransactionService;
use App\Domain\Wallet\Services\WalletService;
use App\Infrastructure\Messaging\Events\OutboxEventDispatched;
use App\Infrastructure\Messaging\OutboxPublisher;
use App\Infrastructure\Providers\ProviderFactory;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OutboxPublisher::class, function ($app) {
            return new OutboxPublisher();
        });

        $this->app->singleton(ProviderGateway::class, function ($app) {
            return new ProviderGateway(
                $app->make(ProviderFactory::class),
                $app->make(OutcomeClassifier::class),
                $app->make(CircuitBreaker::class),
            );
        });

        $this->app->singleton(CircuitBreaker::class, function ($app) {
            return new CircuitBreaker($app['cache.store']);
        });

        $this->app->singleton(WalletService::class, function ($app) {
            return new WalletService($app->make(LedgerService::class));
        });

        $this->app->singleton(BillPaymentService::class, function ($app) {
            return new BillPaymentService(
                $app->make(WalletService::class),
                $app->make(TransactionService::class),
                $app->make(IdempotencyService::class),
                $app->make(RiskEngine::class),
                $app->make(ProviderGateway::class),
                $app->make(OutboxService::class),
                $app->make(BillCatalogService::class),
            );
        });

        $this->app->singleton(\App\Domain\Payments\Services\FundingService::class, function ($app) {
            return new \App\Domain\Payments\Services\FundingService(
                $app->make(WalletService::class),
                $app->make(TransactionService::class),
                $app->make(IdempotencyService::class),
                $app->make(RiskEngine::class),
                $app->make(ProviderGateway::class),
                $app->make(OutboxService::class),
            );
        });

        $this->app->singleton(ReconciliationService::class, function ($app) {
            return new ReconciliationService($app->make(ProviderFactory::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('transactions', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?? $request->ip());
        });

        RateLimiter::for('verification', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?? $request->ip());
        });

        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // Outbox events drive customer notifications. In production these
        // dispatch queued jobs per channel; the scaffold uses the log
        // channel via NotificationService.
        Event::listen(OutboxEventDispatched::class, function (OutboxEventDispatched $event): void {
            $type = $event->event->event_type;

            if (! in_array($type, ['transaction.completed', 'transaction.failed', 'transaction.ambiguous'], true)) {
                return;
            }

            $reference = (string) ($event->event->payload['reference'] ?? '');
            $transaction = Transaction::where('reference', $reference)->first();

            if ($transaction === null) {
                return;
            }

            $titles = [
                'transaction.completed' => 'Transaction completed',
                'transaction.failed' => 'Transaction failed',
                'transaction.ambiguous' => 'Transaction being verified',
            ];

            $statusText = $type === 'transaction.ambiguous' ? 'being verified' : (string) $transaction->status;

            app(\App\Domain\Notifications\Services\NotificationService::class)->send(
                (int) $transaction->user_id,
                $type,
                $titles[$type],
                "Your {$transaction->type} transaction {$reference} is {$statusText}.",
            );

            // Database-channel record for the mobile "in-app notifications"
            // list — kept separate from the SMS/push delivery above, since
            // it must exist even for a user who never opted into push.
            $user = $transaction->user;

            if ($user !== null) {
                $user->notify(match ($type) {
                    'transaction.completed' => new \App\Notifications\V1\TransactionSuccessNotification($transaction),
                    'transaction.failed' => new \App\Notifications\V1\TransactionFailedNotification($transaction),
                    default => new \App\Notifications\V1\TransactionVerifyingNotification($transaction),
                });
            }
        });
    }
}
