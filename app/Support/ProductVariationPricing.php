<?php

namespace App\Support;

class ProductVariationPricing
{
    public static function optionKey(string $group, string $label): string
    {
        return rawurlencode($group).'::'.rawurlencode($label);
    }

    /**
     * @return array{group: string, label: string}|null
     */
    public static function parseOptionKey(string $key): ?array
    {
        $parts = explode('::', $key, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return [
            'group' => rawurldecode($parts[0]),
            'label' => rawurldecode($parts[1]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function decodeVariations(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<array{id: string, group: string, label: string, option_price: float, channel_prices: array<string, float>}>
     */
    public static function flatOptions(mixed $variations): array
    {
        $out = [];
        foreach (self::decodeVariations($variations) as $group) {
            if (! is_array($group) || array_key_exists('price', $group)) {
                continue;
            }
            $name = (string) ($group['name'] ?? '');
            foreach (($group['values'] ?? []) as $option) {
                $option = (array) $option;
                $label = (string) ($option['label'] ?? '');
                if ($name === '' || $label === '') {
                    continue;
                }
                $out[] = [
                    'id' => self::optionKey($name, $label),
                    'group' => $name,
                    'label' => $label,
                    'option_price' => (float) ($option['optionPrice'] ?? 0),
                    'channel_prices' => self::channelPricesFromOption($option),
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $option
     * @return array<string, float>
     */
    public static function channelPricesFromOption(array $option): array
    {
        return self::normalizeChannelPrices($option['channelPrices'] ?? $option['channel_prices'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, float>
     */
    public static function channelPricesFromInput(array $value): array
    {
        $raw = $value['channelPrices'] ?? $value['channel_prices'] ?? [];
        if (! is_array($raw)) {
            $raw = [];
        }
        foreach (ProductPricingChannels::marketplaceChannels() as $channel) {
            $camel = $channel === ProductPricingChannels::BOLT_FOOD ? 'boltFoodPrice' : $channel.'Price';
            if (array_key_exists($camel, $value) && ! array_key_exists($channel, $raw)) {
                $raw[$channel] = $value[$camel];
            }
        }

        return self::normalizeChannelPrices($raw);
    }

    /**
     * @return array<string, mixed>
     */
    public static function optionFromInput(array $value): array
    {
        $option = [];
        if (isset($value['label'])) {
            $option['label'] = $value['label'];
        }
        $option['optionPrice'] = $value['optionPrice'] ?? 0;
        $channelPrices = self::channelPricesFromInput($value);
        if ($channelPrices !== []) {
            $option['channelPrices'] = $channelPrices;
        }

        return $option;
    }

    /**
     * Keep a branch's existing optionPrice / marketplace prices when the
     * catalog option still matches by name + label.
     *
     * @param  array<string, mixed>  $catalogValue
     * @param  list<array<string, mixed>>  $branchVariations
     * @return array<string, mixed>
     */
    public static function preserveBranchOption(array $catalogValue, array $branchVariations, string $groupName): array
    {
        $option = [
            'label' => $catalogValue['label'] ?? '',
            'optionPrice' => $catalogValue['optionPrice'] ?? 0,
        ];
        $channelPrices = self::channelPricesFromOption($catalogValue);

        foreach ($branchVariations as $branchVariation) {
            $branchVariation = (array) $branchVariation;
            if ((string) ($branchVariation['name'] ?? '') !== $groupName) {
                continue;
            }
            foreach (($branchVariation['values'] ?? []) as $branchValue) {
                $branchValue = (array) $branchValue;
                if ((string) ($branchValue['label'] ?? '') !== (string) ($catalogValue['label'] ?? '')) {
                    continue;
                }
                $option['optionPrice'] = $branchValue['optionPrice'] ?? $option['optionPrice'];
                $existing = self::channelPricesFromOption($branchValue);
                if ($existing !== []) {
                    $channelPrices = $existing;
                }
            }
        }

        if ($channelPrices !== []) {
            $option['channelPrices'] = $channelPrices;
        }

        return $option;
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
            $value = $raw[$channel];
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_numeric($value)) {
                continue;
            }
            $amount = round((float) $value, 2);
            if ($amount < 0) {
                continue;
            }
            $out[$channel] = $amount;
        }

        return $out;
    }

    public static function optionChannelPrice(array $option, string $channel): ?float
    {
        $prices = self::channelPricesFromOption($option);

        return $prices[$channel] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $variations
     * @return list<array<string, mixed>>
     */
    public static function setOptionChannelPrice(array $variations, string $optionId, string $channel, ?float $price, bool $mustExist = true): array
    {
        $parsed = self::parseOptionKey($optionId);
        if ($parsed === null || ! ProductPricingChannels::isMarketplace($channel)) {
            throw new \InvalidArgumentException('Invalid variation');
        }

        $found = false;
        foreach ($variations as $groupIndex => $group) {
            $group = (array) $group;
            if ((string) ($group['name'] ?? '') !== $parsed['group']) {
                continue;
            }
            $values = $group['values'] ?? [];
            foreach ($values as $valueIndex => $option) {
                $option = (array) $option;
                if ((string) ($option['label'] ?? '') !== $parsed['label']) {
                    continue;
                }
                $found = true;
                $prices = self::channelPricesFromOption($option);
                if ($price === null) {
                    unset($prices[$channel]);
                } else {
                    $prices[$channel] = round(max(0, $price), 2);
                }
                unset($option['channel_prices']);
                if ($prices === []) {
                    unset($option['channelPrices']);
                } else {
                    $option['channelPrices'] = $prices;
                }
                $values[$valueIndex] = $option;
            }
            $group['values'] = array_values($values);
            $variations[$groupIndex] = $group;
        }

        if (! $found && $mustExist) {
            throw new \InvalidArgumentException('Variation does not belong to this product');
        }

        return array_values($variations);
    }

    public static function effectivePrice(float $productChannelPrice, float $optionPrice, ?float $override): float
    {
        if ($override !== null) {
            return round($override, 2);
        }

        return round($productChannelPrice + $optionPrice, 2);
    }

    public static function extraForChannel(float $productChannelPrice, float $optionPrice, ?float $override): float
    {
        if ($override !== null) {
            return round($override - $productChannelPrice, 2);
        }

        return round($optionPrice, 2);
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
            return [null, 'Enter a valid variation price'];
        }
        $amount = round((float) $value, 2);
        if ($amount < 0) {
            return [null, 'Variation prices cannot be negative'];
        }
        if ($amount <= 0) {
            return [null, 'Enter a valid variation price'];
        }

        return [$amount, null];
    }
}
