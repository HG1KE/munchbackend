<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Model\AddOn;
use App\Model\Branch;
use App\Model\Category;
use App\Model\Product;
use App\Model\ProductByBranch;
use App\Model\Table;
use App\Models\DeliveryChargeByArea;

class BranchPosCatalogService
{
    /**
     * Compact catalog for the offline-first Branch POS. Cached in IndexedDB on the client.
     *
     * @return array<string, mixed>
     */
    public function forBranch(int $branchId): array
    {
        $categories = Category::query()
            ->where(['position' => 0])
            ->active()
            ->orderBy('priority')
            ->get(['id', 'name'])
            ->map(fn ($category) => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
            ])
            ->values()
            ->all();

        $products = Product::query()
            ->with(['product_by_branch' => function ($query) use ($branchId) {
                $query->where(['is_available' => 1, 'branch_id' => $branchId]);
            }])
            ->whereHas('product_by_branch', function ($query) use ($branchId) {
                $query->where(['is_available' => 1, 'branch_id' => $branchId]);
            })
            ->active()
            ->latest()
            ->get();

        $addonIds = [];
        $mappedProducts = [];
        foreach ($products as $product) {
            $branchProduct = $product->product_by_branch->first();
            if (! $branchProduct) {
                continue;
            }

            $ids = json_decode((string) $product->add_ons, true);
            $ids = is_array($ids) ? array_map('intval', $ids) : [];
            foreach ($ids as $id) {
                if ($id > 0) {
                    $addonIds[$id] = true;
                }
            }

            $price = (float) $branchProduct->price;
            $discountData = [
                'discount_type' => $branchProduct->discount_type,
                'discount' => (float) $branchProduct->discount,
            ];
            $discountAmount = (float) Helpers::discount_calculate($discountData, $price);

            $mappedProducts[] = [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'image' => (string) $product->image_full_path,
                'category_ids' => $this->categoryIds($product),
                'price' => $price,
                'discount' => $discountAmount,
                'discount_data' => $discountData,
                'has_modifiers' => $this->hasModifiers($branchProduct->variations, $ids),
                'variations' => $this->normalizeVariations($branchProduct->variations),
                'addon_ids' => $ids,
            ];
        }

        $addons = [];
        if ($addonIds !== []) {
            $addons = AddOn::withoutGlobalScopes()
                ->whereIn('id', array_keys($addonIds))
                ->get(['id', 'name', 'price', 'tax'])
                ->map(fn ($addon) => [
                    'id' => (int) $addon->id,
                    'name' => (string) $addon->getRawOriginal('name') ?: (string) $addon->name,
                    'price' => (float) $addon->price,
                    'tax' => (float) ($addon->tax ?? 0),
                ])
                ->values()
                ->all();
        }

        $tables = Table::query()
            ->where('branch_id', $branchId)
            ->get(['id', 'number', 'capacity'])
            ->map(fn ($table) => [
                'id' => (int) $table->id,
                'number' => (string) $table->number,
                'capacity' => (int) $table->capacity,
            ])
            ->values()
            ->all();

        $currencySymbol = 'Ksh';
        try {
            $currencySymbol = Helpers::currency_symbol();
        } catch (\Throwable) {
            // Keep a visible fallback so POS still renders if currency settings are incomplete.
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'version' => $this->versionForBranch($branchId),
            'branch_id' => $branchId,
            'currency_symbol' => $currencySymbol,
            'currency_position' => Helpers::get_business_settings('currency_symbol_position') ?: 'left',
            'decimal' => (int) (Helpers::get_business_settings('decimal_point_settings') ?? 0),
            'placeholder_image' => asset('public/assets/admin/img/160x160/img2.jpg'),
            'categories' => $categories,
            'products' => $mappedProducts,
            'addons' => $addons,
            'tables' => $tables,
            'delivery' => $this->deliverySetup($branchId),
        ];
    }

    /**
     * Lightweight fingerprint of price, availability, categories, add-ons, and modifiers.
     */
    public function versionForBranch(int $branchId): string
    {
        $branch = ProductByBranch::query()
            ->where('branch_id', $branchId)
            ->selectRaw('MAX(updated_at) as u, COUNT(*) as c, SUM(is_available) as a, SUM(price) as p, SUM(discount) as d')
            ->first();
        $productMax = Product::query()->max('updated_at');
        $addon = AddOn::withoutGlobalScopes()
            ->selectRaw('MAX(updated_at) as u, COUNT(*) as c, SUM(price) as p')
            ->first();
        $categoryMax = Category::query()->max('updated_at');
        $categoryCount = Category::query()->where(['position' => 0])->count();

        return hash('sha256', implode('|', [
            $branchId,
            (string) ($branch->u ?? ''),
            (string) ($branch->c ?? 0),
            (string) ($branch->a ?? 0),
            (string) ($branch->p ?? 0),
            (string) ($branch->d ?? 0),
            (string) $productMax,
            (string) ($addon->u ?? ''),
            (string) ($addon->c ?? 0),
            (string) ($addon->p ?? 0),
            (string) $categoryMax,
            (string) $categoryCount,
        ]));
    }

    /**
     * @return list<int>
     */
    private function categoryIds(Product $product): array
    {
        $decoded = json_decode((string) $product->getRawOriginal('category_ids') ?: (string) $product->category_ids, true);
        if (! is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $row) {
            if (is_array($row) && isset($row['id']) && (int) ($row['position'] ?? 0) === 0) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * @param  mixed  $variations
     * @param  list<int>  $addonIds
     */
    private function hasModifiers($variations, array $addonIds): bool
    {
        return $addonIds !== [] || $this->normalizeVariations($variations) !== [];
    }

    /**
     * @param  mixed  $variations
     * @return list<array<string, mixed>>
     */
    private function normalizeVariations($variations): array
    {
        if (! is_array($variations)) {
            return [];
        }

        $out = [];
        foreach ($variations as $choice) {
            $choice = (array) $choice;
            if (array_key_exists('price', $choice)) {
                continue;
            }
            $values = [];
            foreach (($choice['values'] ?? []) as $option) {
                $option = (array) $option;
                $values[] = [
                    'label' => (string) ($option['label'] ?? ''),
                    'optionPrice' => (float) ($option['optionPrice'] ?? 0),
                ];
            }
            $out[] = [
                'name' => (string) ($choice['name'] ?? ''),
                'type' => (string) ($choice['type'] ?? 'single'),
                'required' => (($choice['required'] ?? '') === 'on') ? 'on' : 'off',
                'min' => (int) ($choice['min'] ?? 0),
                'max' => (int) ($choice['max'] ?? 0),
                'values' => $values,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function deliverySetup(int $branchId): array
    {
        $branch = Branch::with(['delivery_charge_setup', 'delivery_charge_by_area'])->find($branchId);
        $setup = $branch?->delivery_charge_setup;
        $type = $setup->delivery_charge_type ?? 'fixed';
        if (! in_array($type, ['fixed', 'distance', 'area'], true)) {
            $type = 'fixed';
        }

        $areas = DeliveryChargeByArea::query()
            ->where('branch_id', $branchId)
            ->get(['id', 'area_name', 'delivery_charge'])
            ->map(fn ($area) => [
                'id' => (int) $area->id,
                'name' => (string) $area->area_name,
                'charge' => (float) $area->delivery_charge,
            ])
            ->values()
            ->all();

        return [
            'type' => $type,
            'fixed_charge' => (float) ($setup->fixed_delivery_charge ?? 0),
            'minimum_charge' => (float) ($setup->minimum_delivery_charge ?? 0),
            'per_km' => (float) ($setup->delivery_charge_per_kilometer ?? 0),
            'free_over_status' => (int) ($setup->free_delivery_over_status ?? 0),
            'free_over_amount' => (float) ($setup->free_delivery_over_amount ?? 0),
            'areas' => $areas,
        ];
    }
}
