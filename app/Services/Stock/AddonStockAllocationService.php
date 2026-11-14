<?php

namespace App\Services\Stock;

use App\Model\AddOn;
use App\Model\AddonStockAllocation;
use App\Model\StockItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AddonStockAllocationService
{
    /**
     * Payload for addon create/edit forms (mirrors product stock popup shape).
     *
     * @return array{addon: array{id:int,name:string}, stock_items: list<array{id:int,name:string}>, allocations: list<array{stock_item_id:int,quantity:float}>}
     */
    public function payloadForAddon(AddOn $addon): array
    {
        if (! Schema::hasTable('addon_stock_allocations') || ! Schema::hasTable('stock_items')) {
            return [
                'addon' => [
                    'id' => (int) $addon->id,
                    'name' => (string) $addon->name,
                ],
                'stock_items' => [],
                'allocations' => [],
            ];
        }

        $allocations = AddonStockAllocation::query()
            ->where('addon_id', $addon->id)
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
            'addon' => [
                'id' => (int) $addon->id,
                'name' => (string) $addon->name,
            ],
            'stock_items' => $stockItems->map(fn (StockItem $item) => [
                'id' => (int) $item->id,
                'name' => (string) $item->name,
            ])->values()->all(),
            'allocations' => $allocations->map(fn (AddonStockAllocation $row) => [
                'stock_item_id' => (int) $row->stock_item_id,
                'quantity' => (float) $row->quantity,
            ])->values()->all(),
        ];
    }

    /**
     * Replace addon ↔ stock allocations from request arrays:
     * stock_allocation_item_id[] + stock_allocation_qty[]
     * (same field names as products so the shared partial works).
     */
    public function syncFromRequest(int $addonId, Request $request): void
    {
        if (! Schema::hasTable('addon_stock_allocations')) {
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

        AddonStockAllocation::query()->where('addon_id', $addonId)->delete();

        $now = now();
        foreach ($desired as $stockItemId => $qty) {
            AddonStockAllocation::query()->create([
                'addon_id' => $addonId,
                'stock_item_id' => $stockItemId,
                'quantity' => $qty,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
