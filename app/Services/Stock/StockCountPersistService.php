<?php

namespace App\Services\Stock;

use App\Model\StockDailyCount;
use App\Model\StockItem;
use App\Model\StockManager;
use App\Support\StocksAuditExport;
use Illuminate\Support\Facades\Schema;

class StockCountPersistService
{
    /**
     * Persist opening/received/spoiled/closing for every active stock item.
     * When $posted is null, rows already in DB for this branch+date are re-saved
     * (export of previously saved counts). Returns an error message or null.
     *
     * @param  array{opening:array,received:array,spoiled:array,closing:array}|null  $posted
     */
    public function persistComplete(
        StockManager $manager,
        int $branchId,
        string $date,
        ?array $posted
    ): ?string {
        if (! Schema::hasTable('stock_daily_counts') || ! Schema::hasTable('stock_items')) {
            return 'Stock counts table is missing. Run migrations.';
        }

        $activeItemIds = StockItem::query()->active()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($activeItemIds === []) {
            return 'No active stock items. Ask admin to add stock items first.';
        }

        if ($posted === null) {
            $existing = StockDailyCount::query()
                ->where('branch_id', $branchId)
                ->whereDate('count_date', $date)
                ->get()
                ->keyBy('stock_item_id');

            $posted = [
                'opening' => [],
                'received' => [],
                'spoiled' => [],
                'closing' => [],
            ];
            foreach ($activeItemIds as $stockItemId) {
                /** @var StockDailyCount|null $row */
                $row = $existing->get($stockItemId);
                if (! $row) {
                    return StocksAuditExport::INCOMPLETE_MESSAGE;
                }
                foreach (['opening' => 'opening_qty', 'received' => 'received_qty', 'spoiled' => 'spoiled_qty', 'closing' => 'closing_qty'] as $mapKey => $col) {
                    $val = $row->{$col};
                    if ($val === null || $val === '') {
                        return StocksAuditExport::INCOMPLETE_MESSAGE;
                    }
                    $posted[$mapKey][$stockItemId] = $val;
                }
            }
        }

        foreach ($activeItemIds as $stockItemId) {
            $openingRaw = $posted['opening'][$stockItemId] ?? $posted['opening'][(string) $stockItemId] ?? null;
            $receivedRaw = $posted['received'][$stockItemId] ?? $posted['received'][(string) $stockItemId] ?? null;
            $spoiledRaw = $posted['spoiled'][$stockItemId] ?? $posted['spoiled'][(string) $stockItemId] ?? null;
            $closingRaw = $posted['closing'][$stockItemId] ?? $posted['closing'][(string) $stockItemId] ?? null;
            $hasOpening = $openingRaw !== null && $openingRaw !== '';
            $hasReceived = $receivedRaw !== null && $receivedRaw !== '';
            $hasSpoiled = $spoiledRaw !== null && $spoiledRaw !== '';
            $hasClosing = $closingRaw !== null && $closingRaw !== '';
            if (! $hasOpening || ! $hasReceived || ! $hasSpoiled || ! $hasClosing) {
                return 'Fill in all boxes before saving';
            }
        }

        foreach ($activeItemIds as $stockItemId) {
            $openingRaw = $posted['opening'][$stockItemId] ?? $posted['opening'][(string) $stockItemId];
            $receivedRaw = $posted['received'][$stockItemId] ?? $posted['received'][(string) $stockItemId];
            $spoiledRaw = $posted['spoiled'][$stockItemId] ?? $posted['spoiled'][(string) $stockItemId];
            $closingRaw = $posted['closing'][$stockItemId] ?? $posted['closing'][(string) $stockItemId];

            StockDailyCount::query()->updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'stock_item_id' => $stockItemId,
                    'count_date' => $date,
                ],
                [
                    'opening_qty' => (float) $openingRaw,
                    'received_qty' => (float) $receivedRaw,
                    'spoiled_qty' => (float) $spoiledRaw,
                    'closing_qty' => (float) $closingRaw,
                    'stock_manager_id' => (int) $manager->id,
                ]
            );
        }

        return null;
    }

    /**
     * Upsert only the stock items present in $posted maps. Empty string clears to null.
     * Fields omitted for an item are left unchanged (existing row) or null (new row).
     * Incomplete rows are allowed.
     *
     * @param  array{opening?:array,received?:array,spoiled?:array,closing?:array}  $posted
     * @return array{ok:bool,error:?string,saved:list<int>}
     */
    public function persistPartial(
        StockManager $manager,
        int $branchId,
        string $date,
        array $posted
    ): array {
        if (! Schema::hasTable('stock_daily_counts') || ! Schema::hasTable('stock_items')) {
            return ['ok' => false, 'error' => 'Stock counts table is missing. Run migrations.', 'saved' => []];
        }

        $activeIds = StockItem::query()->active()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $activeSet = array_fill_keys($activeIds, true);
        if ($activeSet === []) {
            return ['ok' => false, 'error' => 'No active stock items. Ask admin to add stock items first.', 'saved' => []];
        }

        $itemIds = $this->itemIdsFromPosted($posted);
        if ($itemIds === []) {
            return ['ok' => false, 'error' => 'No stock item values to save.', 'saved' => []];
        }

        $saved = [];
        foreach ($itemIds as $stockItemId) {
            if (! isset($activeSet[$stockItemId])) {
                continue;
            }

            $existing = StockDailyCount::query()
                ->where('branch_id', $branchId)
                ->where('stock_item_id', $stockItemId)
                ->whereDate('count_date', $date)
                ->first();

            $attrs = [
                'branch_id' => $branchId,
                'stock_item_id' => $stockItemId,
                'count_date' => $date,
                'stock_manager_id' => (int) $manager->id,
                'opening_qty' => $existing?->opening_qty,
                'received_qty' => $existing?->received_qty,
                'spoiled_qty' => $existing?->spoiled_qty,
                'closing_qty' => $existing?->closing_qty,
            ];

            foreach (['opening' => 'opening_qty', 'received' => 'received_qty', 'spoiled' => 'spoiled_qty', 'closing' => 'closing_qty'] as $mapKey => $col) {
                if (! array_key_exists($mapKey, $posted) || ! is_array($posted[$mapKey])) {
                    continue;
                }
                $map = $posted[$mapKey];
                if (! array_key_exists($stockItemId, $map) && ! array_key_exists((string) $stockItemId, $map)) {
                    continue;
                }
                $raw = $map[$stockItemId] ?? $map[(string) $stockItemId];
                $attrs[$col] = ($raw === null || $raw === '') ? null : (float) $raw;
            }

            StockDailyCount::query()->updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'stock_item_id' => $stockItemId,
                    'count_date' => $date,
                ],
                [
                    'opening_qty' => $attrs['opening_qty'],
                    'received_qty' => $attrs['received_qty'],
                    'spoiled_qty' => $attrs['spoiled_qty'],
                    'closing_qty' => $attrs['closing_qty'],
                    'stock_manager_id' => $attrs['stock_manager_id'],
                ]
            );
            $saved[] = $stockItemId;
        }

        if ($saved === []) {
            return ['ok' => false, 'error' => 'No active stock items matched the posted values.', 'saved' => []];
        }

        return ['ok' => true, 'error' => null, 'saved' => $saved];
    }

    /**
     * @param  array{opening?:array,received?:array,spoiled?:array,closing?:array}  $posted
     * @return list<int>
     */
    public function itemIdsFromPosted(array $posted): array
    {
        $ids = [];
        foreach (['opening', 'received', 'spoiled', 'closing'] as $key) {
            if (! isset($posted[$key]) || ! is_array($posted[$key])) {
                continue;
            }
            foreach (array_keys($posted[$key]) as $id) {
                $intId = (int) $id;
                if ($intId > 0) {
                    $ids[$intId] = true;
                }
            }
        }

        $list = array_map('intval', array_keys($ids));
        sort($list);

        return $list;
    }
}
