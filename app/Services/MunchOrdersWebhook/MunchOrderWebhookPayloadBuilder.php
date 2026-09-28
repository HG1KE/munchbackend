<?php

namespace App\Services\MunchOrdersWebhook;

use App\Model\Branch;
use App\Model\Order;
use App\Models\MunchOrderWebhookOutbox;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class MunchOrderWebhookPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Order $order, ?CarbonInterface $occurredAt = null): array
    {
        $occurred = $occurredAt ?? $order->placed_at ?? $order->created_at ?? Carbon::now();

        if (! $occurred instanceof CarbonInterface) {
            $occurred = Carbon::parse($occurred);
        }

        $payload = [
            'event' => MunchOrderWebhookOutbox::EVENT_ORDER_RINGING,
            'order_id' => (string) $order->id,
            'branch' => $this->branchLabel($order),
            'occurred_at' => $occurred->toIso8601String(),
        ];

        if ($order->order_status !== null && $order->order_status !== '') {
            $payload['status'] = (string) $order->order_status;
        }

        $channel = trim((string) ($order->sales_channel ?? ''));
        if ($channel === '') {
            $channel = trim((string) ($order->order_type ?? ''));
        }
        if ($channel !== '') {
            $payload['channel'] = $channel;
        }

        $customerName = $this->customerName($order);
        if ($customerName !== '') {
            $payload['customer_name'] = $customerName;
        }

        if ($order->order_amount !== null || $order->delivery_charge !== null) {
            $payload['total'] = round((float) $order->order_amount + (float) $order->delivery_charge, 2);
        }

        return $payload;
    }

    public function branchLabel(Order $order): string
    {
        $branch = $order->relationLoaded('branch')
            ? $order->branch
            : Branch::query()->find($order->branch_id);

        return trim((string) ($branch?->name ?? ''));
    }

    private function customerName(Order $order): string
    {
        if ((int) $order->is_guest === 1) {
            return '';
        }

        $customer = $order->relationLoaded('customer') ? $order->customer : $order->customer()->first();
        if (! $customer) {
            return '';
        }

        return trim(((string) ($customer->f_name ?? '')).' '.((string) ($customer->l_name ?? '')));
    }
}
