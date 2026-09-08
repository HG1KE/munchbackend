<?php

return [

    'default_eligible_statuses' => [
        'confirmed',
        'processing',
        'picked_up',
        'out_for_delivery',
    ],

    'default_excluded_statuses' => [
        'delivered',
        'canceled',
        'failed',
        'refunded',
        'payment_failed',
    ],

    'chunk_size' => (int) env('ORDER_AUTOMATION_CHUNK_SIZE', 50),

    'schedule_every_minutes' => (int) env('ORDER_AUTOMATION_SCHEDULE_MINUTES', 5),

    'delivery_man_exempt_order_types' => [
        'take_away',
        'dine_in',
        'pos',
    ],

];
