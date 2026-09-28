<?php

namespace App\Services\MunchOrdersWebhook;

use App\Model\Order;
use App\Models\MunchOrderWebhookOutbox;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class MunchOrderRingingWebhookRecorder
{
    public function __construct(
        private readonly MunchOrderWebhookPayloadBuilder $payloadBuilder,
    ) {
    }

    /**
     * Persist a durable outbox row inside the order transaction (idempotent).
     */
    public function recordForOrder(Order $order, ?CarbonInterface $occurredAt = null): ?MunchOrderWebhookOutbox
    {
        if (! OnlineOrderRingingEligibility::qualifies($order)) {
            return null;
        }

        return $this->insertIdempotent($order, $occurredAt);
    }

    /**
     * @param  array<string, mixed>  $attributes  Full order row after insert.
     */
    public function recordFromAttributes(array $attributes, ?CarbonInterface $occurredAt = null): ?MunchOrderWebhookOutbox
    {
        if (! OnlineOrderRingingEligibility::qualifiesFromAttributes($attributes)) {
            return null;
        }

        $order = new Order;
        $order->forceFill($attributes);
        $order->exists = true;

        return $this->insertIdempotent($order, $occurredAt);
    }

    private function insertIdempotent(Order $order, ?CarbonInterface $occurredAt): ?MunchOrderWebhookOutbox
    {
        $orderId = (int) $order->id;
        $eventType = MunchOrderWebhookOutbox::EVENT_ORDER_RINGING;
        $eventKey = MunchOrderWebhookOutbox::eventKeyForOrder($orderId);
        $payload = $this->payloadBuilder->build($order, $occurredAt);
        $occurred = isset($payload['occurred_at'])
            ? \Illuminate\Support\Carbon::parse($payload['occurred_at'])
            : now();

        try {
            return MunchOrderWebhookOutbox::query()->create([
                'event_type' => $eventType,
                'order_id' => $orderId,
                'event_key' => $eventKey,
                'payload' => $payload,
                'status' => MunchOrderWebhookOutbox::STATUS_PENDING,
                'occurred_at' => $occurred,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                Log::debug('munch_orders_webhook.outbox_duplicate_skipped', [
                    'event_key' => $eventKey,
                    'order_id' => $orderId,
                ]);

                return MunchOrderWebhookOutbox::query()
                    ->where('event_type', $eventType)
                    ->where('order_id', $orderId)
                    ->first();
            }

            throw $e;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        if ($code === '23000') {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique') || str_contains($message, 'duplicate');
    }
}
