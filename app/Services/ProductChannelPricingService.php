<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Model\Branch;
use App\Model\Category;
use App\Model\Product;
use App\Model\ProductByBranch;
use App\Model\ProductChannelPrice;
use App\Support\ProductPricingChannels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductChannelPricingService
{
    public const CACHE_TTL = 300;

    public function __construct(
        private ProductPricingAuditLogger $auditLogger,
    ) {
    }

    public function tableReady(): bool
    {
        return Schema::hasTable('product_channel_prices');
    }

    /**
     * Stored unit prices (POS checkout still applies product discounts on these):
     * POS: override ?? default unit.
     * Marketplace: channel override ?? POS override ?? default unit.
     */
    public function resolvePrice(string $channel, float $defaultPrice, ?float $posOverride, ?float $channelOverride): float
    {
        $channel = ProductPricingChannels::normalize($channel);
        if ($channel === ProductPricingChannels::DEFAULT) {
            return $this->money($defaultPrice);
        }

        $pos = $posOverride ?? $defaultPrice;

        if ($channel === ProductPricingChannels::POS) {
            return $this->money($pos);
        }

        return $this->money($channelOverride ?? $pos);
    }

    /**
     * Discount payload already used by storefront cards and POS catalog.
     *
     * @return array{discount_type: string, discount: float}
     */
    public function discountPayload(Product $product, ?ProductByBranch $branchProduct = null): array
    {
        $fromProduct = $this->normalizeDiscountPayload([
            'discount_type' => $product->discount_type ?? 'amount',
            'discount' => $product->getRawOriginal('discount') ?? $product->discount,
        ]);

        if ($branchProduct === null) {
            return $fromProduct;
        }

        $fromBranch = $this->normalizeDiscountPayload([
            'discount_type' => $branchProduct->discount_type,
            'discount' => $branchProduct->discount,
        ]);

        return $fromBranch['discount'] > 0 ? $fromBranch : $fromProduct;
    }

    public function defaultSellingPrice(Product $product, ?ProductByBranch $branchProduct = null): float
    {
        $unit = $branchProduct !== null
            ? $this->money((float) $branchProduct->price)
            : $this->defaultPrice($product);

        return $this->effectiveSellingPrice($unit, $this->discountPayload($product, $branchProduct));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{discount_type: string, discount: float}
     */
    public function normalizeDiscountPayload(array $payload): array
    {
        return [
            'discount_type' => (string) ($payload['discount_type'] ?? 'amount'),
            'discount' => (float) ($payload['discount'] ?? 0),
        ];
    }

    /**
     * Website / POS selling price: unit − Helpers::discount_calculate().
     *
     * @param  array<string, mixed>  $discountPayload
     */
    public function effectiveSellingPrice(float $unitPrice, array $discountPayload = []): float
    {
        $payload = $this->normalizeDiscountPayload($discountPayload);
        $discount = (float) Helpers::discount_calculate($payload, $unitPrice);

        return $this->money(max(0, $unitPrice - $discount));
    }

    /**
     * Reverse of effectiveSellingPrice so marketplace admin edits stay in selling-price
     * space while POS still stores and discounts unit prices.
     *
     * @param  array<string, mixed>  $discountPayload
     */
    public function sellingToUnit(float $sellingPrice, array $discountPayload = []): float
    {
        $payload = $this->normalizeDiscountPayload($discountPayload);
        $selling = $this->money(max(0, $sellingPrice));

        if ($payload['discount_type'] === 'percent') {
            $percent = (float) $payload['discount'];
            if ($percent <= 0 || $percent >= 100) {
                return $selling;
            }

            return $this->money($selling / (1 - ($percent / 100)));
        }

        return $this->money($selling + (float) $payload['discount']);
    }

    /**
     * Admin / preview figure for a stored unit price.
     *
     * @param  array<string, mixed>  $discountPayload
     */
    public function displayPrice(string $channel, float $unitPrice, array $discountPayload = []): float
    {
        if (ProductPricingChannels::isMarketplace($channel)) {
            return $this->effectiveSellingPrice($unitPrice, $discountPayload);
        }

        return $this->money($unitPrice);
    }

    /**
     * @param  array<string, ProductChannelPrice|null>  $channelRows  keyed by channel
     * @param  array<string, mixed>  $discountPayload
     * @return array{
     *     prices: array<string, float>,
     *     display_prices: array<string, float>,
     *     available: array<string, bool>,
     *     overrides: array<string, bool>,
     *     inherited_from: array<string, string>,
     *     inherited_price: array<string, float>
     * }
     */
    public function resolveMatrix(float $defaultPrice, ?ProductByBranch $branchProduct, array $channelRows, array $discountPayload = []): array
    {
        $discountPayload = $this->normalizeDiscountPayload($discountPayload);
        $posRow = $channelRows[ProductPricingChannels::POS] ?? null;
        $posOverride = $this->posOverrideValue($posRow, $branchProduct, $defaultPrice);
        $posAvailable = $this->posAvailable($posRow, $branchProduct);
        $posUnit = $this->resolvePrice(ProductPricingChannels::POS, $defaultPrice, $posOverride, null);
        $marketplaceInherit = $this->effectiveSellingPrice($posUnit, $discountPayload);

        $prices = [
            ProductPricingChannels::POS => $posUnit,
        ];
        $displayPrices = [
            ProductPricingChannels::POS => $posUnit,
        ];
        $available = [ProductPricingChannels::POS => $posAvailable];
        $overrides = [ProductPricingChannels::POS => $posOverride !== null];
        $inheritedFrom = [
            ProductPricingChannels::POS => $posOverride !== null ? 'override' : ProductPricingChannels::DEFAULT,
        ];
        $inheritedPrice = [
            ProductPricingChannels::POS => $this->money($defaultPrice),
        ];

        foreach (ProductPricingChannels::marketplaceChannels() as $channel) {
            $row = $channelRows[$channel] ?? null;
            $channelOverride = ($row && $row->price !== null) ? (float) $row->price : null;
            $unit = $this->resolvePrice($channel, $defaultPrice, $posOverride, $channelOverride);
            $prices[$channel] = $unit;
            $displayPrices[$channel] = $this->displayPrice($channel, $unit, $discountPayload);
            $available[$channel] = $row !== null ? ((int) $row->is_available === 1) : $posAvailable;
            $overrides[$channel] = $channelOverride !== null;
            $inheritedFrom[$channel] = $channelOverride !== null
                ? 'override'
                : ($posOverride !== null ? ProductPricingChannels::POS : ProductPricingChannels::DEFAULT);
            $inheritedPrice[$channel] = $marketplaceInherit;
        }

        return [
            'prices' => $prices,
            'display_prices' => $displayPrices,
            'available' => $available,
            'overrides' => $overrides,
            'inherited_from' => $inheritedFrom,
            'inherited_price' => $inheritedPrice,
        ];
    }

    public function anyChannelAvailable(array $available): bool
    {
        foreach ($available as $flag) {
            if ($flag) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{price: float, available: bool, channel: string}
     */
    public function resolveForSale(Product $product, int $branchId, ?string $orderType): array
    {
        $channel = ProductPricingChannels::fromOrderType($orderType);
        $defaultPrice = $this->defaultPrice($product);
        $branchProduct = ProductByBranch::query()
            ->where('product_id', $product->id)
            ->where('branch_id', $branchId)
            ->first();
        $matrix = $this->resolveMatrix(
            $defaultPrice,
            $branchProduct,
            $this->channelRowsFor($product->id, $branchId),
            $this->discountPayload($product, $branchProduct)
        );

        return [
            'channel' => $channel,
            'price' => $matrix['prices'][$channel] ?? $defaultPrice,
            'available' => (bool) ($matrix['available'][$channel] ?? false),
        ];
    }

    /**
     * Lazy payload for the Admin pricing drawer. Product List does not call this.
     *
     * @return array<string, mixed>
     */
    public function drawerPayload(Product $product, ?string $branchSearch = null, ?string $channelFilter = null): array
    {
        $defaultPrice = $this->defaultPrice($product);
        $defaultSellingPrice = $this->defaultSellingPrice($product);
        $branches = Branch::query()
            ->orderBy('id')
            ->get(['id', 'name', 'status']);

        if ($branchSearch !== null && trim($branchSearch) !== '') {
            $q = mb_strtolower(trim($branchSearch));
            $branches = $branches->filter(function ($branch) use ($q) {
                return str_contains(mb_strtolower((string) $branch->name), $q)
                    || (string) $branch->id === $q;
            })->values();
        }

        $branchIds = $branches->pluck('id')->all();
        $branchProducts = ProductByBranch::query()
            ->where('product_id', $product->id)
            ->whereIn('branch_id', $branchIds ?: [0])
            ->get()
            ->keyBy('branch_id');

        $channelRows = $this->channelRowsForProduct($product->id, $branchIds);
        $visibleChannels = ProductPricingChannels::overrideChannels();
        if ($channelFilter && ProductPricingChannels::isOverrideChannel($channelFilter)) {
            $visibleChannels = [$channelFilter];
        }

        $rows = [];
        foreach ($branches as $branch) {
            $branchId = (int) $branch->id;
            $byChannel = $channelRows[$branchId] ?? [];
            $branchProduct = $branchProducts->get($branchId);
            $discountPayload = $this->discountPayload($product, $branchProduct);
            $matrix = $this->resolveMatrix(
                $defaultPrice,
                $branchProduct,
                $byChannel,
                $discountPayload
            );

            $cells = [];
            foreach ($visibleChannels as $channel) {
                $cells[$channel] = [
                    'price' => $matrix['display_prices'][$channel] ?? $matrix['prices'][$channel],
                    'override' => $matrix['overrides'][$channel],
                    'inherited_from' => $matrix['inherited_from'][$channel],
                    'inherited_price' => $matrix['inherited_price'][$channel] ?? $matrix['display_prices'][$channel] ?? $matrix['prices'][$channel],
                    'available' => $matrix['available'][$channel],
                ];
            }

            $rows[] = [
                'id' => $branchId,
                'name' => (string) $branch->name,
                'active' => (int) $branch->status === 1,
                'cells' => $cells,
            ];
        }

        $category = $product->category;

        return [
            'product' => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'image' => (string) $product->image_full_path,
                'category' => is_array($category) ? (string) ($category['name'] ?? '') : '',
                'default_price' => $defaultPrice,
                'default_selling_price' => $defaultSellingPrice,
            ],
            'channels' => $visibleChannels,
            'channel_labels' => ProductPricingChannels::labels(),
            'branches' => $rows,
        ];
    }

    /**
     * Save only the rows the client marked dirty.
     *
     * @param  list<array<string, mixed>>  $changes
     * @return array{saved: int, default_price: float}
     */
    public function saveDrawer(Product $product, ?float $defaultPrice, array $changes, string $source = 'drawer'): array
    {
        $audits = [];
        $currentDefault = $this->defaultPrice($product);

        DB::transaction(function () use ($product, $defaultPrice, $changes, $source, &$audits, &$currentDefault) {
            if ($defaultPrice !== null && abs($defaultPrice - $currentDefault) > 0.009) {
                $audits[] = [
                    'product_id' => $product->id,
                    'branch_id' => null,
                    'channel' => ProductPricingChannels::DEFAULT,
                    'field' => 'price',
                    'old_value' => $currentDefault,
                    'new_value' => $this->money($defaultPrice),
                ];
                $product->price = $this->money($defaultPrice);
                $product->save();
                $oldDefault = $currentDefault;
                $currentDefault = $this->defaultPrice($product);
                $this->propagateDefaultToInheritedPos($product, $oldDefault, $currentDefault);
            }

            foreach ($changes as $change) {
                $branchId = (int) ($change['branch_id'] ?? 0);
                $channel = (string) ($change['channel'] ?? '');
                if ($branchId < 1 || ! ProductPricingChannels::isOverrideChannel($channel)) {
                    continue;
                }

                $audits = array_merge($audits, $this->applyCellChange(
                    $product,
                    $branchId,
                    $channel,
                    $change,
                    $currentDefault
                ));
            }
        });

        $this->auditLogger->record($audits, $source);
        $this->bustCache((int) $product->id);

        return [
            'saved' => count($audits),
            'default_price' => $currentDefault,
        ];
    }

    /**
     * Keep `product_by_branches` in sync so online orders and existing POS
     * checkout keep using the POS-resolved price.
     */
    public function syncPosBranchPrice(int $productId, int $branchId, float $price, ?int $isAvailable = null): ProductByBranch
    {
        $existing = ProductByBranch::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->first();

        $product = Product::query()->findOrFail($productId);
        $payload = [
            'price' => $this->money($price),
        ];
        if ($isAvailable !== null) {
            $payload['is_available'] = $isAvailable ? 1 : 0;
        }

        if ($existing) {
            $existing->fill($payload);
            $existing->save();

            return $existing;
        }

        $main = ProductByBranch::query()
            ->where('product_id', $productId)
            ->where('branch_id', 1)
            ->first();

        $decoded = json_decode((string) $product->getRawOriginal('variations'), true);
        if (! is_array($decoded)) {
            $decoded = [];
        }

        return ProductByBranch::query()->create([
            'product_id' => $productId,
            'branch_id' => $branchId,
            'price' => $this->money($price),
            'discount_type' => $product->discount_type ?: 'percent',
            'discount' => (float) $product->discount,
            'is_available' => $isAvailable === null ? 1 : ($isAvailable ? 1 : 0),
            'variations' => $main?->variations ?: $decoded,
            'stock_type' => $main?->stock_type ?: 'unlimited',
            'stock' => $main?->stock ?: 0,
        ]);
    }

    public function syncPosChannelFromBranch(ProductByBranch $branchProduct, string $source = 'branch'): void
    {
        if (! $this->tableReady()) {
            return;
        }

        $product = Product::query()->find($branchProduct->product_id);
        if (! $product) {
            return;
        }

        $default = $this->defaultPrice($product);
        $price = (float) $branchProduct->price;
        $override = abs($price - $default) > 0.009 ? $price : null;

        $row = ProductChannelPrice::query()->firstOrNew([
            'product_id' => $branchProduct->product_id,
            'branch_id' => $branchProduct->branch_id,
            'channel' => ProductPricingChannels::POS,
        ]);

        $oldPrice = $row->exists ? $row->price : null;
        $oldAvailable = $row->exists ? (int) $row->is_available : (int) $branchProduct->is_available;

        $row->price = $override;
        $row->is_available = (int) $branchProduct->is_available;
        $row->save();

        $this->auditLogger->record([
            [
                'product_id' => $branchProduct->product_id,
                'branch_id' => $branchProduct->branch_id,
                'channel' => ProductPricingChannels::POS,
                'field' => 'price',
                'old_value' => $oldPrice,
                'new_value' => $override,
            ],
            [
                'product_id' => $branchProduct->product_id,
                'branch_id' => $branchProduct->branch_id,
                'channel' => ProductPricingChannels::POS,
                'field' => 'is_available',
                'old_value' => $oldAvailable,
                'new_value' => (int) $branchProduct->is_available,
            ],
        ], $source);

        $this->bustCache((int) $branchProduct->product_id, (int) $branchProduct->branch_id);
    }

    /**
     * @return array<int, array<string, ProductChannelPrice>>
     */
    public function channelRowsForProduct(int $productId, array $branchIds): array
    {
        if (! $this->tableReady() || $branchIds === []) {
            return [];
        }

        $rows = ProductChannelPrice::query()
            ->where('product_id', $productId)
            ->whereIn('branch_id', $branchIds)
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->branch_id][$row->channel] = $row;
        }

        return $grouped;
    }

    /**
     * @return array<int, array<string, ProductChannelPrice>> keyed by product_id
     */
    public function channelRowsForBranch(int $branchId, array $productIds = []): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $query = ProductChannelPrice::query()->where('branch_id', $branchId);
        if ($productIds !== []) {
            $query->whereIn('product_id', $productIds);
        }

        $grouped = [];
        foreach ($query->get() as $row) {
            $grouped[(int) $row->product_id][$row->channel] = $row;
        }

        return $grouped;
    }

    public function bustCache(?int $productId = null, ?int $branchId = null): void
    {
        if ($productId) {
            Cache::forget($this->productCacheKey($productId));
        }
        if ($branchId) {
            Cache::forget($this->branchCacheKey($branchId));
        }
        if ($productId === null && $branchId === null) {
            Cache::forget('product_channel_prices:meta');
        }
    }

    public function defaultPrice(Product $product): float
    {
        $raw = $product->getRawOriginal('price');

        return $this->money($raw !== null ? (float) $raw : (float) $product->price);
    }

    public function money(float|int|string|null $value): float
    {
        return round((float) $value, 2);
    }

    /**
     * @return array<string, ProductChannelPrice|null>
     */
    private function channelRowsFor(int $productId, int $branchId): array
    {
        $grouped = $this->channelRowsForProduct($productId, [$branchId]);

        return $grouped[$branchId] ?? [];
    }

    private function posOverrideValue(?ProductChannelPrice $posRow, ?ProductByBranch $branchProduct, float $defaultPrice): ?float
    {
        if ($posRow && $posRow->price !== null) {
            return (float) $posRow->price;
        }
        if ($posRow && $posRow->price === null) {
            return null;
        }
        if ($branchProduct && abs((float) $branchProduct->price - $defaultPrice) > 0.009) {
            return (float) $branchProduct->price;
        }

        return null;
    }

    private function posAvailable(?ProductChannelPrice $posRow, ?ProductByBranch $branchProduct): bool
    {
        if ($posRow) {
            return (int) $posRow->is_available === 1;
        }
        if ($branchProduct) {
            return (int) $branchProduct->is_available === 1;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $change
     * @return list<array<string, mixed>>
     */
    private function applyCellChange(Product $product, int $branchId, string $channel, array $change, float $defaultPrice): array
    {
        $audits = [];
        $branchProduct = ProductByBranch::query()
            ->where('product_id', $product->id)
            ->where('branch_id', $branchId)
            ->first();
        $channelRows = $this->channelRowsFor($product->id, $branchId);
        $discountPayload = $this->discountPayload($product, $branchProduct);
        $before = $this->resolveMatrix($defaultPrice, $branchProduct, $channelRows, $discountPayload);

        $row = ProductChannelPrice::query()->firstOrNew([
            'product_id' => $product->id,
            'branch_id' => $branchId,
            'channel' => $channel,
        ]);

        $resetPrice = ! empty($change['reset_price']);
        $hasPrice = array_key_exists('price', $change);
        $hasAvailable = array_key_exists('is_available', $change);

        if ($resetPrice) {
            $row->price = null;
        } elseif ($hasPrice && $change['price'] !== null && $change['price'] !== '') {
            $price = $this->money($change['price']);
            if (ProductPricingChannels::isMarketplace($channel) || ! empty($change['price_is_selling'])) {
                $price = $this->sellingToUnit($price, $discountPayload);
            }
            $row->price = $price;
        } elseif (! $row->exists) {
            $row->price = null;
        }

        if ($hasAvailable) {
            $row->is_available = ! empty($change['is_available']) ? 1 : 0;
        } elseif (! $row->exists) {
            $row->is_available = $before['available'][$channel] ? 1 : 0;
        }

        $row->save();

        $afterRows = $this->channelRowsFor($product->id, $branchId);
        $afterRows[$channel] = $row;
        if ($channel === ProductPricingChannels::POS) {
            $posPrice = $this->resolvePrice(ProductPricingChannels::POS, $defaultPrice, $row->price, null);
            $branchProduct = $this->syncPosBranchPrice(
                (int) $product->id,
                $branchId,
                $posPrice,
                (int) $row->is_available
            );
        } elseif (! $branchProduct && (int) $row->is_available === 1) {
            $posPrice = $before['prices'][ProductPricingChannels::POS] ?? $defaultPrice;
            $branchProduct = $this->syncPosBranchPrice((int) $product->id, $branchId, $posPrice, 1);
        }

        $after = $this->resolveMatrix($defaultPrice, $branchProduct, $afterRows, $discountPayload);
        $beforeDisplay = $before['display_prices'][$channel] ?? $before['prices'][$channel];
        $afterDisplay = $after['display_prices'][$channel] ?? $after['prices'][$channel];
        if (! empty($change['price_is_selling']) && $channel === ProductPricingChannels::POS) {
            $beforeDisplay = $this->effectiveSellingPrice($before['prices'][$channel] ?? $defaultPrice, $discountPayload);
            $afterDisplay = $this->effectiveSellingPrice($after['prices'][$channel] ?? $defaultPrice, $discountPayload);
        }

        if (abs($beforeDisplay - $afterDisplay) > 0.009 || ($before['overrides'][$channel] !== $after['overrides'][$channel])) {
            $audits[] = [
                'product_id' => $product->id,
                'branch_id' => $branchId,
                'channel' => $channel,
                'field' => 'price',
                'old_value' => $beforeDisplay,
                'new_value' => $resetPrice ? $after['inherited_price'][$channel] ?? $afterDisplay : $afterDisplay,
            ];
        }
        if ($before['available'][$channel] !== $after['available'][$channel]) {
            $audits[] = [
                'product_id' => $product->id,
                'branch_id' => $branchId,
                'channel' => $channel,
                'field' => 'is_available',
                'old_value' => $before['available'][$channel] ? 1 : 0,
                'new_value' => $after['available'][$channel] ? 1 : 0,
            ];
        }

        $this->bustCache((int) $product->id, $branchId);

        return $audits;
    }

    private function propagateDefaultToInheritedPos(Product $product, float $oldDefault, float $newDefault): void
    {
        $branchRows = ProductByBranch::query()->where('product_id', $product->id)->get();
        $channelRows = $this->channelRowsForProduct((int) $product->id, $branchRows->pluck('branch_id')->all());

        foreach ($branchRows as $branchProduct) {
            $posRow = $channelRows[(int) $branchProduct->branch_id][ProductPricingChannels::POS] ?? null;
            if ($posRow && $posRow->price !== null) {
                continue;
            }
            if (! $posRow && abs((float) $branchProduct->price - $oldDefault) > 0.009) {
                continue;
            }
            if (abs((float) $branchProduct->price - $newDefault) <= 0.009) {
                continue;
            }
            $branchProduct->price = $newDefault;
            $branchProduct->save();
        }
    }

    private function productCacheKey(int $productId): string
    {
        return 'product_channel_prices:product:'.$productId;
    }

    private function branchCacheKey(int $branchId): string
    {
        return 'product_channel_prices:branch:'.$branchId;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function categoryOptions(): array
    {
        return Category::query()
            ->where('position', 0)
            ->orderBy('priority')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($category) => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, active: bool}>
     */
    public function branchOptions(): array
    {
        return Branch::query()
            ->orderBy('id')
            ->get(['id', 'name', 'status'])
            ->map(fn ($branch) => [
                'id' => (int) $branch->id,
                'name' => (string) $branch->name,
                'active' => (int) $branch->status === 1,
            ])
            ->values()
            ->all();
    }
}
