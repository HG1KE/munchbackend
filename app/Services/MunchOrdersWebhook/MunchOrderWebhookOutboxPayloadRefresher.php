<?php

namespace App\Services\MunchOrdersWebhook;

use App\Model\Order;
use App\Models\MunchOrderWebhookOutbox;

class MunchOrderWebhookOutboxPayloadRefresher
{
    public function __construct(
        private readonly MunchOrderWebhookPayloadBuilder $payloadBuilder,
    ) {
    }

    /**
     * Rebuild JSON from the authoritative order; keep stored occurred_at when present.
     */
    public function refresh(MunchOrderWebhookOutbox $outbox): MunchOrderWebhookOutbox
    {
        $order = Order::query()->with(['branch', 'customer'])->find($outbox->order_id);
        if (! $order) {
            return $outbox;
        }

        $existing = is_array($outbox->payload) ? $outbox->payload : [];
        $payload = $this->payloadBuilder->build($order);

        if (isset($existing['occurred_at']) && (string) $existing['occurred_at'] !== '') {
            $payload['occurred_at'] = $existing['occurred_at'];
        }

        $outbox->payload = $payload;
        $outbox->save();

        return $outbox->fresh() ?? $outbox;
    }
}
