<?php

namespace App\Services\Stock;

use App\Model\Product;
use App\Model\StockItem;
use App\Model\VariationStockAllocation;
use App\Support\ProductVariationPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class VariationStockAllocationService
{
    /**
     * Products that have at least one variation option (for the bulk list page).
     *
     * @return Collection<int, array{id:int,name:string,status:int,option_count:int,allocated_option_count:int,options:list<array{id:string,group:string,label:string,allocation_count:int}>}>
     */
    public function listProductsWithVariations(?string $search = null): Collection
    {
        if (! Schema::hasTable('products')) {
            return collect();
        }

        $query = Product::query()->orderBy('name');
        if ($search !== null && trim($search) !== '') {
            $query->where('name', 'like', '%'.trim($search).'%');
        }

        $allocationCounts = [];
        if (Schema::hasTable('variation_stock_allocations')) {
            $allocationCounts = VariationStockAllocation::query()
                ->selectRaw('product_id, option_key, COUNT(*) as allocation_count')
                ->groupBy('product_id', 'option_key')
                ->get()
                ->groupBy('product_id')
                ->map(fn ($rows) => $rows->keyBy('option_key'))
                ->all();
        }

        $out = collect();
        foreach ($query->get(['id', 'name', 'status', 'variations']) as $product) {
            $options = ProductVariationPricing::flatOptions($product->variations);
            if ($options === []) {
                continue;
            }

            $countsForProduct = $allocationCounts[(int) $product->id] ?? collect();
            $mapped = [];
            $allocatedOptions = 0;
            foreach ($options as $option) {
                $countRow = $countsForProduct[$option['id']] ?? null;
                $count = (int) ($countRow->allocation_count ?? 0);
                if ($count > 0) {
                    $allocatedOptions++;
                }
                $mapped[] = [
                    'id' => $option['id'],
                    'group' => $option['group'],
                    'label' => $option['label'],
                    'allocation_count' => $count,
                ];
            }

            $out->push([
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'status' => (int) $product->status,
                'option_count' => count($mapped),
                'allocated_option_count' => $allocatedOptions,
                'options' => $mapped,
            ]);
        }

        return $out;
    }

    /**
     * Payload for the variation stock modal (all options for one product).
     *
     * @return array{
     *   product: array{id:int,name:string},
     *   stock_items: list<array{id:int,name:string}>,
     *   options: list<array{id:string,group:string,label:string,allocations:list<array{stock_item_id:int,quantity:float}>}>
     * }
     */
    public function payloadForProduct(Product $product): array
    {
        $options = ProductVariationPricing::flatOptions($product->variations);
        $optionKeys = array_map(fn ($o) => $o['id'], $options);

        $allocationsByKey = [];
        if (Schema::hasTable('variation_stock_allocations') && $optionKeys !== []) {
            $rows = VariationStockAllocation::query()
                ->where('product_id', $product->id)
                ->whereIn('option_key', $optionKeys)
                ->orderBy('id')
                ->get(['option_key', 'stock_item_id', 'quantity']);

            foreach ($rows as $row) {
                $key = (string) $row->option_key;
                if (! isset($allocationsByKey[$key])) {
                    $allocationsByKey[$key] = [];
                }
                $allocationsByKey[$key][] = [
                    'stock_item_id' => (int) $row->stock_item_id,
                    'quantity' => (float) $row->quantity,
                ];
            }
        }

        $allocatedIds = [];
        foreach ($allocationsByKey as $rows) {
            foreach ($rows as $row) {
                $allocatedIds[] = (int) $row['stock_item_id'];
            }
        }
        $allocatedIds = array_values(array_unique($allocatedIds));

        $stockItems = [];
        if (Schema::hasTable('stock_items')) {
            $stockItems = StockItem::query()
                ->where(function ($query) use ($allocatedIds) {
                    $query->where('status', true);
                    if ($allocatedIds !== []) {
                        $query->orWhereIn('id', $allocatedIds);
                    }
                })
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (StockItem $item) => [
                    'id' => (int) $item->id,
                    'name' => (string) $item->name,
                ])
                ->values()
                ->all();
        }

        return [
            'product' => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
            ],
            'stock_items' => $stockItems,
            'options' => array_map(fn (array $option) => [
                'id' => $option['id'],
                'group' => $option['group'],
                'label' => $option['label'],
                'allocations' => $allocationsByKey[$option['id']] ?? [],
            ], $options),
        ];
    }

    /**
     * Replace variation ↔ stock allocations for a product from request arrays:
     * variation_option_key[] + stock_allocation_item_id[] + stock_allocation_qty[]
     *
     * Only option keys that still exist on the product catalogue are written;
     * stale keys for removed options are cleared with the product wipe.
     */
    public function syncFromRequest(int $productId, Request $request, ?Product $product = null): void
    {
        if (! Schema::hasTable('variation_stock_allocations')) {
            return;
        }

        $product ??= Product::query()->find($productId);
        $validKeys = [];
        $metaByKey = [];
        if ($product) {
            foreach (ProductVariationPricing::flatOptions($product->variations) as $option) {
                $validKeys[$option['id']] = true;
                $metaByKey[$option['id']] = [
                    'group_name' => $option['group'],
                    'option_label' => $option['label'],
                ];
            }
        }

        $optionKeys = $request->input('variation_option_key', []);
        $itemIds = $request->input('stock_allocation_item_id', []);
        $qtys = $request->input('stock_allocation_qty', []);
        if (! is_array($optionKeys)) {
            $optionKeys = [];
        }
        if (! is_array($itemIds)) {
            $itemIds = [];
        }
        if (! is_array($qtys)) {
            $qtys = [];
        }

        // option_key => [stock_item_id => qty]
        $desired = [];
        foreach ($optionKeys as $index => $rawKey) {
            $optionKey = (string) $rawKey;
            if ($optionKey === '' || ! isset($validKeys[$optionKey])) {
                continue;
            }
            $stockItemId = (int) ($itemIds[$index] ?? 0);
            if ($stockItemId <= 0) {
                continue;
            }
            $qty = isset($qtys[$index]) ? (float) $qtys[$index] : 0.0;
            if ($qty <= 0) {
                continue;
            }
            if (! isset($desired[$optionKey])) {
                $desired[$optionKey] = [];
            }
            // Last row wins for duplicate stock items on the same option.
            $desired[$optionKey][$stockItemId] = round($qty, 3);
        }

        VariationStockAllocation::query()->where('product_id', $productId)->delete();

        $now = now();
        foreach ($desired as $optionKey => $items) {
            $meta = $metaByKey[$optionKey] ?? ['group_name' => null, 'option_label' => null];
            foreach ($items as $stockItemId => $qty) {
                VariationStockAllocation::query()->create([
                    'product_id' => $productId,
                    'option_key' => $optionKey,
                    'group_name' => $meta['group_name'],
                    'option_label' => $meta['option_label'],
                    'stock_item_id' => $stockItemId,
                    'quantity' => $qty,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Selected variation option keys on an order detail (product_id scoped by caller).
     * Prefers `variation`, falls back to `variant`. Unallocated options stay untracked.
     *
     * @return list<string>
     */
    public function selectedOptionKeysFromDetail(object $detail): array
    {
        $keys = $this->optionKeysFromVariationPayload($detail->variation ?? null);
        if ($keys !== []) {
            return $keys;
        }

        return $this->optionKeysFromVariationPayload($detail->variant ?? null);
    }

    /**
     * @return list<string>
     */
    public function optionKeysFromVariationPayload(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '' || $trimmed === '[]' || strcasecmp($trimmed, 'null') === 0) {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            if (! is_array($decoded)) {
                return [];
            }
            $raw = $decoded;
        }
        if (! is_array($raw)) {
            return [];
        }

        $keys = [];
        foreach ($raw as $group) {
            if (! is_array($group)) {
                continue;
            }
            $groupName = trim((string) ($group['name'] ?? ''));
            if ($groupName === '') {
                continue;
            }

            $values = $group['values'] ?? null;
            if (is_array($values) && array_key_exists('label', $values)) {
                foreach ((array) $values['label'] as $label) {
                    $label = trim((string) $label);
                    if ($label !== '') {
                        $keys[] = ProductVariationPricing::optionKey($groupName, $label);
                    }
                }
                continue;
            }
            if (is_array($values)) {
                foreach ($values as $value) {
                    if (is_array($value) && isset($value['label'])) {
                        $label = trim((string) $value['label']);
                        if ($label !== '') {
                            $keys[] = ProductVariationPricing::optionKey($groupName, $label);
                        }
                    } elseif (is_string($value) && trim($value) !== '') {
                        $keys[] = ProductVariationPricing::optionKey($groupName, trim($value));
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
