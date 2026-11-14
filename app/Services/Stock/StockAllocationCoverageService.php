<?php

namespace App\Services\Stock;

use App\Model\AddonStockAllocation;
use App\Model\Order;
use App\Model\ProductStockAllocation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flags POS kitchen-sold products/addons that have sales but zero stock allocations
 * (Sold understates → false "missing" on counts).
 */
class StockAllocationCoverageService
{
    /**
     * @return array{
     *   products: list<array{product_id:int,name:string,qty_sold:float}>,
     *   addons: list<array{addon_id:int,name:string,qty_sold:float}>
     * }
     */
    public function gapsForBranchDate(int $branchId, string $date): array
    {
        $products = [];
        $addons = [];

        if ($branchId <= 0 || ! Schema::hasTable('orders') || ! Schema::hasTable('order_details')) {
            return ['products' => $products, 'addons' => $addons];
        }

        $day = Carbon::parse($date, config('app.timezone'))->toDateString();
        $orderIds = $this->posSoldOrderIds($branchId, $day);
        if ($orderIds === []) {
            return ['products' => $products, 'addons' => $addons];
        }

        if (Schema::hasTable('product_stock_allocations')) {
            $soldProducts = DB::table('order_details')
                ->selectRaw('product_id, SUM(quantity) as qty_sold')
                ->whereIn('order_id', $orderIds)
                ->where('product_id', '>', 0)
                ->groupBy('product_id')
                ->get();

            $allocatedIds = ProductStockAllocation::query()
                ->distinct()
                ->pluck('product_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $allocatedSet = array_fill_keys($allocatedIds, true);

            $names = Schema::hasTable('products')
                ? DB::table('products')->whereIn('id', $soldProducts->pluck('product_id'))->pluck('name', 'id')
                : collect();

            foreach ($soldProducts as $row) {
                $productId = (int) $row->product_id;
                if ($productId <= 0 || isset($allocatedSet[$productId])) {
                    continue;
                }
                $products[] = [
                    'product_id' => $productId,
                    'name' => (string) ($names[$productId] ?? ('Product #'.$productId)),
                    'qty_sold' => round((float) $row->qty_sold, 3),
                ];
            }
        }

        if (Schema::hasTable('addon_stock_allocations') && Schema::hasColumn('order_details', 'add_on_ids')) {
            $details = DB::table('order_details')
                ->whereIn('order_id', $orderIds)
                ->get(['add_on_ids', 'add_on_qtys']);

            $addonQtys = [];
            $deduction = app(StockDeductionService::class);
            foreach ($details as $detail) {
                foreach ($deduction->addonQuantitiesFromDetail($detail) as $addonId => $qty) {
                    $addonQtys[$addonId] = ($addonQtys[$addonId] ?? 0.0) + $qty;
                }
            }

            if ($addonQtys !== []) {
                $allocatedAddonIds = AddonStockAllocation::query()
                    ->distinct()
                    ->pluck('addon_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $allocatedAddonSet = array_fill_keys($allocatedAddonIds, true);

                $addonNames = Schema::hasTable('add_ons')
                    ? DB::table('add_ons')->whereIn('id', array_keys($addonQtys))->pluck('name', 'id')
                    : collect();

                foreach ($addonQtys as $addonId => $qty) {
                    if (isset($allocatedAddonSet[$addonId])) {
                        continue;
                    }
                    $addons[] = [
                        'addon_id' => (int) $addonId,
                        'name' => (string) ($addonNames[$addonId] ?? ('Addon #'.$addonId)),
                        'qty_sold' => round((float) $qty, 3),
                    ];
                }
            }
        }

        usort($products, fn ($a, $b) => $b['qty_sold'] <=> $a['qty_sold']);
        usort($addons, fn ($a, $b) => $b['qty_sold'] <=> $a['qty_sold']);

        return ['products' => $products, 'addons' => $addons];
    }

    /**
     * @return list<int>
     */
    private function posSoldOrderIds(int $branchId, string $day): array
    {
        $tz = config('app.timezone');
        $start = Carbon::parse($day, $tz)->startOfDay();
        $end = $start->copy()->endOfDay();
        // Wide window then filter in PHP for TZ edges (same idea as backfill).
        $sqlFloor = $start->copy()->subDay()->format('Y-m-d H:i:s');
        $sqlCeil = $end->copy()->addDay()->format('Y-m-d H:i:s');

        $orders = Order::query()
            ->where('branch_id', $branchId)
            ->whereIn('order_status', StockDeductionService::SOLD_STATUSES)
            ->where(function ($q) use ($sqlFloor, $sqlCeil) {
                $q->whereBetween('placed_at', [$sqlFloor, $sqlCeil])
                    ->orWhere(function ($q2) use ($sqlFloor, $sqlCeil) {
                        $q2->whereNull('placed_at')->whereBetween('created_at', [$sqlFloor, $sqlCeil]);
                    });
            })
            ->get(['id', 'order_type', 'sales_channel', 'placed_at', 'created_at']);

        $deduction = app(StockDeductionService::class);
        $ids = [];
        foreach ($orders as $order) {
            if (! $deduction->countsTowardSold($order)) {
                continue;
            }
            if ($deduction->saleDateFor($order) !== $day) {
                continue;
            }
            $ids[] = (int) $order->id;
        }

        return $ids;
    }
}
