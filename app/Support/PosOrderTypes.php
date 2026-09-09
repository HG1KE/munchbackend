<?php

namespace App\Support;

/**
 * Branch POS order-type contract (UI values vs stored orders.order_type).
 *
 * Marketplace channels (Glovo / Uber / Bolt Food) are stored as `pos` so they
 * never appear on the Online Orders board. The channel name is kept in order_note.
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

    public static function orderNote(?string $type): ?string
    {
        return match (self::normalize($type)) {
            self::GLOVO => 'Glovo',
            self::UBER => 'Uber',
            self::BOLT_FOOD => 'Bolt Food',
            default => null,
        };
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
