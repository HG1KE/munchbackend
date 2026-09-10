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
     * @return array{rows: list<array<string, mixed>>, count: int, truncated: bool}
     */
    public function previewPrices(array $productIds, array $branchIds, string $channel, string $action, float $value): array
    {
        $channel = ProductPricingChannels::normalize($channel);
        if (! in_array($action, self::ACTIONS, true)) {
            return ['rows' => [], 'count' => 0, 'truncated' => false, 'error' => 'Invalid action'];
        }

        $pairs = $this->resolvedPairs($productIds, $branchIds, $channel);
        $rows = [];
        foreach ($pairs as $pair) {
            $current = $pair['price'];
            $next = $this->applyAction($current, $action, $value);
            $rows[] = [
                'product_id' => $pair['product_id'],
                'product_name' => $pair['product_name'],
                'branch_id' => $pair['branch_id'],
                'branch_name' => $pair['branch_name'],
                'channel' => $channel,
                'current_price' => $current,
                'new_price' => $next,
                'difference' => $this->pricing->money($next - $current),
            ];
        }

        return $this->truncate($rows);
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @return array{saved: int}
     */
    public function applyPrices(array $productIds, array $branchIds, string $channel, string $action, float $value): array
    {
        $preview = $this->previewPrices($productIds, $branchIds, $channel, $action, $value);
        if (! empty($preview['error'])) {
            return ['saved' => 0, 'error' => $preview['error']];
        }

        $channel = ProductPricingChannels::normalize($channel);
        $saved = 0;

        DB::transaction(function () use ($preview, $channel, &$saved) {
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
                if ($channel === ProductPricingChannels::DEFAULT) {
                    $first = $rows[0];
                    if (abs($first['current_price'] - $first['new_price']) <= 0.009) {
                        continue;
                    }
                    $this->pricing->saveDrawer($product, (float) $first['new_price'], [], 'bulk_price');
                    $saved++;
                    continue;
                }

                $changes = [];
                foreach ($rows as $row) {
                    if (abs($row['current_price'] - $row['new_price']) <= 0.009) {
                        continue;
                    }
                    $changes[] = [
                        'branch_id' => $row['branch_id'],
                        'channel' => $channel,
                        'price' => $row['new_price'],
                        'reset_price' => false,
                    ];
                }
                if ($changes === []) {
                    continue;
                }
                $result = $this->pricing->saveDrawer($product, null, $changes, 'bulk_price');
                $saved += (int) $result['saved'];
            }
        });

        return ['saved' => $saved];
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @return array{rows: list<array<string, mixed>>, count: int, truncated: bool}
     */
    public function previewAvailability(array $productIds, array $branchIds, string $action): array
    {
        $parsed = $this->parseAvailabilityAction($action);
        if ($parsed === null) {
            return ['rows' => [], 'count' => 0, 'truncated' => false, 'error' => 'Invalid action'];
        }

        [$channel, $enabled] = $parsed;
        $pairs = $this->resolvedPairs($productIds, $branchIds, $channel);
        $rows = [];
        foreach ($pairs as $pair) {
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

        return $this->truncate($rows);
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @return array{saved: int}
     */
    public function applyAvailability(array $productIds, array $branchIds, string $action): array
    {
        $preview = $this->previewAvailability($productIds, $branchIds, $action);
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
     * @param  list<int>  $productIds
     * @param  list<int>  $branchIds
     * @return list<array<string, mixed>>
     */
    private function resolvedPairs(array $productIds, array $branchIds, string $channel): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds))));
        if ($productIds === []) {
            return [];
        }
        if ($channel !== ProductPricingChannels::DEFAULT && $branchIds === []) {
            return [];
        }

        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $branchNames = collect($this->pricing->branchOptions())->keyBy('id');
        $branchProducts = ProductByBranch::query()
            ->whereIn('product_id', $productIds)
            ->whereIn('branch_id', $branchIds)
            ->get()
            ->groupBy('product_id');

        $out = [];
        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }
            $default = $this->pricing->defaultPrice($product);
            $byBranch = ($branchProducts->get($productId) ?? collect())->keyBy('branch_id');
            $channelRows = $this->pricing->channelRowsForProduct($productId, $branchIds);

            if ($channel === ProductPricingChannels::DEFAULT) {
                $out[] = [
                    'product_id' => $productId,
                    'product_name' => (string) $product->name,
                    'branch_id' => 0,
                    'branch_name' => 'All branches',
                    'price' => $default,
                    'available' => true,
                ];
                continue;
            }

            foreach ($branchIds as $branchId) {
                $branchProduct = $byBranch->get($branchId);
                $payload = $this->pricing->discountPayload($product, $branchProduct);
                $matrix = $this->pricing->resolveMatrix(
                    $default,
                    $branchProduct,
                    $channelRows[$branchId] ?? [],
                    $payload
                );
                $unit = $matrix['prices'][$channel] ?? $default;
                $out[] = [
                    'product_id' => $productId,
                    'product_name' => (string) $product->name,
                    'branch_id' => $branchId,
                    'branch_name' => (string) ($branchNames->get($branchId)['name'] ?? 'Branch '.$branchId),
                    'price' => $this->pricing->displayPrice($channel, $unit, $payload),
                    'available' => (bool) ($matrix['available'][$channel] ?? false),
                ];
            }
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
