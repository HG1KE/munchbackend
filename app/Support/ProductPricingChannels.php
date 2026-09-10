<?php

namespace App\Support;

/**
 * Admin / POS product price channels.
 *
 * Default lives on `products.price`. Branch POS and marketplace overrides live
 * on `product_channel_prices`. Takeaway, dine-in, and POS Delivery use POS.
 */
class ProductPricingChannels
{
    public const DEFAULT = 'default';

    public const POS = 'pos';

    public const UBER = 'uber';

    public const GLOVO = 'glovo';

    public const BOLT_FOOD = 'bolt_food';

    /**
     * Channels that can store a per-branch override.
     *
     * @return list<string>
     */
    public static function overrideChannels(): array
    {
        return [self::POS, self::UBER, self::GLOVO, self::BOLT_FOOD];
    }

    /**
     * Channels shown in the pricing matrix and bulk editor.
     *
     * @return list<string>
     */
    public static function selectableChannels(): array
    {
        return [self::DEFAULT, self::POS, self::UBER, self::GLOVO, self::BOLT_FOOD];
    }

    public static function isOverrideChannel(?string $channel): bool
    {
        return in_array((string) $channel, self::overrideChannels(), true);
    }

    /**
     * @return list<string>
     */
    public static function marketplaceChannels(): array
    {
        return [self::UBER, self::GLOVO, self::BOLT_FOOD];
    }

    public static function isMarketplace(?string $channel): bool
    {
        return in_array((string) $channel, self::marketplaceChannels(), true);
    }

    public static function isSelectable(?string $channel): bool
    {
        return in_array((string) $channel, self::selectableChannels(), true);
    }

    public static function normalize(?string $channel): string
    {
        $channel = (string) $channel;

        return self::isSelectable($channel) ? $channel : self::POS;
    }

    /**
     * Map a POS UI order type / sales channel onto a pricing channel.
     */
    public static function fromOrderType(?string $orderType): string
    {
        $type = PosOrderTypes::normalize($orderType);

        return match ($type) {
            PosOrderTypes::UBER => self::UBER,
            PosOrderTypes::GLOVO => self::GLOVO,
            PosOrderTypes::BOLT_FOOD => self::BOLT_FOOD,
            default => self::POS,
        };
    }

    public static function fromSalesChannel(?string $salesChannel): string
    {
        return match ((string) $salesChannel) {
            self::UBER => self::UBER,
            self::GLOVO => self::GLOVO,
            self::BOLT_FOOD => self::BOLT_FOOD,
            default => self::POS,
        };
    }

    public static function label(?string $channel): string
    {
        return match ((string) $channel) {
            self::DEFAULT => 'Default',
            self::POS => 'POS',
            self::UBER => 'Uber',
            self::GLOVO => 'Glovo',
            self::BOLT_FOOD => 'Bolt Food',
            default => (string) $channel,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $out = [];
        foreach (self::selectableChannels() as $channel) {
            $out[$channel] = self::label($channel);
        }

        return $out;
    }
}
