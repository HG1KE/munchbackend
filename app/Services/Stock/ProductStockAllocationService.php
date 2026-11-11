<?php

namespace App\Services\Stock;

use App\Model\ProductStockAllocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ProductStockAllocationService
{
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
