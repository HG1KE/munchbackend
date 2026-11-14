<?php

namespace App\Services\Stock;

use App\Model\StockDailyCount;
use App\Model\StockItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class StockOpeningContinuityService
{
    public const TOLERANCE = 0.001;

    public const WARN_MESSAGE = 'Today\'s opening does not match yesterday\'s closing for one or more items. Confirm the gap or adjust opening before saving/exporting.';

    /**
     * Compare today's opening (posted or saved) to yesterday's closing.
     *
     * @param  array{opening?:array}|null  $posted
     * @return list<array{
     *   stock_item_id:int,
     *   name:string,
     *   yesterday_closing:float,
     *   today_opening:float,
     *   delta:float
     * }>
     */
    public function mismatches(int $branchId, string $date, ?array $posted = null): array
    {
        if ($branchId <= 0 || ! Schema::hasTable('stock_daily_counts') || ! Schema::hasTable('stock_items')) {
            return [];
        }

        $today = Carbon::parse($date)->toDateString();
        $yesterday = Carbon::parse($date)->subDay()->toDateString();

        $items = StockItem::query()->active()->orderBy('name')->get(['id', 'name']);
        if ($items->isEmpty()) {
            return [];
        }

        $visibleIds = (new BranchStockItemVisibility)->filterForBranch(
            $branchId,
            $items->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
        $visibleSet = array_fill_keys($visibleIds, true);
        $items = $items->filter(fn (StockItem $item) => isset($visibleSet[(int) $item->id]))->values();
        if ($items->isEmpty()) {
            return [];
        }

        $yesterdayClosings = StockDailyCount::query()
            ->where('branch_id', $branchId)
            ->whereDate('count_date', $yesterday)
            ->get()
            ->keyBy('stock_item_id');

        $todayOpenings = StockDailyCount::query()
            ->where('branch_id', $branchId)
            ->whereDate('count_date', $today)
            ->get()
            ->keyBy('stock_item_id');

        $out = [];
        foreach ($items as $item) {
            $itemId = (int) $item->id;
            $yRow = $yesterdayClosings->get($itemId);
            if (! $yRow || $yRow->closing_qty === null || $yRow->closing_qty === '') {
                continue;
            }
            $yesterdayClosing = round((float) $yRow->closing_qty, 3);

            $openingRaw = null;
            if ($posted !== null && isset($posted['opening']) && is_array($posted['opening'])) {
                $openingRaw = $posted['opening'][$itemId] ?? $posted['opening'][(string) $itemId] ?? null;
            }
            if ($openingRaw === null || $openingRaw === '') {
                $tRow = $todayOpenings->get($itemId);
                $openingRaw = $tRow?->opening_qty;
            }
            if ($openingRaw === null || $openingRaw === '') {
                continue;
            }

            $todayOpening = round((float) $openingRaw, 3);
            $delta = round($todayOpening - $yesterdayClosing, 3);
            if (abs($delta) <= self::TOLERANCE) {
                continue;
            }

            $out[] = [
                'stock_item_id' => $itemId,
                'name' => (string) $item->name,
                'yesterday_closing' => $yesterdayClosing,
                'today_opening' => $todayOpening,
                'delta' => $delta,
            ];
        }

        return $out;
    }
}
