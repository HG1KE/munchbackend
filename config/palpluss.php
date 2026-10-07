<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PalPluss M-PESA STK Push (non-BYOC)
    |--------------------------------------------------------------------------
    |
    | Authentication is HTTP Basic with the API key as username and an empty
    | password. Do not configure Safaricom Daraja / BYOC credential_id here.
    |
    | Till routing is via PALPLUSS_CHANNEL_ID (payment channel UUID).
    |
    */

    'api_key' => env('PALPLUSS_API_KEY'),

    'base_url' => rtrim((string) env('PALPLUSS_BASE_URL', 'https://api.palpluss.com/v1'), '/'),

    'channel_id' => env('PALPLUSS_CHANNEL_ID'),

    'timeout_seconds' => (int) env('PALPLUSS_TIMEOUT', 30),

    'currency' => 'KES',

    'amount_tolerance_major' => 1.0,

    /*
    | Public webhook path appended to APP_URL when initiating STK.
    */
    'webhook_path' => env('PALPLUSS_WEBHOOK_PATH', '/api/v1/palpluss/webhook'),

    'reconcile_lookback_hours' => (int) env('PALPLUSS_RECONCILE_LOOKBACK_HOURS', 48),

    'reconcile_limit' => (int) env('PALPLUSS_RECONCILE_LIMIT', 50),

];
