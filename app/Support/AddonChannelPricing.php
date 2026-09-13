<?php

namespace App\Support;

use App\Model\AddOn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddonChannelPricing
{
    /**
     * @param  list<int>  $addonIds
     * @return array<int, array<string, float>>
     */
    public static function mapForIds(array $addonIds): array
    {
        $addonIds = array_values(array_unique(array_filter(array_map('intval', $addonIds))));
        if ($addonIds === [] || ! Schema::hasTable('addon_channel_prices')) {
            return [];
        }

        $out = [];
        $rows = DB::table('addon_channel_prices')
            ->whereIn('addon_id', $addonIds)
            ->get(['addon_id', 'channel', 'price']);
        foreach ($rows as $row) {
            $channel = (string) $row->channel;
            if (! ProductPricingChannels::isMarketplace($channel)) {
                continue;
            }
            if (! is_numeric($row->price)) {
                continue;
            }
            $amount = round((float) $row->price, 2);
            if ($amount <= 0) {
                continue;
            }
            $out[(int) $row->addon_id][$channel] = $amount;
        }

        return $out;
    }

    /**
     * @param  mixed  $raw
     * @return array<string, float>
     */
    public static function normalizeChannelPrices(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach (ProductPricingChannels::marketplaceChannels() as $channel) {
            if (! array_key_exists($channel, $raw)) {
                continue;
            }
            [$amount, $error] = self::parseSubmittedPrice($raw[$channel]);
            if ($error !== null || $amount === null) {
                continue;
            }
            $out[$channel] = $amount;
        }

        return $out;
    }

    public static function resolve(float $masterPrice, ?float $override): float
    {
        if ($override !== null) {
            return round($override, 2);
        }

        return round($masterPrice, 2);
    }

    /**
     * @param  list<AddOn|object>  $addons
     * @return array<int, float>
     */
    public static function unitPrices(array $addons, ?string $orderType): array
    {
        $ids = [];
        foreach ($addons as $addon) {
            $id = (int) ($addon->id ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $map = self::mapForIds($ids);
        $channel = ProductPricingChannels::fromOrderType($orderType);
        $out = [];
        foreach ($addons as $addon) {
            $id = (int) ($addon->id ?? 0);
            if ($id < 1) {
                continue;
            }
            $master = (float) ($addon->price ?? 0);
            $override = ProductPricingChannels::isMarketplace($channel)
                ? ($map[$id][$channel] ?? null)
                : null;
            $out[$id] = self::resolve($master, $override);
        }

        return $out;
    }

    /**
     * @return array{0: ?float, 1: ?string}  [amount, error]
     */
    public static function parseSubmittedPrice(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }
        if (is_string($value) && trim($value) === '') {
            return [null, null];
        }
        if (! is_numeric($value)) {
            return [null, 'Enter a valid addon price'];
        }
        $amount = round((float) $value, 2);
        if ($amount < 0) {
            return [null, 'Addon prices cannot be negative'];
        }
        if ($amount <= 0) {
            return [null, 'Enter a valid addon price'];
        }

        return [$amount, null];
    }

    /**
     * @param  mixed  $raw
     * @param  list<mixed>  $addonIds
     */
    public static function validateProductForm(mixed $raw, array $addonIds): ?string
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $allowed = array_values(array_unique(array_filter(array_map('intval', $addonIds))));
        foreach ($raw as $addonId => $channels) {
            $addonId = (int) $addonId;
            if ($addonId < 1 || ! in_array($addonId, $allowed, true)) {
                continue;
            }
            if (! is_array($channels)) {
                continue;
            }
            foreach ($channels as $channel => $value) {
                $channel = (string) $channel;
                if (! ProductPricingChannels::isMarketplace($channel)) {
                    continue;
                }
                [, $error] = self::parseSubmittedPrice($value);
                if ($error !== null) {
                    return $error;
                }
            }
        }

        return null;
    }

    /**
     * Persist only submitted, non-empty marketplace prices for the selected addons.
     *
     * @param  mixed  $raw
     * @param  list<mixed>  $addonIds
     */
    public static function saveProductForm(mixed $raw, array $addonIds): int
    {
        if (! is_array($raw) || $raw === [] || ! Schema::hasTable('addon_channel_prices')) {
            return 0;
        }

        $allowed = array_values(array_unique(array_filter(array_map('intval', $addonIds))));
        if ($allowed === []) {
            return 0;
        }

        $saved = 0;
        foreach ($raw as $addonId => $channels) {
            $addonId = (int) $addonId;
            if ($addonId < 1 || ! in_array($addonId, $allowed, true) || ! is_array($channels)) {
                continue;
            }
            foreach ($channels as $channel => $value) {
                $channel = (string) $channel;
                if (! ProductPricingChannels::isMarketplace($channel)) {
                    continue;
                }
                [$amount, $error] = self::parseSubmittedPrice($value);
                if ($error !== null || $amount === null) {
                    continue;
                }
                self::upsert($addonId, $channel, $amount);
                $saved++;
            }
        }

        return $saved;
    }

    /**
     * @param  list<array{addon_id: int, channel: string, value: float}>  $values
     */
    public static function upsertMany(array $values): int
    {
        if ($values === [] || ! Schema::hasTable('addon_channel_prices')) {
            return 0;
        }

        $now = now();
        $rows = [];
        foreach ($values as $value) {
            $addonId = (int) ($value['addon_id'] ?? 0);
            $channel = (string) ($value['channel'] ?? '');
            $amount = $value['value'] ?? null;
            if ($addonId < 1 || ! ProductPricingChannels::isMarketplace($channel) || ! is_numeric($amount)) {
                continue;
            }
            $price = round((float) $amount, 2);
            if ($price <= 0) {
                continue;
            }
            $rows[$addonId.'|'.$channel] = [
                'addon_id' => $addonId,
                'channel' => $channel,
                'price' => $price,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows === []) {
            return 0;
        }

        DB::table('addon_channel_prices')->upsert(
            array_values($rows),
            ['addon_id', 'channel'],
            ['price', 'updated_at']
        );

        return count($rows);
    }

    public static function upsert(int $addonId, string $channel, float $price): void
    {
        self::upsertMany([[
            'addon_id' => $addonId,
            'channel' => $channel,
            'value' => $price,
        ]]);
    }
}
