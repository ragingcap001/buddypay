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
        'expirer_batch_size' => 200,
    ],

    'idempotency' => [
        // Idempotency keys are retained for this long before cleanup.
        'ttl_days' => 30,
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
    ],

    // Providers known to the platform. Each maps a provider name (as stored
    // in the providers table) to its implementation class.
    'providers' => [
        'mock' => \App\Infrastructure\Providers\MockBillProvider::class,
    ],

    'default_bill_provider' => env('ASE_DEFAULT_BILL_PROVIDER', 'mock'),
    'default_funding_provider' => env('ASE_DEFAULT_FUNDING_PROVIDER', 'mock'),

    'payment_providers' => [
        'mock' => \App\Infrastructure\Providers\MockPaymentProvider::class,
    ],

    // Webhook shared secrets per provider (used by the mock providers to
    // sign/verify webhook payloads).
    'webhook_secrets' => [
        'mock' => env('ASE_WEBHOOK_SECRET_MOCK', 'mock-webhook-secret'),
    ],

    // Mock provider behaviour (development and automated tests only).
    // mode: success | failure | timeout
    'mock' => [
        'mode' => env('ASE_MOCK_MODE', 'success'),
        'funding_mode' => env('ASE_MOCK_FUNDING_MODE', 'success'),
    ],
];
