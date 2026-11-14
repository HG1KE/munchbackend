<?php

namespace App\Services\Stock;

/**
 * Allocation coverage UI was retired: products/addons with no stock allocations
 * are intentionally not stock-tracked. Sold only deducts for allocated items
 * ({@see StockDeductionService}); missing allocations are not a sync failure.
 *
 * Controllers still call {@see gapsForBranchDate} so blade alerts stay inert.
 */
class StockAllocationCoverageService
{
    /**
     * Always empty — untracked SKUs must not surface as "need allocations".
     *
     * @return array{
     *   products: list<array{product_id:int,name:string,qty_sold:float}>,
     *   addons: list<array{addon_id:int,name:string,qty_sold:float}>
     * }
     */
    public function gapsForBranchDate(int $branchId, string $date): array
    {
        return ['products' => [], 'addons' => []];
    }
}
