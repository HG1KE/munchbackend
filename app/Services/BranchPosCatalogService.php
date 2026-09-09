<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Model\Branch;
use App\Model\Category;
use App\Model\Product;
use App\Model\ProductByBranch;
use App\Model\Table;
use App\Models\DeliveryChargeByArea;
use App\Support\PosOrderTypes;
use Illuminate\Support\Facades\DB;

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
            ->leftJoinSub($this->posSoldSubquery($branchId), 'pos_sold', 'pos_sold.product_id', '=', 'products.id')
            ->orderByRaw('COALESCE(pos_sold.qty_sold, 0) DESC')
            ->orderBy('products.name')
            ->select('products.*')
            ->get();

        $mappedProducts = [];
        foreach ($products as $product) {
            $branchProduct = $product->product_by_branch->first();
            if (! $branchProduct) {
                continue;
            }

            $variations = $this->normalizeVariations($branchProduct->variations);
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
                'has_modifiers' => $variations !== [],
                'variations' => $variations,
            ];
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
            'tables' => $tables,
            'delivery' => $this->deliverySetup($branchId),
        ];
    }

    /**
     * Lightweight fingerprint of price, availability, categories, and variations.
     */
    public function versionForBranch(int $branchId): string
    {
        $branch = ProductByBranch::query()
            ->where('branch_id', $branchId)
            ->selectRaw('MAX(updated_at) as u, COUNT(*) as c, SUM(is_available) as a, SUM(price) as p, SUM(discount) as d')
            ->first();
        $productMax = Product::query()->max('updated_at');
        $categoryMax = Category::query()->max('updated_at');
        $categoryCount = Category::query()->where(['position' => 0])->count();

        return hash('sha256', implode('|', [
            $branchId,
            'pos-catalog-popularity-1',
            (string) ($branch->u ?? ''),
            (string) ($branch->c ?? 0),
            (string) ($branch->a ?? 0),
            (string) ($branch->p ?? 0),
            (string) ($branch->d ?? 0),
            (string) $productMax,
            (string) $categoryMax,
            (string) $categoryCount,
            (string) $this->posSoldStamp($branchId),
        ]));
    }

    /**
     * Completed POS unit sales for this branch, one grouped query.
     */
    private function posSoldSubquery(int $branchId)
    {
        return DB::table('order_details')
            ->join('orders', 'orders.id', '=', 'order_details.order_id')
            ->where('orders.branch_id', $branchId)
            ->whereIn('orders.sales_channel', PosOrderTypes::salesChannels())
            ->whereIn('orders.order_status', ['delivered', 'completed'])
            ->whereNotNull('order_details.product_id')
            ->groupBy('order_details.product_id')
            ->selectRaw('order_details.product_id, SUM(order_details.quantity) as qty_sold');
    }

    private function posSoldStamp(int $branchId): string
    {
        return (string) (DB::table('orders')
            ->where('branch_id', $branchId)
            ->whereIn('sales_channel', PosOrderTypes::salesChannels())
            ->whereIn('order_status', ['delivered', 'completed'])
            ->max('updated_at') ?: '');
    }

    /**
     * Parent and child category ids stored on the POS product (`category_ids` JSON).
     *
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
            if (is_array($row) && isset($row['id'])) {
                $ids[] = (int) $row['id'];
            } elseif (is_numeric($row)) {
                $ids[] = (int) $row;
            }
        }

        return array_values(array_unique(array_filter($ids)));
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
