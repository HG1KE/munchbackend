<?php

namespace App\Console\Commands;

use App\Jobs\DeliverMunchOrderWebhookJob;
use App\Models\MunchOrderWebhookOutbox;
use App\Services\MunchOrdersWebhook\MunchOrderWebhookOutboxPayloadRefresher;
use Illuminate\Console\Command;

class MunchOrdersWebhookRetryCommand extends Command
{
    protected $signature = 'munch:orders-webhook-retry
                            {--order= : Portal order id}
                            {--outbox= : Outbox row id}';

    protected $description = 'Re-queue delivery for an existing order.ringing outbox row (no new outbox row)';

    public function handle(MunchOrderWebhookOutboxPayloadRefresher $payloadRefresher): int
    {
        $outbox = $this->resolveOutbox();
        if (! $outbox) {
            return self::FAILURE;
        }

        if ($outbox->status === MunchOrderWebhookOutbox::STATUS_DELIVERED) {
            $this->info("Outbox {$outbox->id} is already delivered.");

            return self::SUCCESS;
        }

        $payloadRefresher->refresh($outbox);

        $outbox->status = MunchOrderWebhookOutbox::STATUS_PENDING;
        $outbox->last_error = null;
        $outbox->save();

        DeliverMunchOrderWebhookJob::dispatch((int) $outbox->id);
        $this->info("Queued DeliverMunchOrderWebhookJob for outbox {$outbox->id} (order {$outbox->order_id}).");

        return self::SUCCESS;
    }

    private function resolveOutbox(): ?MunchOrderWebhookOutbox
    {
        $outboxId = (int) $this->option('outbox');
        if ($outboxId > 0) {
            $outbox = MunchOrderWebhookOutbox::query()->find($outboxId);
            if (! $outbox) {
                $this->error("Outbox id {$outboxId} not found.");
            }

            return $outbox;
        }

        $orderId = (int) $this->option('order');
        if ($orderId < 1) {
            $this->error('Provide --order=<id> or --outbox=<id>.');

            return null;
        }

        $outbox = MunchOrderWebhookOutbox::query()
            ->where('event_type', MunchOrderWebhookOutbox::EVENT_ORDER_RINGING)
            ->where('order_id', $orderId)
            ->first();

        if (! $outbox) {
            $this->error("No order.ringing outbox row for order {$orderId}.");

            return null;
        }

        return $outbox;
    }
}
