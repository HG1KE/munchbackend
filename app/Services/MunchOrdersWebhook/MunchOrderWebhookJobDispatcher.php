<?php

namespace App\Services\MunchOrdersWebhook;

use App\Jobs\DeliverMunchOrderWebhookJob;

/**
 * Webhook delivery always uses the dedicated async queue (never QUEUE_CONNECTION=sync).
 */
class MunchOrderWebhookJobDispatcher
{
    public static function dispatch(int $outboxId): void
    {
        $connection = (string) config('munch_orders_webhook.queue_connection', 'redis');
        $queue = (string) config('munch_orders_webhook.queue_name', 'munch-webhooks');

        DeliverMunchOrderWebhookJob::dispatch($outboxId)
            ->onConnection($connection)
            ->onQueue($queue);
    }
}
