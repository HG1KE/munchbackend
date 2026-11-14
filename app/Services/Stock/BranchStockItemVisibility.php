<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which active stock items belong on a branch recon table / manager PDF.
 *
 * Branch product disable lives on product_by_branches.is_available (0 = not sold
 * at that branch). Products also need products.status = 1.
 *
 * Rules for a stock item at a branch:
 * - No product/addon allocations → keep (manually tracked / unallocated).
 * - Any addon allocation → keep (addons are not branch-gated).
 * - Otherwise keep only if at least one allocating product is enabled/sold
 *   at the branch; if every allocating product is disabled/unavailable there,
 *   omit.
 */
class BranchStockItemVisibility
{
    /**
     * @param  list<int>  $activeStockItemIds
     * @return list<int>
     */
    public function filterForBranch(int $branchId, array $activeStockItemIds): array
    {
        $activeStockItemIds = array_values(array_unique(array_map('intval', $activeStockItemIds)));
        if ($branchId <= 0 || $activeStockItemIds === []) {
            return $activeStockItemIds;
        }

        $hasProductAlloc = Schema::hasTable('product_stock_allocations');
        $hasAddonAlloc = Schema::hasTable('addon_stock_allocations');
        if (! $hasProductAlloc && ! $hasAddonAlloc) {
            return $activeStockItemIds;
        }

        if ($hasProductAlloc && (! Schema::hasTable('product_by_branches') || ! Schema::hasTable('products'))) {
            // Cannot evaluate branch availability — fail open.
            return $activeStockItemIds;
        }

        /** @var array<int, list<int>> $productsByItem */
        $productsByItem = [];
        if ($hasProductAlloc) {
            $rows = DB::table('product_stock_allocations')
                ->whereIn('stock_item_id', $activeStockItemIds)
                ->get(['stock_item_id', 'product_id']);
            foreach ($rows as $row) {
                $itemId = (int) $row->stock_item_id;
                $productsByItem[$itemId][] = (int) $row->product_id;
            }
        }

        /** @var array<int, true> $addonItemSet */
        $addonItemSet = [];
        if ($hasAddonAlloc) {
            $addonIds = DB::table('addon_stock_allocations')
                ->whereIn('stock_item_id', $activeStockItemIds)
                ->distinct()
                ->pluck('stock_item_id');
            foreach ($addonIds as $id) {
                $addonItemSet[(int) $id] = true;
            }
        }

        /** @var array<int, true> $enabledProductSet */
        $enabledProductSet = [];
        if ($hasProductAlloc) {
            $enabledIds = DB::table('product_by_branches as pbb')
                ->join('products as p', 'p.id', '=', 'pbb.product_id')
                ->where('pbb.branch_id', $branchId)
                ->where('pbb.is_available', 1)
                ->where('p.status', 1)
                ->pluck('pbb.product_id');
            foreach ($enabledIds as $id) {
                $enabledProductSet[(int) $id] = true;
            }
        }

        $visible = [];
        foreach ($activeStockItemIds as $itemId) {
            $productIds = $productsByItem[$itemId] ?? [];
            $hasProductAllocations = $productIds !== [];
            $hasAddonAllocations = isset($addonItemSet[$itemId]);

            if (! $hasProductAllocations && ! $hasAddonAllocations) {
                $visible[] = $itemId;
                continue;
            }

            if ($hasAddonAllocations) {
                $visible[] = $itemId;
                continue;
            }

            foreach ($productIds as $productId) {
                if (isset($enabledProductSet[$productId])) {
                    $visible[] = $itemId;
                    continue 2;
                }
            }
            // Only disabled / not-sold-at-branch product allocations → omit.
        }

        return $visible;
    }

    /**
     * @return array<int, true>
     */
    public function visibleIdSet(int $branchId, array $activeStockItemIds): array
    {
        return array_fill_keys($this->filterForBranch($branchId, $activeStockItemIds), true);
    }
}
