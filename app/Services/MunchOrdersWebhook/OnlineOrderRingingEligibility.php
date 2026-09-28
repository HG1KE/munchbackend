<?php

namespace App\Services\MunchOrdersWebhook;

use App\Model\Order;
use App\Support\OnlineOrderStatus;
use App\Support\PosOrderTypes;
use Carbon\Carbon;

/**
 * Same scope as DashboardOrderOperationsService::pendingQueueQuery() /
 * branch new-order alert (onlineOrders + notSchedule + pending|confirmed).
 */
class OnlineOrderRingingEligibility
{
    public static function qualifies(Order $order): bool
    {
        if (! PosOrderTypes::isOnlineOrder($order->order_type ?? null, $order->sales_channel ?? null)) {
            return false;
        }

        if (! in_array((string) $order->order_status, OnlineOrderStatus::pendingQueueStatuses(), true)) {
            return false;
        }

        return self::isNotScheduled($order);
    }

    /**
     * @param  array<string, mixed>  $attributes  Order row attributes (pre-persist or persisted).
     */
    public static function qualifiesFromAttributes(array $attributes): bool
    {
        $orderType = $attributes['order_type'] ?? null;
        $salesChannel = $attributes['sales_channel'] ?? null;

        if (! PosOrderTypes::isOnlineOrder($orderType, $salesChannel)) {
            return false;
        }

        $status = (string) ($attributes['order_status'] ?? '');
        if (! in_array($status, OnlineOrderStatus::pendingQueueStatuses(), true)) {
            return false;
        }

        $deliveryDate = $attributes['delivery_date'] ?? null;
        if ($deliveryDate === null || $deliveryDate === '') {
            return true;
        }

        return Carbon::parse((string) $deliveryDate)->format('Y-m-d')
            <= Carbon::now()->format('Y-m-d');
    }

    public static function isNotScheduled(Order $order): bool
    {
        if ($order->delivery_date === null || $order->delivery_date === '') {
            return true;
        }

        return Carbon::parse((string) $order->delivery_date)->format('Y-m-d')
            <= Carbon::now()->format('Y-m-d');
    }
}
