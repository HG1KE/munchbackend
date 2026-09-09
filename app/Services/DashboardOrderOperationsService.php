<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Model\Order;
use App\Support\OnlineOrderStatus;
use App\Support\OrderDispatchedTime;
use App\Support\OrderPayableAmount;
use App\Support\OrderPlacementTime;
use App\Support\PaymentMethodLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DashboardOrderOperationsService
{
    public function baseQuery(?int $branchId = null): Builder
    {
        $query = Order::query()
            ->notPos()
            ->notDineIn()
            ->notSchedule();

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    public function onlineActiveCount(?int $branchId = null): int
    {
        return $this->baseQuery($branchId)
            ->whereNotIn('order_status', OnlineOrderStatus::terminalStatuses())
            ->count();
    }

    /**
     * @return array{online: int, express: int}
     */
    public function dashboardCounts(?int $branchId = null): array
    {
        $count = $this->onlineActiveCount($branchId);

        return [
            'online' => $count,
            'express' => $count,
        ];
    }

    /**
     * @return Collection<int, Order>
     */
    public function expressPendingQueue(?int $branchId = null): Collection
    {
        return $this->statusQueue($branchId, OnlineOrderStatus::pendingQueueStatuses());
    }

    /**
     * @return Collection<int, Order>
     */
    public function expressPackingQueue(?int $branchId = null): Collection
    {
        return $this->statusQueue($branchId, OnlineOrderStatus::packingQueueStatuses());
    }

    /**
     * @return Collection<int, Order>
     */
    public function expressDispatchedQueue(?int $branchId = null): Collection
    {
        $query = $this->baseQuery($branchId)
            ->whereIn('order_status', OnlineOrderStatus::dispatchedQueueStatuses())
            ->with($this->cardRelations())
            ->when(
                OrderDispatchedTime::hasDispatchedAtColumn(),
                fn ($q) => $q->orderBy('dispatched_at')
            )
            ->orderBy('created_at');

        return $query->get();
    }

    /**
     * @param  list<string>  $statuses
     * @return Collection<int, Order>
     */
    private function statusQueue(?int $branchId, array $statuses): Collection
    {
        return $this->baseQuery($branchId)
            ->whereIn('order_status', $statuses)
            ->with($this->cardRelations())
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return list<string>
     */
    private function cardRelations(): array
    {
        return ['customer', 'guest', 'branch', 'order_area.area', 'details', 'delivery_man'];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function mapExpressOrderCards(Collection $orders, string $section = 'pending'): Collection
    {
        return $orders->map(function (Order $order) use ($section) {
            $card = $this->formatOrderCard($order);
            $timer = $section === 'dispatched'
                ? OrderPlacementTime::expressFrozenDispatchTimerPayload($order)
                : OrderPlacementTime::expressTimerPayload($order);

            return array_merge($card, $timer);
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatOrderCard(Order $order): array
    {
        $address = $this->resolveAddress($order);
        $timer = OrderPlacementTime::expressTimerPayload($order);
        $amounts = OrderPayableAmount::cardPayload($order);
        $orderType = (string) ($order->order_type ?? 'delivery');
        $itemLines = $this->itemLines($order);
        $itemCount = (int) ($order->relationLoaded('details')
            ? $order->details->sum(fn ($detail) => (int) ($detail->quantity ?? 1))
            : count($itemLines));
        $riderName = null;
        if ($order->relationLoaded('delivery_man') && $order->delivery_man) {
            $riderName = trim(($order->delivery_man->f_name ?? '').' '.($order->delivery_man->l_name ?? ''));
        }

        return [
            'id' => $order->id,
            'route_key' => $order->id,
            'readable_order_id' => $order->readable_order_id,
            'order_display_id' => Helpers::order_display_id($order),
            'customer_name' => $this->customerName($order, $address),
            'membership_type' => null,
            'area' => $this->areaLabel($order, $address),
            'branch_name' => $order->branch?->name ?? translate('N/A'),
            'item_count' => $itemCount,
            'item_lines' => $itemLines,
            'rider_name' => $riderName !== '' ? $riderName : null,
            'order_type' => $orderType,
            ...$amounts,
            'order_status' => $order->order_status,
            'order_status_label' => translate(OnlineOrderStatus::labelKey($order->order_status)),
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'payment_method_label' => PaymentMethodLabel::operationalTitle($order->payment_method),
            'fulfillment_label' => $orderType === 'take_away' ? translate('PICKUP') : translate('DELIVERY'),
            'created_at_iso' => $timer['created_at_iso'],
            'placed_at_iso' => $timer['placed_at_iso'],
            'placed_at_unix' => $timer['placed_at_unix'],
            'elapsed_seconds' => $timer['elapsed_seconds'],
            'sla_tier' => $timer['sla_tier'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveAddress(Order $order): ?array
    {
        $raw = $order->delivery_address;
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private function customerName(Order $order, ?array $address): string
    {
        if ($order->is_guest && $order->guest) {
            return trim(($order->guest->f_name ?? '').' '.($order->guest->l_name ?? '')) ?: translate('Guest User');
        }

        if ($order->customer) {
            return trim(($order->customer->f_name ?? '').' '.($order->customer->l_name ?? '')) ?: translate('Customer');
        }

        if ($address) {
            return (string) ($address['contact_person_name'] ?? translate('Customer'));
        }

        return translate('Customer');
    }

    /**
     * @return list<string>
     */
    private function itemLines(Order $order): array
    {
        if (! $order->relationLoaded('details')) {
            return [];
        }

        $lines = [];
        foreach ($order->details as $detail) {
            $name = $this->detailProductName($detail);
            if ($name === null || $name === '') {
                continue;
            }

            $qty = (int) ($detail->quantity ?? 1);
            $lines[] = $qty > 1 ? $name.' ×'.$qty : $name;
        }

        return $lines;
    }

    private function detailProductName(mixed $detail): ?string
    {
        $raw = $detail->product_details ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && ! empty($decoded['name'])) {
                return (string) $decoded['name'];
            }
        } elseif (is_array($raw) && ! empty($raw['name'])) {
            return (string) $raw['name'];
        } elseif (is_object($raw) && ! empty($raw->name)) {
            return (string) $raw->name;
        }

        if (isset($detail->product) && $detail->product && ! empty($detail->product->name)) {
            return (string) $detail->product->name;
        }

        return null;
    }

    private function areaLabel(Order $order, ?array $address): string
    {
        $areaName = $order->order_area?->area?->area_name;
        if ($areaName) {
            return (string) $areaName;
        }

        if ($address) {
            return (string) ($address['address'] ?? $address['road'] ?? $address['address_type'] ?? '—');
        }

        return '—';
    }
}
