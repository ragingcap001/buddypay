<?php

/*
|--------------------------------------------------------------------------
| Admin-managed application configuration
|--------------------------------------------------------------------------
|
| The manifest of runtime configuration values that can be overridden from
| the admin dashboard (stored encrypted in the `app_config` table).
|
| Resolution order at runtime:  DB override  ->  environment variable
| ->  default below. Deleting a DB row falls back to the env/default.
|
| `secret` values are masked in API responses (last 4 chars) and rendered
| as password fields in the dashboard.
|
*/

return [

    'groups' => [

        'wema' => [
            'label' => 'Wema (ALAT) — funding & payout rails',
            'keys' => [
                'base_url' => [
                    'label' => 'Base URL',
                    'env' => 'WEMA_BASE_URL',
                    'config' => 'ase.wema.base_url',
                    'default' => 'https://wema-alatdev-apimgt.developer.azure-api.net',
                ],
                'api_key' => [
                    'label' => 'API key (subscription / channel key)',
                    'env' => 'WEMA_API_KEY',
                    'config' => 'ase.wema.api_key',
                    'secret' => true,
                ],
                'webhook' => [
                    'label' => 'Webhook URL (public, e.g. https://api.example.com/api/v1/webhooks/wema)',
                    'env' => 'WEMA_WEBHOOK_URL',
                    'config' => 'ase.wema.webhook',
                ],
                'webhook_secret' => [
                    'label' => 'Webhook HMAC secret',
                    'env' => 'ASE_WEBHOOK_SECRET_WEMA',
                    'config' => 'ase.webhook_secrets.wema',
                    'secret' => true,
                ],
            ],
        ],

        'monnify' => [
            'label' => 'Monnify — funding & payout rails',
            'keys' => [
                'base_url' => [
                    'label' => 'Base URL',
                    'env' => 'MONNIFY_BASE_URL',
                    'config' => 'ase.monnify.base_url',
                    'default' => 'https://sandbox.monnify.com',
                ],
                'api_key' => [
                    'label' => 'API key',
                    'env' => 'MONNIFY_API_KEY',
                    'config' => 'ase.monnify.api_key',
                    'secret' => true,
                ],
                'secret_key' => [
                    'label' => 'Secret key (also signs webhooks)',
                    'env' => 'MONNIFY_SECRET_KEY',
                    'config' => 'ase.monnify.secret_key',
                    'secret' => true,
                ],
                'contract_code' => [
                    'label' => 'Contract code',
                    'env' => 'MONNIFY_CONTRACT_CODE',
                    'config' => 'ase.monnify.contract_code',
                ],
                'source_account' => [
                    'label' => 'Disbursement wallet account (funds payouts)',
                    'env' => 'MONNIFY_SOURCE_ACCOUNT',
                    'config' => 'ase.monnify.source_account_number',
                ],
            ],
        ],

        'firebase' => [
            'label' => 'Firebase (FCM v1 — push delivery for Android AND iOS)',
            'keys' => [
                'project_id' => [
                    'label' => 'Project ID',
                    'env' => 'FIREBASE_PROJECT_ID',
                ],
                'service_account' => [
                    'label' => 'Service account JSON (Firebase console -> Project settings -> Service accounts)',
                    'env' => 'FIREBASE_SERVICE_ACCOUNT',
                    'secret' => true,
                    'multiline' => true,
                ],
            ],
        ],

        'apple' => [
            'label' => 'Apple (iOS) — APNs keys (via FCM relay; direct APNs later)',
            'keys' => [
                'team_id' => [
                    'label' => 'Apple Team ID',
                    'env' => 'APPLE_TEAM_ID',
                ],
                'key_id' => [
                    'label' => 'APNs Key ID',
                    'env' => 'APPLE_KEY_ID',
                ],
                'bundle_id' => [
                    'label' => 'App Bundle ID',
                    'env' => 'APPLE_BUNDLE_ID',
                ],
                'apns_key' => [
                    'label' => 'APNs private key (.p8 contents)',
                    'env' => 'APPLE_APNS_KEY',
                    'secret' => true,
                    'multiline' => true,
                ],
            ],
        ],

        'google' => [
            'label' => 'Google (Android) — FCM',
            'keys' => [
                'sender_id' => [
                    'label' => 'FCM Sender ID (Firebase project number)',
                    'env' => 'GOOGLE_SENDER_ID',
                ],
                'android_package' => [
                    'label' => 'Android package name (optional, for targeting)',
                    'env' => 'GOOGLE_ANDROID_PACKAGE',
                ],
            ],
        ],
    ],
];
