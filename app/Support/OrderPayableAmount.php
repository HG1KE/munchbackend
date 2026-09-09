<?php

namespace App\Support;

use App\CentralLogics\Helpers;
use App\Model\Order;

class OrderPayableAmount
{
    public static function deliveryCharge(Order|array $order): float
    {
        if ($order instanceof Order) {
            $orderType = (string) ($order->order_type ?? '');

            return $orderType === 'take_away' ? 0.0 : max(0, (float) ($order->delivery_charge ?? 0));
        }

        $orderType = (string) ($order['order_type'] ?? '');

        return $orderType === 'take_away' ? 0.0 : max(0, (float) ($order['delivery_charge'] ?? 0));
    }

    public static function orderAmount(Order|array $order): float
    {
        if ($order instanceof Order) {
            return max(0, (float) ($order->order_amount ?? 0));
        }

        return max(0, (float) ($order['order_amount'] ?? 0));
    }

    public static function payable(Order|array $order): float
    {
        return max(0, round(self::orderAmount($order) + self::deliveryCharge($order), 2));
    }

    /**
     * @return array<string, float|string>
     */
    public static function cardPayload(Order|array $order): array
    {
        $payable = self::payable($order);

        return [
            'order_amount' => self::orderAmount($order),
            'delivery_charge' => self::deliveryCharge($order),
            'payable_amount' => $payable,
            'order_amount_formatted' => Helpers::set_symbol(self::orderAmount($order)),
            'payable_amount_formatted' => Helpers::set_symbol($payable),
        ];
    }
}
