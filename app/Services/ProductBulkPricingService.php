<?php

namespace App\Services;

use App\Model\Product;
use App\Model\ProductByBranch;
use App\Support\ProductPricingChannels;
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
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @param  list<array<string, mixed>>  $operations
     * @return array{rows: list<array<string, mixed>>, count: int, truncated: bool, error?: string}
     */
    public function previewPrices(array $productIds, array $branchIds, array $operations): array
    {
        $operations = $this->normalizePriceOperations($operations);
        if ($operations === []) {
            return ['rows' => [], 'count' => 0, 'truncated' => false, 'error' => 'Select at least one channel'];
        }

        $channels = array_values(array_unique(array_column($operations, 'channel')));
        $needsBranches = $this->operationsNeedBranches($operations);
        if ($needsBranches && $branchIds === []) {
            return ['rows' => [], 'count' => 0, 'truncated' => false, 'error' => 'Choose branches first'];
        }

        $pairsByChannel = $this->resolvedPairsByChannels($productIds, $branchIds, $channels);
        $rows = [];
        foreach ($operations as $op) {
            foreach ($pairsByChannel[$op['channel']] ?? [] as $pair) {
                $current = $pair['price'];
                $next = $this->applyAction($current, $op['action'], $op['value']);
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

        return $this->truncate($rows);
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @param  list<array<string, mixed>>  $operations
     * @return array{saved: int, error?: string}
     */
    public function applyPrices(array $productIds, array $branchIds, array $operations): array
    {
        $preview = $this->previewPrices($productIds, $branchIds, $operations);
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
        });

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
