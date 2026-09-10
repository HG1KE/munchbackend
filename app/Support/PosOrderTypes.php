<?php

namespace App\Support;

/**
 * Branch POS order-type contract (UI values vs stored orders.order_type).
 *
 * Marketplace channels (Glovo / Uber / Bolt Food) are stored as `pos` so they
 * never appear on the Online Orders board. The cashier-facing channel is stored
 * in `sales_channel`, never in `order_note`.
 */
class PosOrderTypes
{
    public const TAKE_AWAY = 'take_away';

    public const DINE_IN = 'dine_in';

    public const DELIVERY = 'delivery';

    public const HOME_DELIVERY = 'home_delivery';

    public const GLOVO = 'glovo';

    public const UBER = 'uber';

    public const BOLT_FOOD = 'bolt_food';

    /**
     * @return list<string>
     */
    public static function uiTypes(): array
    {
        return [
            self::DELIVERY,
            self::TAKE_AWAY,
            self::DINE_IN,
            self::GLOVO,
            self::UBER,
            self::BOLT_FOOD,
        ];
    }

    public static function normalize(?string $type): string
    {
        $type = (string) $type;
        if ($type === self::HOME_DELIVERY) {
            return self::DELIVERY;
        }
        if (in_array($type, self::uiTypes(), true)) {
            return $type;
        }

        return self::TAKE_AWAY;
    }

    public static function isDelivery(?string $type): bool
    {
        $type = self::normalize($type);

        return $type === self::DELIVERY;
    }

    public static function isDineIn(?string $type): bool
    {
        return self::normalize($type) === self::DINE_IN;
    }

    public static function isMarketplace(?string $type): bool
    {
        return in_array(self::normalize($type), [self::GLOVO, self::UBER, self::BOLT_FOOD], true);
    }

    public static function allowsManualDiscount(?string $type): bool
    {
        return in_array(self::normalize($type), [self::DELIVERY, self::TAKE_AWAY, self::DINE_IN], true);
    }

    /**
     * Stored `orders.order_type`. Marketplace channels stay `pos` so Online Orders
     * (`notPos()` / `notDineIn()`) never picks them up.
     */
    public static function databaseType(?string $type): string
    {
        $type = self::normalize($type);

        return match ($type) {
            self::DINE_IN => 'dine_in',
            self::DELIVERY => 'delivery',
            default => 'pos',
        };
    }

    /**
     * Dedicated POS sales channel. Independent of `order_type` and `order_note`.
     *
     * @return 'pos'|'delivery'|'takeaway'|'dine_in'|'glovo'|'uber'|'bolt_food'
     */
    public static function salesChannel(?string $type): string
    {
        return match (self::normalize($type)) {
            self::DELIVERY => 'delivery',
            self::TAKE_AWAY => 'takeaway',
            self::DINE_IN => 'dine_in',
            self::GLOVO => 'glovo',
            self::UBER => 'uber',
            self::BOLT_FOOD => 'bolt_food',
            default => 'pos',
        };
    }

    /**
     * @return list<string>
     */
    public static function salesChannels(): array
    {
        return ['pos', 'delivery', 'takeaway', 'dine_in', 'glovo', 'uber', 'bolt_food'];
    }

    public static function channelLabel(?string $salesChannel, ?string $orderType = null): string
    {
        return match ($salesChannel ?: '') {
            'pos' => 'POS',
            'delivery' => 'Delivery',
            'takeaway' => 'Take Away',
            'dine_in' => 'Dine In',
            'glovo' => 'Glovo',
            'uber' => 'Uber',
            'bolt_food' => 'Bolt Food',
            default => match ($orderType ?: '') {
                'pos' => 'POS',
                'dine_in' => 'Dine In',
                'delivery' => 'Delivery',
                'take_away' => 'Take Away',
                default => $orderType ? (string) $orderType : 'POS',
            },
        };
    }

    /**
     * Cashier-facing POS payment methods for a UI order type.
     *
     * @return list<string>
     */
    public static function paymentMethods(?string $type, bool $posMpesaEnabled = true): array
    {
        return match (self::normalize($type)) {
            self::DELIVERY => ['cash_on_delivery'],
            self::TAKE_AWAY, self::DINE_IN => $posMpesaEnabled
                ? ['cash', 'card', 'mpesa']
                : ['cash', 'card'],
            default => ['cash', 'card'],
        };
    }

    /**
     * Required POS Delivery fields. Other order types skip this validation.
     *
     * @param  array{customer_name?: mixed, customer_phone?: mixed, address?: mixed, rider_name?: mixed, rider_phone?: mixed}  $fields
     */
    public static function posDeliveryFieldError(?string $type, array $fields): ?string
    {
        if (! self::isDelivery($type)) {
            return null;
        }

        if (trim((string) ($fields['customer_name'] ?? '')) === '') {
            return 'Customer Name';
        }
        if (trim((string) ($fields['customer_phone'] ?? '')) === '') {
            return 'Customer Phone';
        }
        if (trim((string) ($fields['address'] ?? '')) === '') {
            return 'Delivery Address';
        }
        if (trim((string) ($fields['rider_name'] ?? '')) === '') {
            return 'Rider Name';
        }
        if (trim((string) ($fields['rider_phone'] ?? '')) === '') {
            return 'Rider Phone';
        }

        return null;
    }

    public static function isPaidImmediately(?string $type, ?string $paymentMethod): bool
    {
        $type = self::normalize($type);
        if ($type === self::DELIVERY) {
            return false;
        }
        if ($type === self::DINE_IN && $paymentMethod === 'pay_after_eating') {
            return false;
        }

        return true;
    }

    public static function defaultStatus(?string $type): string
    {
        $type = self::normalize($type);
        if ($type === self::TAKE_AWAY || self::isMarketplace($type)) {
            return 'delivered';
        }

        return 'confirmed';
    }

    public static function showsDeliveryCharge(?string $type): bool
    {
        return self::isDelivery($type);
    }
}
