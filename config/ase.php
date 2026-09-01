<?php

/*
|--------------------------------------------------------------------------
| Aṣẹ Platform Configuration
|--------------------------------------------------------------------------
|
| All monetary values in this file are expressed in integer minor units
| (kobo for NGN). Floating point values must never be used for money.
|
*/

return [

    'base_currency' => env('ASE_BASE_CURRENCY', 'NGN'),

    // Minor units per major unit (1 NGN = 100 kobo).
    'minor_units_per_unit' => 100,

    // Prefix for generated financial references (e.g. ASE_T123...).
    'reference_prefix' => env('ASE_REFERENCE_PREFIX', 'ASE'),

    'wallet' => [
        // Reservations older than this are released by the expirer command.
        'reservation_ttl_minutes' => env('ASE_RESERVATION_TTL_MINUTES', 15),
        // Payout reservations live longer: NIP settlement can take hours.
        // If the reservation lapses before the provider confirms, the
        // transaction FAILS (never silently completes) and reconciliation
        // must investigate the provider-side payout.
        'payout_reservation_ttl_hours' => env('ASE_PAYOUT_RESERVATION_TTL_HOURS', 24),
        'expirer_batch_size' => 200,
    ],

    'idempotency' => [
        // A COMPLETED key's stored response is replayed for this long.
        'ttl_days' => 30,
        // An IN_PROGRESS key older than this is presumed abandoned (worker
        // crash, unhandled exception between begin() and complete()) rather
        // than genuinely still in flight — every provider call in this
        // codebase times out well under a minute, so this is a wide safety
        // margin, not a guess at typical latency. Once past it, begin()
        // allows a fresh attempt instead of returning 409 forever.
        'stuck_in_progress_minutes' => 5,
    ],

    'circuit_breaker' => [
        'failure_threshold' => 5,
        'cooldown_seconds' => 60,
        'half_open_max_calls' => 1,
    ],

    'otp' => [
        'length' => 6,
        'ttl_minutes' => 10,
        'max_attempts' => 5,
    ],

    'pin' => [
        'length' => 4,
        'max_attempts' => 5,
        'lockout_minutes' => 15,
    ],

    // Per-transaction and daily limits by KYC tier.
    // Amounts are in kobo (integer minor units).
    'kyc_tiers' => [
        0 => [
            'per_transaction' => 5000000,   // ₦50,000
            'daily_amount' => 10000000,     // ₦100,000
            'daily_count' => 5,
            'max_wallet_balance' => 50000000, // ₦500,000
        ],
        1 => [
            'per_transaction' => 50000000,  // ₦500,000
            'daily_amount' => 100000000,    // ₦1,000,000
            'daily_count' => 20,
            'max_wallet_balance' => 500000000, // ₦5,000,000
        ],
        2 => [
            'per_transaction' => 500000000, // ₦5,000,000
            'daily_amount' => 1000000000,   // ₦10,000,000
            'daily_count' => 100,
            'max_wallet_balance' => 5000000000, // ₦50,000,000
        ],
    ],

    // Transaction fees. bps = basis points of the transaction amount
    // (100 bps = 1%). flat is a fixed fee in kobo. Both may be set.
    'fees' => [
        'airtime' => ['bps' => 0, 'flat' => 500],
        'data' => ['bps' => 100, 'flat' => 0],
        'electricity' => ['bps' => 50, 'flat' => 100],
        'cable_tv' => ['bps' => 100, 'flat' => 0],
        'betting' => ['bps' => 50, 'flat' => 0],
        'bank_transfer' => ['bps' => 100, 'flat' => 100],
        'wallet_funding' => ['bps' => 0, 'flat' => 0],
        // 200 bps (2%) matches the markup implied by the gift-card
        // contract's own pricing example (serviceFee is exactly 2% of
        // baseNgnPrice in both the min and max denomination shown).
        'giftcard' => ['bps' => 200, 'flat' => 0],
    ],

    // Providers known to the platform. Each maps a provider name (as stored
    // in the providers table) to its implementation class.
    'providers' => [
        'mock' => \App\Infrastructure\Providers\MockBillProvider::class,
        'kuda' => \App\Infrastructure\Providers\Kuda\KudaBillProvider::class,
    ],

    'default_bill_provider' => env('ASE_DEFAULT_BILL_PROVIDER', 'mock'),
    'default_funding_provider' => env('ASE_DEFAULT_FUNDING_PROVIDER', 'mock'),

    'payment_providers' => [
        'mock' => \App\Infrastructure\Providers\MockPaymentProvider::class,
        'wema' => \App\Infrastructure\Providers\Wema\WemaPaymentProvider::class,
        'monnify' => \App\Infrastructure\Providers\Monnify\MonnifyPaymentProvider::class,
    ],

    // Payout providers (wallet -> bank transfers). Each maps a provider name
    // to its implementation class.
    'payout_providers' => [
        'mock' => \App\Infrastructure\Providers\MockPayoutProvider::class,
        'wema' => \App\Infrastructure\Providers\Wema\WemaPayoutProvider::class,
        'monnify' => \App\Infrastructure\Providers\Monnify\MonnifyPayoutProvider::class,
    ],

    'default_payout_provider' => env('ASE_DEFAULT_PAYOUT_PROVIDER', 'wema'),

    // Gift card providers. Each maps a provider name to its implementation
    // class (App\Domain\GiftCards\Contracts\GiftCardProviderInterface).
    'giftcard_providers' => [
        'reloadly' => \App\Infrastructure\Providers\Reloadly\ReloadlyGiftCardProvider::class,
    ],

    'default_giftcard_provider' => env('ASE_DEFAULT_GIFTCARD_PROVIDER', 'reloadly'),

    // Webhook shared secrets per provider (used to verify inbound webhook
    // signatures). NOTE: Monnify signs webhooks with its client secret key
    // (HMAC-SHA512, `monnify-signature` header) — configure it via
    // MONNIFY_SECRET_KEY; production only, sandbox notifications are unsigned.
    'webhook_secrets' => [
        'mock' => env('ASE_WEBHOOK_SECRET_MOCK', 'mock-webhook-secret'),
        'wema' => env('ASE_WEBHOOK_SECRET_WEMA', ''),
    ],

    // Wema (ALAT) Developer API.
    // Docs: https://wema-alatdev-apimgt.developer.azure-api.net/
    // Test credentials are issued on portal signup; production base URL and
    // key are issued at onboarding. `webhook` is the PUBLIC URL Wema posts
    // payment-request and payout callbacks to (e.g.
    // https://api.example.com/api/v1/webhooks/wema).
    'wema' => [
        'base_url' => env('WEMA_BASE_URL', 'https://wema-alatdev-apimgt.developer.azure-api.net'),
        'api_key' => env('WEMA_API_KEY', ''),
        'webhook' => env('WEMA_WEBHOOK_URL', ''),
        'timeout_seconds' => 10,
        'connect_timeout_seconds' => 5,
    ],

    // Kuda Business API (bill payments: airtime, data, betting, ...).
    // Docs: https://docs.kuda.com/ + business-support.kuda.com (Business API)
    // UAT base URL for development; production:
    // https://kuda-openapi.kuda.com/v2.1
    // Webhook auth: Kuda sends a plaintext `username` header and a
    // Base64-encoded `password` header — configure both below.
    'kuda' => [
        'base_url' => env('KUDA_BASE_URL', 'https://kuda-openapi-uat.kudabank.com/v2.1'),
        'api_key' => env('KUDA_API_KEY', ''),
        'email' => env('KUDA_BUSINESS_EMAIL', ''),
        'webhook_username' => env('KUDA_WEBHOOK_USERNAME', ''),
        'webhook_password' => env('KUDA_WEBHOOK_PASSWORD', ''),
    ],

    // Monnify API (wallet funding + disbursements/payouts).
    // Docs: https://developers.monnify.com/
    // Sandbox base URL for development; use https://api.monnify.com with
    // live credentials in production. `source_account_number` is the
    // Monnify disbursement wallet account that funds payouts.
    'monnify' => [
        'base_url' => env('MONNIFY_BASE_URL', 'https://sandbox.monnify.com'),
        'api_key' => env('MONNIFY_API_KEY', ''),
        'secret_key' => env('MONNIFY_SECRET_KEY', ''),
        'contract_code' => env('MONNIFY_CONTRACT_CODE', ''),
        'source_account_number' => env('MONNIFY_SOURCE_ACCOUNT', ''),
        'currency' => 'NGN',
    ],

    // Reloadly Gift Cards API. Docs: https://docs.reloadly.com/gift-cards
    // Sandbox base URL for development; production: https://giftcards.reloadly.com.
    // Auth is a separate OAuth token exchange against https://auth.reloadly.com
    // (not the base_url itself) — `audience` on that request must equal
    // whichever base_url below is in effect.
    'reloadly' => [
        'base_url' => env('RELOADLY_BASE_URL', 'https://giftcards-sandbox.reloadly.com'),
        'client_id' => env('RELOADLY_CLIENT_ID', ''),
        'client_secret' => env('RELOADLY_CLIENT_SECRET', ''),
    ],

    // Mock provider behaviour (development and automated tests only).
    // mode: success | failure | timeout
    'mock' => [
        'mode' => env('ASE_MOCK_MODE', 'success'),
        'funding_mode' => env('ASE_MOCK_FUNDING_MODE', 'success'),
        'payout_mode' => env('ASE_MOCK_PAYOUT_MODE', 'success'),
    ],
];
