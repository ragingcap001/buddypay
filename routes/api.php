<?php

use App\Http\Controllers\Api\V1\Admin\AdminConfigController;
use App\Http\Controllers\Api\V1\Admin\AdminPushController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\NotificationDeviceController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Health
    Route::get('/health', [HealthController::class, 'show']);

    // Authentication
    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
        Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:auth');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/pin', [AuthController::class, 'setPin'])->middleware('throttle:auth');
            Route::post('/verify-pin', [AuthController::class, 'verifyPin'])->middleware('throttle:auth');
        });
    });

    // Authenticated user endpoints
    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::prefix('wallet')->group(function (): void {
            Route::get('/', [WalletController::class, 'summary']);
            Route::get('/balance', [WalletController::class, 'balance']);
            Route::get('/transactions', [WalletController::class, 'transactions']);
            Route::post('/fund', [WalletController::class, 'fund'])
                ->middleware(['idempotent', 'pin', 'throttle:transactions']);
            Route::post('/payout', [WalletController::class, 'payout'])
                ->middleware(['idempotent', 'pin', 'throttle:transactions']);
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
            Route::post('/validate', [BillController::class, 'validate'])->middleware('throttle:transactions');
            Route::post('/pay', [BillController::class, 'pay'])
                ->middleware(['idempotent', 'pin', 'throttle:transactions']);
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
