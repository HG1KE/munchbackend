<?php

namespace App\Services\Stock;

use App\Model\StockDailyCount;
use App\Model\StockItem;
use App\Model\StockSaleDeduction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class StockVarianceService
{
    /**
     * Rows for a branch + date: each active stock item with opening/closing,
     * physical sold, expected sold from deductions, and variance label.
     *
     * @return list<array{
     *   stock_item_id:int,
     *   name:string,
     *   opening_qty:float|null,
     *   closing_qty:float|null,
     *   physical_sold:float|null,
     *   expected_sold:float,
     *   variance:float|null,
     *   variance_label:string
     * }>
     */
    public function rowsForBranchDate(int $branchId, string $date): array
    {
        if (! Schema::hasTable('stock_items')) {
            return [];
        }

        $items = StockItem::query()->active()->orderBy('name')->get(['id', 'name']);
        $counts = collect();
        if (Schema::hasTable('stock_daily_counts')) {
            $counts = StockDailyCount::query()
                ->where('branch_id', $branchId)
                ->whereDate('count_date', $date)
                ->get()
                ->keyBy('stock_item_id');
        }

        $expectedByItem = $this->expectedSoldByItem($branchId, $date);

        $rows = [];
        foreach ($items as $item) {
            /** @var StockDailyCount|null $count */
            $count = $counts->get($item->id);
            $opening = $count ? (float) $count->opening_qty : null;
            $closing = $count && $count->closing_qty !== null ? (float) $count->closing_qty : null;
            $physical = ($opening !== null && $closing !== null)
                ? round($opening - $closing, 3)
                : null;
            $expected = round((float) ($expectedByItem[$item->id] ?? 0), 3);
            $variance = $physical === null ? null : round($physical - $expected, 3);

            $rows[] = [
                'stock_item_id' => (int) $item->id,
                'name' => (string) $item->name,
                'opening_qty' => $opening,
                'closing_qty' => $closing,
                'physical_sold' => $physical,
                'expected_sold' => $expected,
                'variance' => $variance,
                'variance_label' => $this->formatVarianceLabel($variance),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, float> stock_item_id => expected qty
     */
    public function expectedSoldByItem(int $branchId, string $date): array
    {
        if (! Schema::hasTable('stock_sale_deductions')) {
            return [];
        }

        /** @var Collection<int, object> $rows */
        $rows = StockSaleDeduction::query()
            ->selectRaw('stock_item_id, SUM(quantity) as total_qty')
            ->where('branch_id', $branchId)
            ->whereDate('sale_date', $date)
            ->groupBy('stock_item_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->stock_item_id] = (float) $row->total_qty;
        }

        return $out;
    }

    public function formatVarianceLabel(?float $variance): string
    {
        if ($variance === null) {
            return '—';
        }

        $abs = abs($variance);
        $display = rtrim(rtrim(number_format($abs, 3, '.', ''), '0'), '.');
        if ($display === '') {
            $display = '0';
        }

        if (abs($variance) < 0.0005) {
            return 'balanced';
        }

        if ($variance > 0) {
            return $display . ' excess';
        }

        return $display . ' missing';
    }

    /**
     * Pure helpers for unit tests without DB.
     */
    public static function physicalSold(float $opening, ?float $closing): ?float
    {
        if ($closing === null) {
            return null;
        }

        return round($opening - $closing, 3);
    }

    public static function variance(?float $physicalSold, float $expectedSold): ?float
    {
        if ($physicalSold === null) {
            return null;
        }

        return round($physicalSold - $expectedSold, 3);
    }
}
