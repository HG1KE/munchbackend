<?php

namespace App\Services;

use App\Model\Product;
use App\Model\ProductByBranch;
use App\Support\ProductPricingChannels;
use App\Support\ProductVariationPricing;
use Illuminate\Support\Facades\DB;

class ProductBulkPricingService
{
    public const MAX_ROWS = 8000;

    public const ACTIONS = [
        'increase_percent',
        'decrease_percent',
        'increase_amount',
        'decrease_amount',
        'set_exact',
        'round_5',
        'round_10',
    ];

    public const AVAILABILITY_ACTIONS = [
        'enable_pos',
        'disable_pos',
        'enable_uber',
        'disable_uber',
        'enable_glovo',
        'disable_glovo',
        'enable_bolt_food',
        'disable_bolt_food',
    ];

    public function __construct(
        private ProductChannelPricingService $pricing,
    ) {
    }

    /**
     * Effective selling prices for the current bulk selection. Always returned,
     * even when the price is not changing, so the modal never shows a stale figure.
     *
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @param  list<string>  $channels
     * @return array{rows: list<array<string, mixed>>, count: int, truncated: bool}
     */
    public function currentPrices(array $productIds, array $branchIds, array $channels): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds))));
        $channels = ProductPricingChannels::filterOverrideChannels($channels);
        if ($productIds === []) {
            return ['rows' => [], 'count' => 0, 'truncated' => false];
        }

        if ($channels === [] || $branchIds === []) {
            $rows = [];
            $products = Product::query()->whereIn('id', $productIds)->orderBy('name')->get();
            foreach ($products as $product) {
                $rows[] = [
                    'product_id' => (int) $product->id,
                    'product_name' => (string) $product->name,
                    'branch_id' => 0,
                    'branch_name' => '',
                    'channel' => $channels[0] ?? 'default',
                    'current_price' => $this->pricing->defaultSellingPrice($product),
                ];
            }

            return $this->truncate($rows);
        }

        $pairsByChannel = $this->resolvedPairsByChannels($productIds, $branchIds, $channels);
        $rows = [];
        foreach ($channels as $channel) {
            foreach ($pairsByChannel[$channel] ?? [] as $pair) {
                $rows[] = [
                    'product_id' => $pair['product_id'],
                    'product_name' => $pair['product_name'],
                    'branch_id' => $pair['branch_id'],
                    'branch_name' => $pair['branch_name'],
                    'channel' => $channel,
                    'current_price' => $pair['price'],
                ];
            }
        }

        return $this->truncate(array_merge($rows, $this->variationCurrentRows($productIds, $branchIds, $channels, $pairsByChannel)));
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @param  list<array<string, mixed>>  $operations
     * @param  array<int|string, mixed>  $productValues
     * @param  list<array<string, mixed>>  $variationValues
     * @return array{rows: list<array<string, mixed>>, count: int, truncated: bool, error?: string}
     */
    public function previewPrices(array $productIds, array $branchIds, array $operations, array $productValues = [], array $variationValues = []): array
    {
        $operations = $this->normalizePriceOperations($operations);
        $productValues = $this->normalizeProductValues($productValues);
        $variation = $this->normalizeVariationValues($variationValues, $productIds, $branchIds);
        if (! empty($variation['error'])) {
            return ['rows' => [], 'count' => 0, 'truncated' => false, 'error' => $variation['error']];
        }
        if ($productValues === [] && $variation['values'] !== []) {
            $operations = array_values(array_filter(
                $operations,
                fn ($op) => ! ($op['action'] === 'set_exact' && $op['value'] <= 0)
            ));
        }
        if ($operations === [] && $variation['values'] === []) {
            return ['rows' => [], 'count' => 0, 'truncated' => false, 'error' => 'Select at least one channel'];
        }

        $needsBranches = $this->operationsNeedBranches($operations) || $variation['values'] !== [];
        if ($needsBranches && $branchIds === []) {
            return ['rows' => [], 'count' => 0, 'truncated' => false, 'error' => 'Choose branches first'];
        }

        $rows = [];
        if ($operations !== []) {
            $channels = array_values(array_unique(array_column($operations, 'channel')));
            $pairsByChannel = $this->resolvedPairsByChannels($productIds, $branchIds, $channels);
            foreach ($operations as $op) {
                foreach ($pairsByChannel[$op['channel']] ?? [] as $pair) {
                    $productId = (int) $pair['product_id'];
                    if ($productValues !== [] && ! array_key_exists($productId, $productValues)) {
                        continue;
                    }
                    $current = $pair['price'];
                    $next = $this->applyAction(
                        $current,
                        $op['action'],
                        $productValues[$productId] ?? $op['value']
                    );
                    if (abs($next - $current) <= 0.009) {
                        continue;
                    }
                    $rows[] = [
                        'product_id' => $pair['product_id'],
                        'product_name' => $pair['product_name'],
                        'branch_id' => $pair['branch_id'],
                        'branch_name' => $pair['branch_name'],
                        'channel' => $op['channel'],
                        'current_price' => $current,
                        'new_price' => $next,
                        'difference' => $this->pricing->money($next - $current),
                    ];
                }
            }
        }

        return $this->truncate(array_merge($rows, $this->variationPreviewRows($productIds, $branchIds, $variation['values'])));
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @param  list<array<string, mixed>>  $operations
     * @param  array<int|string, mixed>  $productValues
     * @param  list<array<string, mixed>>  $variationValues
     * @return array{saved: int, error?: string}
     */
    public function applyPrices(array $productIds, array $branchIds, array $operations, array $productValues = [], array $variationValues = []): array
    {
        $variation = $this->normalizeVariationValues($variationValues, $productIds, $branchIds);
        if (! empty($variation['error'])) {
            return ['saved' => 0, 'error' => $variation['error']];
        }

        $preview = $this->previewPrices($productIds, $branchIds, $operations, $productValues, $variationValues);
        if (! empty($preview['error'])) {
            return ['saved' => 0, 'error' => $preview['error']];
        }

        $saved = 0;

        try {
            DB::transaction(function () use ($preview, $productIds, $branchIds, $variation, &$saved) {
                $byProduct = [];
                foreach ($preview['rows'] as $row) {
                    if (! empty($row['variation_id'])) {
                        continue;
                    }
                    $byProduct[(int) $row['product_id']][] = $row;
                }

                $products = Product::query()->whereIn('id', array_keys($byProduct))->get()->keyBy('id');
                foreach ($byProduct as $productId => $rows) {
                    $product = $products->get($productId);
                    if (! $product) {
                        continue;
                    }

                    $defaultPrice = null;
                    $changes = [];
                    foreach ($rows as $row) {
                        if (($row['channel'] ?? '') === ProductPricingChannels::DEFAULT) {
                            $defaultPrice = (float) $row['new_price'];
                            continue;
                        }
                        $changes[] = [
                            'branch_id' => $row['branch_id'],
                            'channel' => $row['channel'],
                            'price' => $row['new_price'],
                            'reset_price' => false,
                            'price_is_selling' => ($row['channel'] ?? '') === ProductPricingChannels::POS,
                        ];
                    }

                    if ($defaultPrice === null && $changes === []) {
                        continue;
                    }
                    $result = $this->pricing->saveDrawer($product, $defaultPrice, $changes, 'bulk_price');
                    $saved += (int) $result['saved'];
                }

                $saved += $this->applyVariationUpdates($productIds, $branchIds, $variation['values']);
            });
        } catch (\Throwable $e) {
            return ['saved' => 0, 'error' => $e->getMessage()];
        }

        return ['saved' => $saved];
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @param  list<string>  $channels
     * @return array{rows: list<array<string, mixed>>, count: int, truncated: bool, error?: string}
     */
    public function previewAvailability(array $productIds, array $branchIds, array $channels, bool $enabled): array
    {
        $channels = ProductPricingChannels::filterOverrideChannels($channels);
        if ($channels === []) {
            return ['rows' => [], 'count' => 0, 'truncated' => false, 'error' => 'Select at least one channel'];
        }
        if ($branchIds === []) {
            return ['rows' => [], 'count' => 0, 'truncated' => false, 'error' => 'Choose branches first'];
        }

        $pairsByChannel = $this->resolvedPairsByChannels($productIds, $branchIds, $channels);
        $rows = [];
        foreach ($channels as $channel) {
            foreach ($pairsByChannel[$channel] ?? [] as $pair) {
                $current = (bool) $pair['available'];
                if ($current === $enabled) {
                    continue;
                }
                $rows[] = [
                    'product_id' => $pair['product_id'],
                    'product_name' => $pair['product_name'],
                    'branch_id' => $pair['branch_id'],
                    'branch_name' => $pair['branch_name'],
                    'channel' => $channel,
                    'current_available' => $current,
                    'new_available' => $enabled,
                ];
            }
        }

        return $this->truncate($rows);
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @param  list<string>  $channels
     * @return array{saved: int, error?: string}
     */
    public function applyAvailability(array $productIds, array $branchIds, array $channels, bool $enabled): array
    {
        $preview = $this->previewAvailability($productIds, $branchIds, $channels, $enabled);
        if (! empty($preview['error'])) {
            return ['saved' => 0, 'error' => $preview['error']];
        }

        $saved = 0;
        DB::transaction(function () use ($preview, &$saved) {
            $byProduct = [];
            foreach ($preview['rows'] as $row) {
                $byProduct[(int) $row['product_id']][] = $row;
            }
            $products = Product::query()->whereIn('id', array_keys($byProduct))->get()->keyBy('id');
            foreach ($byProduct as $productId => $rows) {
                $product = $products->get($productId);
                if (! $product) {
                    continue;
                }
                $changes = [];
                foreach ($rows as $row) {
                    $changes[] = [
                        'branch_id' => $row['branch_id'],
                        'channel' => $row['channel'],
                        'is_available' => ! empty($row['new_available']),
                    ];
                }
                $result = $this->pricing->saveDrawer($product, null, $changes, 'bulk_availability');
                $saved += (int) $result['saved'];
            }
        });

        return ['saved' => $saved];
    }

    public function applyAction(float $current, string $action, float $value): float
    {
        $next = match ($action) {
            'increase_percent' => $current * (1 + ($value / 100)),
            'decrease_percent' => $current * (1 - ($value / 100)),
            'increase_amount' => $current + $value,
            'decrease_amount' => $current - $value,
            'set_exact' => $value,
            'round_5' => round($current / 5) * 5,
            'round_10' => round($current / 10) * 10,
            default => $current,
        };

        return $this->pricing->money(max(0, $next));
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return list<array{channel: string, action: string, value: float}>
     */
    public function normalizePriceOperations(array $operations): array
    {
        $out = [];
        foreach ($operations as $op) {
            if (! is_array($op)) {
                continue;
            }
            $channel = (string) ($op['channel'] ?? '');
            if (! ProductPricingChannels::isSelectable($channel)) {
                continue;
            }
            $action = (string) ($op['action'] ?? '');
            if (! in_array($action, self::ACTIONS, true)) {
                continue;
            }
            $out[] = [
                'channel' => $channel,
                'action' => $action,
                'value' => (float) ($op['value'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Per-product selling prices for Set Exact Price. Empty means every selected
     * product uses the operation value, matching the original bulk behaviour.
     *
     * @param  array<int|string, mixed>  $raw
     * @return array<int, float>
     */
    public function normalizeProductValues(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            if (is_array($value)) {
                $id = (int) ($value['product_id'] ?? $value['id'] ?? 0);
                $amount = (float) ($value['value'] ?? $value['price'] ?? 0);
            } else {
                $id = (int) $key;
                $amount = (float) $value;
            }
            if ($id > 0 && $amount > 0) {
                $out[$id] = $this->pricing->money($amount);
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @return array{values: list<array{product_id: int, variation_id: string, channel: string, value: float}>, error?: string}
     */
    public function normalizeVariationValues(array $raw, array $productIds = [], array $branchIds = []): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds))));
        $allowed = $this->allowedVariationIds($productIds, $branchIds);
        $out = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $productId = (int) ($row['product_id'] ?? 0);
            $variationId = (string) ($row['variation_id'] ?? '');
            $channel = (string) ($row['channel'] ?? '');
            if ($productId < 1 || $variationId === '') {
                continue;
            }
            if ($productIds !== [] && ! in_array($productId, $productIds, true)) {
                return ['values' => [], 'error' => 'Variation does not belong to the selected products'];
            }
            if (! ProductPricingChannels::isMarketplace($channel)) {
                return ['values' => [], 'error' => 'Variation marketplace prices are only supported for Uber, Glovo and Bolt Food'];
            }
            if (! isset($allowed[$productId][$variationId])) {
                return ['values' => [], 'error' => 'Variation does not belong to the selected products'];
            }
            [$amount, $error] = ProductVariationPricing::parseSubmittedPrice($row['value'] ?? $row['price'] ?? null);
            if ($error !== null) {
                return ['values' => [], 'error' => $error];
            }
            if ($amount === null) {
                continue;
            }
            $out[] = [
                'product_id' => $productId,
                'variation_id' => $variationId,
                'channel' => $channel,
                'value' => $amount,
            ];
        }

        if ($out !== [] && $branchIds === []) {
            return ['values' => [], 'error' => 'Choose branches first'];
        }

        return ['values' => $out];
    }

    /**
     * @return array{0: string, 1: bool}|null
     */
    public function parseAvailabilityAction(string $action): ?array
    {
        if (! in_array($action, self::AVAILABILITY_ACTIONS, true)) {
            return null;
        }

        $enabled = str_starts_with($action, 'enable_');
        $channel = match ($action) {
            'enable_pos', 'disable_pos' => ProductPricingChannels::POS,
            'enable_uber', 'disable_uber' => ProductPricingChannels::UBER,
            'enable_glovo', 'disable_glovo' => ProductPricingChannels::GLOVO,
            'enable_bolt_food', 'disable_bolt_food' => ProductPricingChannels::BOLT_FOOD,
            default => null,
        };

        return $channel === null ? null : [$channel, $enabled];
    }

    /**
     * @param  list<array{channel: string, action: string, value: float}>  $operations
     */
    private function operationsNeedBranches(array $operations): bool
    {
        foreach ($operations as $op) {
            if ($op['channel'] !== ProductPricingChannels::DEFAULT) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @param  list<string>  $channels
     * @return array<string, list<array<string, mixed>>>
     */
    private function resolvedPairsByChannels(array $productIds, array $branchIds, array $channels): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds))));
        $grouped = [];
        foreach ($channels as $channel) {
            $grouped[$channel] = [];
        }
        if ($productIds === []) {
            return $grouped;
        }

        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $branchNames = collect($this->pricing->branchOptions())->keyBy('id');
        $overrideChannels = array_values(array_filter(
            $channels,
            fn ($channel) => $channel !== ProductPricingChannels::DEFAULT
        ));
        $branchProducts = $overrideChannels === [] || $branchIds === []
            ? collect()
            : ProductByBranch::query()
                ->whereIn('product_id', $productIds)
                ->whereIn('branch_id', $branchIds)
                ->get()
                ->groupBy('product_id');

        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }
            $default = $this->pricing->defaultPrice($product);
            if (in_array(ProductPricingChannels::DEFAULT, $channels, true)) {
                $grouped[ProductPricingChannels::DEFAULT][] = [
                    'product_id' => $productId,
                    'product_name' => (string) $product->name,
                    'branch_id' => 0,
                    'branch_name' => 'All branches',
                    'price' => $default,
                    'available' => true,
                ];
            }
            if ($overrideChannels === [] || $branchIds === []) {
                continue;
            }

            $byBranch = ($branchProducts->get($productId) ?? collect())->keyBy('branch_id');
            $channelRows = $this->pricing->channelRowsForProduct($productId, $branchIds);
            foreach ($branchIds as $branchId) {
                $branchProduct = $byBranch->get($branchId);
                $payload = $this->pricing->discountPayload($product, $branchProduct);
                $matrix = $this->pricing->resolveMatrix(
                    $default,
                    $branchProduct,
                    $channelRows[$branchId] ?? [],
                    $payload
                );
                foreach ($overrideChannels as $channel) {
                    $unit = $matrix['prices'][$channel] ?? $default;
                    $grouped[$channel][] = [
                        'product_id' => $productId,
                        'product_name' => (string) $product->name,
                        'branch_id' => $branchId,
                        'branch_name' => (string) ($branchNames->get($branchId)['name'] ?? 'Branch '.$branchId),
                        'price' => $this->pricing->effectiveSellingPrice($unit, $payload),
                        'available' => (bool) ($matrix['available'][$channel] ?? false),
                    ];
                }
            }
        }

        return $grouped;
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @param  list<string>  $channels
     * @param  array<string, list<array<string, mixed>>>  $pairsByChannel
     * @return list<array<string, mixed>>
     */
    private function variationCurrentRows(array $productIds, array $branchIds, array $channels, array $pairsByChannel): array
    {
        $marketplace = array_values(array_filter(
            $channels,
            fn ($channel) => ProductPricingChannels::isMarketplace($channel)
        ));
        if ($marketplace === [] || $branchIds === []) {
            return [];
        }

        $optionsByProduct = $this->variationOptionsByProduct($productIds, $branchIds);
        $rows = [];
        foreach ($marketplace as $channel) {
            foreach ($pairsByChannel[$channel] ?? [] as $pair) {
                $productId = (int) $pair['product_id'];
                $branchId = (int) $pair['branch_id'];
                foreach ($optionsByProduct[$productId][$branchId] ?? $optionsByProduct[$productId][0] ?? [] as $option) {
                    $override = $option['channel_prices'][$channel] ?? null;
                    $rows[] = [
                        'product_id' => $productId,
                        'product_name' => $pair['product_name'],
                        'branch_id' => $branchId,
                        'branch_name' => $pair['branch_name'],
                        'channel' => $channel,
                        'current_price' => ProductVariationPricing::effectivePrice(
                            (float) $pair['price'],
                            (float) $option['option_price'],
                            $override
                        ),
                        'variation_id' => $option['id'],
                        'variation_name' => $option['label'],
                        'variation_group' => $option['group'],
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @param  list<array{product_id: int, variation_id: string, channel: string, value: float}>  $values
     * @return list<array<string, mixed>>
     */
    private function variationPreviewRows(array $productIds, array $branchIds, array $values): array
    {
        if ($values === [] || $branchIds === []) {
            return [];
        }

        $channels = array_values(array_unique(array_column($values, 'channel')));
        $pairsByChannel = $this->resolvedPairsByChannels($productIds, $branchIds, $channels);
        $optionsByProduct = $this->variationOptionsByProduct($productIds, $branchIds);
        $indexed = [];
        foreach ($values as $value) {
            $indexed[$value['product_id'].'|'.$value['variation_id'].'|'.$value['channel']] = $value;
        }

        $rows = [];
        foreach ($indexed as $value) {
            foreach ($pairsByChannel[$value['channel']] ?? [] as $pair) {
                if ((int) $pair['product_id'] !== $value['product_id']) {
                    continue;
                }
                $branchId = (int) $pair['branch_id'];
                $options = $optionsByProduct[$value['product_id']][$branchId]
                    ?? $optionsByProduct[$value['product_id']][0]
                    ?? [];
                $option = null;
                foreach ($options as $candidate) {
                    if ($candidate['id'] === $value['variation_id']) {
                        $option = $candidate;
                        break;
                    }
                }
                if ($option === null) {
                    continue;
                }
                $current = ProductVariationPricing::effectivePrice(
                    (float) $pair['price'],
                    (float) $option['option_price'],
                    $option['channel_prices'][$value['channel']] ?? null
                );
                $next = $value['value'];
                if (abs($next - $current) <= 0.009) {
                    continue;
                }
                $rows[] = [
                    'product_id' => $value['product_id'],
                    'product_name' => $pair['product_name'],
                    'branch_id' => $branchId,
                    'branch_name' => $pair['branch_name'],
                    'channel' => $value['channel'],
                    'current_price' => $current,
                    'new_price' => $next,
                    'difference' => $this->pricing->money($next - $current),
                    'variation_id' => $option['id'],
                    'variation_name' => $option['label'],
                    'variation_group' => $option['group'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @param  list<array{product_id: int, variation_id: string, channel: string, value: float}>  $values
     */
    private function applyVariationUpdates(array $productIds, array $branchIds, array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds))));
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $branchRows = ProductByBranch::query()
            ->whereIn('product_id', $productIds)
            ->whereIn('branch_id', $branchIds)
            ->get();

        $byBranchKey = [];
        foreach ($branchRows as $row) {
            $byBranchKey[(int) $row->product_id.':'.(int) $row->branch_id] = $row;
        }

        $saved = 0;
        foreach ($branchIds as $branchId) {
            $byProduct = [];
            foreach ($values as $value) {
                $byProduct[$value['product_id']][] = $value;
            }
            foreach ($byProduct as $productId => $productValues) {
                $branchProduct = $byBranchKey[$productId.':'.$branchId] ?? null;
                if (! $branchProduct) {
                    continue;
                }
                $variations = ProductVariationPricing::decodeVariations($branchProduct->variations);
                if ($variations === []) {
                    $product = $products->get($productId);
                    $variations = ProductVariationPricing::decodeVariations($product?->getRawOriginal('variations'));
                }
                foreach ($productValues as $value) {
                    $variations = ProductVariationPricing::setOptionChannelPrice(
                        $variations,
                        $value['variation_id'],
                        $value['channel'],
                        $value['value']
                    );
                    $saved++;
                }
                $branchProduct->variations = $variations;
                $branchProduct->save();
            }
        }

        foreach ($products as $product) {
            $edits = array_values(array_filter(
                $values,
                fn ($value) => $value['product_id'] === (int) $product->id
            ));
            if ($edits === []) {
                continue;
            }
            $variations = ProductVariationPricing::decodeVariations($product->getRawOriginal('variations'));
            foreach ($edits as $value) {
                $variations = ProductVariationPricing::setOptionChannelPrice(
                    $variations,
                    $value['variation_id'],
                    $value['channel'],
                    $value['value'],
                    false
                );
            }
            $product->variations = json_encode($variations);
            $product->save();
        }

        return $saved;
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @return array<int, array<string, true>>
     */
    private function allowedVariationIds(array $productIds, array $branchIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $allowed = [];
        $products = Product::query()->whereIn('id', $productIds)->get(['id', 'variations']);
        foreach ($products as $product) {
            foreach (ProductVariationPricing::flatOptions($product->getRawOriginal('variations')) as $option) {
                $allowed[(int) $product->id][$option['id']] = true;
            }
        }

        $branchQuery = ProductByBranch::query()->whereIn('product_id', $productIds);
        if ($branchIds !== []) {
            $branchQuery->whereIn('branch_id', $branchIds);
        }
        foreach ($branchQuery->get(['product_id', 'variations']) as $row) {
            foreach (ProductVariationPricing::flatOptions($row->variations) as $option) {
                $allowed[(int) $row->product_id][$option['id']] = true;
            }
        }

        return $allowed;
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @return array<int, array<int, list<array{id: string, group: string, label: string, option_price: float, channel_prices: array<string, float>}>>>
     */
    private function variationOptionsByProduct(array $productIds, array $branchIds): array
    {
        $out = [];
        if ($productIds === []) {
            return $out;
        }

        $products = Product::query()->whereIn('id', $productIds)->get(['id', 'variations']);
        foreach ($products as $product) {
            $out[(int) $product->id][0] = ProductVariationPricing::flatOptions($product->getRawOriginal('variations'));
        }

        if ($branchIds === []) {
            return $out;
        }

        $rows = ProductByBranch::query()
            ->whereIn('product_id', $productIds)
            ->whereIn('branch_id', $branchIds)
            ->get(['product_id', 'branch_id', 'variations']);
        foreach ($rows as $row) {
            $options = ProductVariationPricing::flatOptions($row->variations);
            if ($options === []) {
                $options = $out[(int) $row->product_id][0] ?? [];
            }
            $out[(int) $row->product_id][(int) $row->branch_id] = $options;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, count: int, truncated: bool}
     */
    private function truncate(array $rows): array
    {
        $count = count($rows);
        $truncated = $count > self::MAX_ROWS;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }

        return [
            'rows' => array_values($rows),
            'count' => $count,
            'truncated' => $truncated,
        ];
    }
}
