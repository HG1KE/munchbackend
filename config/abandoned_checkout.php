<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Send via queue
    |--------------------------------------------------------------------------
    |
    | When false (recommended on shared hosting), the scheduler command sends SMS
    | inline during the same cron run — no separate queue worker required.
    | When true, jobs go to the sms-recovery queue; ensure a worker processes it:
    |   php artisan queue:work --queue=sms-recovery,default
    |
    */
    'send_via_queue' => env('ABANDONED_CHECKOUT_SEND_VIA_QUEUE', false),

    /*
    | Drain sms-recovery after dispatch (only when send_via_queue is true).
    | Runs queue:work --stop-when-empty for up to N seconds inside the scheduler.
    */
    'drain_queue_seconds' => (int) env('ABANDONED_CHECKOUT_DRAIN_QUEUE_SECONDS', 50),

    'reaper_batch_size' => (int) env('ABANDONED_CHECKOUT_REAPER_BATCH', 50),

    /** Re-claim rows stuck in "queued" state with no send after this many minutes. */
    'stale_claim_minutes' => (int) env('ABANDONED_CHECKOUT_STALE_CLAIM_MINUTES', 60),

    /** Run dispatch command every minute (requires cron * * * * * schedule:run). */
    'schedule_every_minute' => env('ABANDONED_CHECKOUT_SCHEDULE_EVERY_MINUTE', true),

    'schedule_on_one_server' => env('ABANDONED_CHECKOUT_SCHEDULE_ON_ONE_SERVER', false),

];
