<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PalPluss M-PESA STK Push (non-BYOC)
    |--------------------------------------------------------------------------
    |
    | Runtime credentials (API key + channelId) come from Admin Payment Settings
    | (addon_settings key_name=palpluss), encrypted at rest.
    |
    | PALPLUSS_API_KEY / PALPLUSS_CHANNEL_ID remain optional legacy fallbacks
    | only when Admin values are empty. Prefer Admin configuration.
    |
    | Authentication is HTTP Basic with the API key as username and an empty
    | password. Do not configure Safaricom Daraja / BYOC credential_id.
    |
    */

    'api_key' => env('PALPLUSS_API_KEY'),

    'base_url' => rtrim((string) env('PALPLUSS_BASE_URL', 'https://api.palpluss.com/v1'), '/'),

    'channel_id' => env('PALPLUSS_CHANNEL_ID'),

    'timeout_seconds' => (int) env('PALPLUSS_TIMEOUT', 30),

    'currency' => 'KES',

    'amount_tolerance_major' => 1.0,

    'webhook_path' => env('PALPLUSS_WEBHOOK_PATH', '/api/v1/palpluss/webhook'),

    'reconcile_lookback_hours' => (int) env('PALPLUSS_RECONCILE_LOOKBACK_HOURS', 48),

    'reconcile_limit' => (int) env('PALPLUSS_RECONCILE_LIMIT', 50),

];
