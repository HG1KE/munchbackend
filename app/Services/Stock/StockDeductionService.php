<?php

namespace App\Services\Stock;

use App\Model\AddonStockAllocation;
use App\Model\Order;
use App\Model\ProductStockAllocation;
use App\Model\StockSaleDeduction;
use App\Support\PosOrderTypes;
use App\Support\TimezoneDisplay;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class StockDeductionService
{
    /**
     * Kitchen-committed statuses. POS dine_in/delivery place as `confirmed`;
     * takeaway/marketplace place as `delivered`. Pending/canceled/failed clear.
     *
     * Sold ledger is POS-only (see countsTowardSold) — online web orders are
     * re-posted on Branch POS, so including them would double-count.
     */
    public const SOLD_STATUSES = [
        'confirmed',
        'processing',
        'out_for_delivery',
        'handover',
        'delivered',
        'completed',
    ];

    public function isSoldStatus(?string $status): bool
    {
        return in_array((string) $status, self::SOLD_STATUSES, true);
    }

    /**
     * Include Branch/Admin POS family (pos + sales_channel in
     * pos/delivery/takeaway/dine_in/glovo/uber/bolt_food) and dine_in rows
     * that lack sales_channel (Admin POS dine_in).
     *
     * Exclude pure online web/app orders (order_type delivery/take_away/… with
     * no POS sales_channel) — those are re-entered on POS when fulfilled.
     */
    public function countsTowardSold(Order $order): bool
    {
        $orderType = $order->order_type ?? null;
        $salesChannel = $order->sales_channel ?? null;

        if (PosOrderTypes::isPosFamily($orderType, $salesChannel)) {
            return true;
        }

        // Admin POS dine_in is often stored as order_type=dine_in without sales_channel.
        return (string) $orderType === 'dine_in';
    }

    /**
     * Rebuild ledger rows for an order from current details × product/addon allocations.
     * Idempotent: re-running with the same details leaves net quantities unchanged.
     * Non-sold / non-POS / canceled orders clear their ledger (reverses prior deduction).
     *
     * Product rows: product_id > 0, addon_id = 0.
     * Addon rows: product_id = 0, addon_id > 0 (qty from order_details.add_on_qtys).
     */
    public function syncOrder(Order $order): void
    {
        $hasProductAlloc = Schema::hasTable('product_stock_allocations');
        $hasAddonAlloc = Schema::hasTable('addon_stock_allocations');
        if (! Schema::hasTable('stock_sale_deductions') || (! $hasProductAlloc && ! $hasAddonAlloc)) {
            return;
        }

        $orderId = (int) $order->id;
        if ($orderId <= 0) {
            return;
        }

        if (! $this->isSoldStatus($order->order_status ?? null) || ! $this->countsTowardSold($order)) {
            StockSaleDeduction::query()->where('order_id', $orderId)->delete();
            return;
        }

        $order->loadMissing('details');
        $productQtys = [];
        $addonQtys = [];
        foreach ($order->details as $detail) {
            $productId = (int) ($detail->product_id ?? 0);
            if ($productId > 0) {
                $productQtys[$productId] = ($productQtys[$productId] ?? 0) + (float) ($detail->quantity ?? 0);
            }

            foreach ($this->addonQuantitiesFromDetail($detail) as $addonId => $qty) {
                $addonQtys[$addonId] = ($addonQtys[$addonId] ?? 0) + $qty;
            }
        }

        $desired = [];
        $hasAddonColumn = Schema::hasColumn('stock_sale_deductions', 'addon_id');
        $unallocatedProducts = [];
        $unallocatedAddons = [];

        if ($hasProductAlloc && $productQtys !== []) {
            $allocations = ProductStockAllocation::query()
                ->whereIn('product_id', array_keys($productQtys))
                ->get();

            $allocatedProductIds = [];
            foreach ($allocations as $allocation) {
                $productId = (int) $allocation->product_id;
                $allocatedProductIds[$productId] = true;
                $stockItemId = (int) $allocation->stock_item_id;
                $qty = (float) $productQtys[$productId] * (float) $allocation->quantity;
                if ($qty <= 0 || $stockItemId <= 0) {
                    continue;
                }
                $key = 'p:' . $productId . ':' . $stockItemId;
                if (! isset($desired[$key])) {
                    $desired[$key] = [
                        'product_id' => $productId,
                        'addon_id' => 0,
                        'stock_item_id' => $stockItemId,
                        'quantity' => 0.0,
                    ];
                }
                $desired[$key]['quantity'] += $qty;
            }
            foreach (array_keys($productQtys) as $productId) {
                if (! isset($allocatedProductIds[$productId]) && (float) $productQtys[$productId] > 0) {
                    $unallocatedProducts[] = (int) $productId;
                }
            }
        }

        if ($hasAddonAlloc && $hasAddonColumn && $addonQtys !== []) {
            $allocations = AddonStockAllocation::query()
                ->whereIn('addon_id', array_keys($addonQtys))
                ->get();

            $allocatedAddonIds = [];
            foreach ($allocations as $allocation) {
                $addonId = (int) $allocation->addon_id;
                $allocatedAddonIds[$addonId] = true;
                $stockItemId = (int) $allocation->stock_item_id;
                $qty = (float) $addonQtys[$addonId] * (float) $allocation->quantity;
                if ($qty <= 0 || $stockItemId <= 0 || $addonId <= 0) {
                    continue;
                }
                $key = 'a:' . $addonId . ':' . $stockItemId;
                if (! isset($desired[$key])) {
                    $desired[$key] = [
                        'product_id' => 0,
                        'addon_id' => $addonId,
                        'stock_item_id' => $stockItemId,
                        'quantity' => 0.0,
                    ];
                }
                $desired[$key]['quantity'] += $qty;
            }
            foreach (array_keys($addonQtys) as $addonId) {
                if (! isset($allocatedAddonIds[$addonId]) && (float) $addonQtys[$addonId] > 0) {
                    $unallocatedAddons[] = (int) $addonId;
                }
            }
        }

        if ($unallocatedProducts !== [] || $unallocatedAddons !== []) {
            Log::warning('stock.sold.unallocated_line_items', [
                'order_id' => $orderId,
                'branch_id' => (int) ($order->branch_id ?? 0),
                'product_ids' => $unallocatedProducts,
                'addon_ids' => $unallocatedAddons,
            ]);
        }

        $saleDate = $this->saleDateFor($order);
        $branchId = (int) ($order->branch_id ?? 0);

        DB::transaction(function () use ($orderId, $branchId, $saleDate, $desired, $hasAddonColumn) {
            $keepKeys = [];
            foreach ($desired as $row) {
                $addonId = (int) ($row['addon_id'] ?? 0);
                $keepKeys[] = $this->deductionKey((int) $row['product_id'], $addonId, (int) $row['stock_item_id']);

                $match = [
                    'order_id' => $orderId,
                    'product_id' => $row['product_id'],
                    'stock_item_id' => $row['stock_item_id'],
                ];
                $values = [
                    'branch_id' => $branchId,
                    'quantity' => round($row['quantity'], 3),
                    'sale_date' => $saleDate,
                ];
                if ($hasAddonColumn) {
                    $match['addon_id'] = $addonId;
                }

                StockSaleDeduction::query()->updateOrCreate($match, $values);
            }

            $columns = ['id', 'product_id', 'stock_item_id'];
            if ($hasAddonColumn) {
                $columns[] = 'addon_id';
            }
            $existing = StockSaleDeduction::query()
                ->where('order_id', $orderId)
                ->get($columns);

            foreach ($existing as $row) {
                $key = $this->deductionKey(
                    (int) $row->product_id,
                    $hasAddonColumn ? (int) ($row->addon_id ?? 0) : 0,
                    (int) $row->stock_item_id
                );
                if (! in_array($key, $keepKeys, true)) {
                    StockSaleDeduction::query()->whereKey($row->id)->delete();
                }
            }
        });
    }

    /**
     * @return array<int, float> addon_id => total qty on this detail line
     */
    public function addonQuantitiesFromDetail(object $detail): array
    {
        $ids = $this->decodeAddonIds($detail->add_on_ids ?? null);
        if ($ids === []) {
            return [];
        }

        $qtys = $this->decodeAddonList($detail->add_on_qtys ?? null);
        $out = [];
        foreach ($ids as $index => $addonId) {
            if ($addonId <= 0) {
                continue;
            }
            $qty = isset($qtys[$index]) ? (float) $qtys[$index] : 1.0;
            if ($qty <= 0) {
                continue;
            }
            $out[$addonId] = ($out[$addonId] ?? 0.0) + $qty;
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function decodeAddonIds(mixed $value): array
    {
        $ids = [];
        foreach ($this->decodeAddonList($value) as $raw) {
            if (is_array($raw)) {
                $raw = $raw['id'] ?? 0;
            }
            $id = (int) $raw;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return list<mixed>
     */
    private function decodeAddonList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function deductionKey(int $productId, int $addonId, int $stockItemId): string
    {
        return $productId . ':' . $addonId . ':' . $stockItemId;
    }

    /**
     * Sale day in business/app timezone (Africa/Nairobi by default).
     * Prefer placed_at; fall back to created_at.
     */
    public function saleDateFor(Order $order): string
    {
        $tz = $this->saleTimezone();
        $raw = $order->placed_at ?? $order->created_at ?? now($tz);
        if ($raw instanceof Carbon) {
            return $raw->copy()->timezone($tz)->toDateString();
        }

        try {
            return Carbon::parse($raw)->timezone($tz)->toDateString();
        } catch (\Throwable) {
            return now($tz)->toDateString();
        }
    }

    public function saleTimezone(): string
    {
        $configured = (string) config('app.timezone', '');
        if ($configured !== '' && in_array($configured, \DateTimeZone::listIdentifiers(), true)) {
            return $configured;
        }

        return TimezoneDisplay::businessTimezone();
    }

    /**
     * Idempotent backfill: re-sync orders whose sale day falls on/after $sinceDate
     * (app timezone, using placed_at ?? created_at). Safe to re-run.
     *
     * @return array{scanned:int, synced:int, with_rows:int, cleared:int}
     */
    public function backfillSince(string $sinceDate, ?int $branchId = null): array
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('stock_sale_deductions')) {
            return ['scanned' => 0, 'synced' => 0, 'with_rows' => 0, 'cleared' => 0];
        }

        $tz = $this->saleTimezone();
        $since = Carbon::parse($sinceDate, $tz)->startOfDay();
        // Wide SQL window (1 day slack) so TZ/stored-as-UTC edges are not missed;
        // precise sale-day filter still runs in PHP below.
        $sqlFloor = $since->copy()->subDay()->format('Y-m-d H:i:s');

        $query = Order::query()
            ->where(function ($q) use ($sqlFloor) {
                $q->where('placed_at', '>=', $sqlFloor)
                    ->orWhere(function ($q2) use ($sqlFloor) {
                        $q2->whereNull('placed_at')->where('created_at', '>=', $sqlFloor);
                    });
            })
            ->orderBy('id');

        if ($branchId !== null && $branchId > 0) {
            $query->where('branch_id', $branchId);
        }

        $scanned = 0;
        $synced = 0;
        $withRows = 0;
        $cleared = 0;

        $query->chunkById(100, function ($orders) use ($since, $tz, &$scanned, &$synced, &$withRows, &$cleared) {
            foreach ($orders as $order) {
                $scanned++;
                $raw = $order->placed_at ?? $order->created_at;
                if ($raw === null) {
                    continue;
                }
                try {
                    $saleDay = Carbon::parse($raw)->timezone($tz)->startOfDay();
                } catch (\Throwable) {
                    continue;
                }
                if ($saleDay->lt($since)) {
                    continue;
                }

                $before = (int) StockSaleDeduction::query()->where('order_id', $order->id)->count();
                $this->syncOrder($order->fresh(['details']) ?? $order);
                $after = (int) StockSaleDeduction::query()->where('order_id', $order->id)->count();
                $synced++;
                if ($after > 0) {
                    $withRows++;
                } elseif ($before > 0 && $after === 0) {
                    $cleared++;
                }
            }
        });

        return [
            'scanned' => $scanned,
            'synced' => $synced,
            'with_rows' => $withRows,
            'cleared' => $cleared,
        ];
    }
}
