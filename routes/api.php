<?php

use App\Http\Controllers\Api\V1\Admin\AdminConfigController;
use App\Http\Controllers\Api\V1\Admin\AdminPushController;
use App\Http\Controllers\Api\V1\AirtimeController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BettingController;
use App\Http\Controllers\Api\V1\BillController;
use App\Http\Controllers\Api\V1\CableController;
use App\Http\Controllers\Api\V1\DataController;
use App\Http\Controllers\Api\V1\ElectricityController;
use App\Http\Controllers\Api\V1\GiftCardController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\NotificationDeviceController;
use App\Http\Controllers\Api\V1\PreferenceController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserNotificationController;
use App\Http\Controllers\Api\V1\UserTransactionController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WalletFundingController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Health
    Route::get('/health', [HealthController::class, 'show']);

    // Public — feature flags / socials, needed before a user is signed in.
    Route::get('/preferences', [PreferenceController::class, 'show']);

    // Authentication (session lifecycle)
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('/verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:auth');
    Route::post('/resend-email-otp', [AuthController::class, 'resendEmailOtp'])->middleware('throttle:auth');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
    Route::post('/verify-reset-otp', [AuthController::class, 'verifyResetOtp'])->middleware('throttle:auth');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    // Authenticated user endpoints
    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::prefix('user')->group(function (): void {
            Route::get('/profile', [UserController::class, 'show']);
            Route::put('/profile', [UserController::class, 'update']);
            Route::put('/change-password', [UserController::class, 'changePassword'])->middleware('throttle:auth');
            Route::post('/update-device-token', [UserController::class, 'updateDeviceToken']);
            Route::post('/set-pin', [UserController::class, 'setPin'])->middleware('throttle:auth');
            Route::post('/verify-pin', [UserController::class, 'verifyPin'])->middleware('throttle:auth');
            Route::post('/reset-pin', [UserController::class, 'resetPin'])->middleware('throttle:auth');

            Route::prefix('notifications')->group(function (): void {
                Route::get('/', [UserNotificationController::class, 'index']);
                Route::post('/mark-all-read', [UserNotificationController::class, 'markAllRead']);
                Route::post('/{id}/mark-read', [UserNotificationController::class, 'markRead'])
                    ->where('id', '[0-9a-fA-F-]{36}');
            });
        });

        Route::prefix('wallet')->group(function (): void {
            Route::get('/', [WalletController::class, 'summary']);
            Route::get('/balance', [WalletController::class, 'balance']);
            Route::get('/transactions', [WalletController::class, 'transactions']);
            Route::post('/fund', [WalletController::class, 'fund'])
                ->middleware(['idempotent', 'pin', 'throttle:transactions']);
            Route::post('/payout', [WalletController::class, 'payout'])
                ->middleware(['idempotent', 'pin', 'throttle:transactions']);

            // Contract-exact Monnify funding flow — no `pin` (funding adds
            // money, it doesn't move any out) and no Idempotency-Key header
            // (none of these payloads carry one; SyntheticIdempotencyKey
            // covers duplicate-submission protection instead).
            Route::prefix('monnify')->group(function (): void {
                Route::post('/fund', [WalletFundingController::class, 'fund'])->middleware('throttle:transactions');
                Route::get('/pending-fund', [WalletFundingController::class, 'pendingFund']);
                Route::get('/fund/{id}/requery', [WalletFundingController::class, 'requery'])
                    ->where('id', '[0-9]+')->middleware('throttle:transactions');
                Route::post('/fund/{id}/confirm-payment', [WalletFundingController::class, 'confirmPayment'])
                    ->where('id', '[0-9]+')->middleware('throttle:transactions');
                Route::post('/fund/{id}/retry', [WalletFundingController::class, 'retry'])
                    ->where('id', '[0-9]+')->middleware('throttle:transactions');
                Route::get('/fundings', [WalletFundingController::class, 'fundings']);
                Route::get('/fundings/{id}', [WalletFundingController::class, 'fundingDetail'])->where('id', '[0-9]+');
            });
        });

        Route::prefix('transactions')->group(function (): void {
            Route::get('/', [TransactionController::class, 'index'])->middleware('throttle:transactions');
            Route::get('/{reference}', [TransactionController::class, 'show'])->where('reference', '[A-Za-z0-9_-]+');
            Route::post('/{reference}/verify', [TransactionController::class, 'verify'])
                ->where('reference', '[A-Za-z0-9_-]+')
                ->middleware('throttle:verification');
        });

        Route::prefix('bills')->group(function (): void {
            Route::get('/categories', [BillController::class, 'categories']);
            Route::get('/providers', [BillController::class, 'providers']);
            Route::get('/products', [BillController::class, 'products']);
            Route::get('/kuda/catalog', [BillController::class, 'kudaCatalog'])->middleware('throttle:transactions');
            Route::post('/validate', [BillController::class, 'validate'])->middleware('throttle:transactions');
            Route::post('/pay', [BillController::class, 'pay'])
                ->middleware(['idempotent', 'pin', 'throttle:transactions']);
        });

        Route::get('/user/transactions', [UserTransactionController::class, 'index'])->middleware('throttle:transactions');
        Route::get('/user/transactions/{transId}', [UserTransactionController::class, 'show'])
            ->where('transId', '[A-Za-z0-9_-]+');

        Route::get('/detect-network', [AirtimeController::class, 'detectNetwork']);
        Route::post('/airtime/purchase', [AirtimeController::class, 'purchase'])
            ->middleware(['pin', 'throttle:transactions']);

        Route::get('/detect-data-network', [DataController::class, 'detectNetwork']);
        Route::post('/data/purchase', [DataController::class, 'purchase'])
            ->middleware(['pin', 'throttle:transactions']);

        Route::prefix('electricity')->group(function (): void {
            Route::get('/providers', [ElectricityController::class, 'providers']);
            Route::post('/purchase', [ElectricityController::class, 'purchase'])
                ->middleware(['pin', 'throttle:transactions']);
        });

        Route::prefix('betting')->group(function (): void {
            Route::get('/providers', [BettingController::class, 'providers']);
            Route::post('/validate', [BettingController::class, 'validateCustomer'])->middleware('throttle:transactions');
            Route::post('/fund', [BettingController::class, 'fund'])
                ->middleware(['pin', 'throttle:transactions']);
        });

        Route::prefix('cable')->group(function (): void {
            Route::get('/providers', [CableController::class, 'providers']);
            Route::get('/{slug}/variations', [CableController::class, 'variations']);
        });

        Route::prefix('giftcard')->group(function (): void {
            Route::get('/products', [GiftCardController::class, 'products'])->middleware('throttle:transactions');
            Route::get('/products/{id}', [GiftCardController::class, 'show'])
                ->where('id', '[0-9]+')->middleware('throttle:transactions');
            Route::get('/categories', [GiftCardController::class, 'categories']);
            Route::get('/countries', [GiftCardController::class, 'countries']);
            Route::post('/purchase', [GiftCardController::class, 'purchase'])
                ->middleware(['pin', 'throttle:transactions']);
        });

        Route::prefix('kyc')->group(function (): void {
            Route::get('/', [KycController::class, 'index']);
            Route::post('/bvn', [KycController::class, 'bvn'])->middleware('throttle:auth');
            Route::post('/nin', [KycController::class, 'nin'])->middleware('throttle:auth');
            Route::post('/documents', [KycController::class, 'documents'])->middleware('throttle:auth');
        });

        Route::prefix('notifications')->group(function (): void {
            Route::post('/devices', [NotificationDeviceController::class, 'store'])->middleware('throttle:auth');
            Route::get('/devices', [NotificationDeviceController::class, 'index']);
            Route::delete('/devices/{token}', [NotificationDeviceController::class, 'destroy'])
                ->where('token', '[A-Za-z0-9._-]+');
        });
    });

    // Admin dashboard API — web-session auth + admin role (NOT the sanctum
    // bearer flow: the dashboard is same-origin and uses CSRF).
    Route::prefix('admin')->middleware(['auth', 'admin', 'verified'])->group(function (): void {
        Route::get('/config', [AdminConfigController::class, 'index']);
        Route::put('/config', [AdminConfigController::class, 'update'])->middleware('throttle:auth');
        Route::get('/providers', [AdminConfigController::class, 'providers']);
        Route::post('/push/test', [AdminPushController::class, 'test'])->middleware('throttle:auth');
        Route::get('/push/devices', [AdminPushController::class, 'devices']);
    });

    // Provider webhooks (authenticated by HMAC signature, not user token)
    Route::post('/webhooks/{provider}', [WebhookController::class, 'handle'])
        ->where('provider', '[a-z0-9_]+')
        ->middleware('throttle:webhooks');
});

// The mobile contract puts a handful of endpoints under /v2 while the rest
// of the same feature stays on /v1 (e.g. GET /v1/data/... catalog browsing
// vs GET /v2/data/... variations) — not a versioning scheme we chose, just
// what the contract specifies.
Route::prefix('v2')->middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::prefix('data')->group(function (): void {
        Route::get('/networks', [DataController::class, 'networks']);
        Route::get('/{slug}/variations', [DataController::class, 'variations']);
    });

    Route::post('/electricity/validate', [ElectricityController::class, 'validateMeter'])
        ->middleware('throttle:transactions');

    Route::post('/cable/validate', [CableController::class, 'validateDecoder'])->middleware('throttle:transactions');
    Route::post('/cable/purchase', [CableController::class, 'purchase'])
        ->middleware(['pin', 'throttle:transactions']);
});
