<?php

namespace App\Services\Stock;

use App\Model\Product;
use App\Model\ProductStockAllocation;
use App\Model\StockItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ProductStockAllocationService
{
    /**
     * Payload for the product-list Stock popup (and create/edit forms).
     *
     * @return array{product: array{id:int,name:string}, stock_items: list<array{id:int,name:string}>, allocations: list<array{stock_item_id:int,quantity:float}>}
     */
    public function payloadForProduct(Product $product): array
    {
        if (! Schema::hasTable('product_stock_allocations') || ! Schema::hasTable('stock_items')) {
            return [
                'product' => [
                    'id' => (int) $product->id,
                    'name' => (string) $product->name,
                ],
                'stock_items' => [],
                'allocations' => [],
            ];
        }

        $allocations = ProductStockAllocation::query()
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->get(['stock_item_id', 'quantity']);

        $allocatedIds = $allocations->pluck('stock_item_id')->map(fn ($id) => (int) $id)->all();

        $stockItems = StockItem::query()
            ->where(function ($query) use ($allocatedIds) {
                $query->where('status', true);
                if ($allocatedIds !== []) {
                    $query->orWhereIn('id', $allocatedIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        return [
            'product' => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
            ],
            'stock_items' => $stockItems->map(fn (StockItem $item) => [
                'id' => (int) $item->id,
                'name' => (string) $item->name,
            ])->values()->all(),
            'allocations' => $allocations->map(fn (ProductStockAllocation $row) => [
                'stock_item_id' => (int) $row->stock_item_id,
                'quantity' => (float) $row->quantity,
            ])->values()->all(),
        ];
    }

    /**
     * Replace product ↔ stock allocations from request arrays:
     * stock_allocation_item_id[] + stock_allocation_qty[]
     */
    public function syncFromRequest(int $productId, Request $request): void
    {
        if (! Schema::hasTable('product_stock_allocations')) {
            return;
        }

        $itemIds = $request->input('stock_allocation_item_id', []);
        $qtys = $request->input('stock_allocation_qty', []);
        if (! is_array($itemIds)) {
            $itemIds = [];
        }
        if (! is_array($qtys)) {
            $qtys = [];
        }

        $desired = [];
        foreach ($itemIds as $index => $rawId) {
            $stockItemId = (int) $rawId;
            if ($stockItemId <= 0) {
                continue;
            }
            $qty = isset($qtys[$index]) ? (float) $qtys[$index] : 0.0;
            if ($qty <= 0) {
                continue;
            }
            // Last row wins for duplicate stock items.
            $desired[$stockItemId] = round($qty, 3);
        }

        ProductStockAllocation::query()->where('product_id', $productId)->delete();

        $now = now();
        foreach ($desired as $stockItemId => $qty) {
            ProductStockAllocation::query()->create([
                'product_id' => $productId,
                'stock_item_id' => $stockItemId,
                'quantity' => $qty,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
