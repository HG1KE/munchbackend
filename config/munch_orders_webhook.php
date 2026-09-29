<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Munch online order ringing webhook (Grok Bot)
    |--------------------------------------------------------------------------
    |
    | Outbound POST when an online order first enters the portal pending queue
    | (same scope as branch "new order" / order_request notifications).
    |
    | MUNCH_ORDERS_WEBHOOK_URL — full HTTPS endpoint URL
    | MUNCH_ORDERS_WEBHOOK_AUTH — value for the Authorization header (e.g. Bearer …)
    |
    */

    'url' => env('MUNCH_ORDERS_WEBHOOK_URL'),

    'authorization' => env('MUNCH_ORDERS_WEBHOOK_AUTH'),

    'timeout_seconds' => (int) env('MUNCH_ORDERS_WEBHOOK_TIMEOUT', 15),

    'connect_timeout_seconds' => (int) env('MUNCH_ORDERS_WEBHOOK_CONNECT_TIMEOUT', 5),

    /*
    | Dedicated async queue — never use the app default QUEUE_CONNECTION=sync for webhooks.
    */
    'queue_connection' => env('MUNCH_ORDERS_WEBHOOK_QUEUE_CONNECTION', 'redis'),

    'queue_name' => env('MUNCH_ORDERS_WEBHOOK_QUEUE', 'munch-webhooks'),

];
