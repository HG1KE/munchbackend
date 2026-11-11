<?php

namespace App\Services\Stock;

use App\Model\Order;
use App\Model\ProductStockAllocation;
use App\Model\StockSaleDeduction;
use App\Support\PosOrderTypes;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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
     * Rebuild ledger rows for an order from current details × product allocations.
     * Idempotent: re-running with the same details leaves net quantities unchanged.
     * Non-sold / non-POS / canceled orders clear their ledger (reverses prior deduction).
     */
    public function syncOrder(Order $order): void
    {
        if (! Schema::hasTable('stock_sale_deductions') || ! Schema::hasTable('product_stock_allocations')) {
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
        foreach ($order->details as $detail) {
            $productId = (int) ($detail->product_id ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $productQtys[$productId] = ($productQtys[$productId] ?? 0) + (float) ($detail->quantity ?? 0);
        }

        $desired = [];
        if ($productQtys !== []) {
            $allocations = ProductStockAllocation::query()
                ->whereIn('product_id', array_keys($productQtys))
                ->get();

            foreach ($allocations as $allocation) {
                $productId = (int) $allocation->product_id;
                $stockItemId = (int) $allocation->stock_item_id;
                $qty = (float) $productQtys[$productId] * (float) $allocation->quantity;
                if ($qty <= 0 || $stockItemId <= 0) {
                    continue;
                }
                $key = $productId . ':' . $stockItemId;
                if (! isset($desired[$key])) {
                    $desired[$key] = [
                        'product_id' => $productId,
                        'stock_item_id' => $stockItemId,
                        'quantity' => 0.0,
                    ];
                }
                $desired[$key]['quantity'] += $qty;
            }
        }

        $saleDate = $this->saleDateFor($order);
        $branchId = (int) ($order->branch_id ?? 0);

        DB::transaction(function () use ($orderId, $branchId, $saleDate, $desired) {
            $keepKeys = [];
            foreach ($desired as $row) {
                $keepKeys[] = $row['product_id'] . ':' . $row['stock_item_id'];
                StockSaleDeduction::query()->updateOrCreate(
                    [
                        'order_id' => $orderId,
                        'product_id' => $row['product_id'],
                        'stock_item_id' => $row['stock_item_id'],
                    ],
                    [
                        'branch_id' => $branchId,
                        'quantity' => round($row['quantity'], 3),
                        'sale_date' => $saleDate,
                    ]
                );
            }

            $existing = StockSaleDeduction::query()
                ->where('order_id', $orderId)
                ->get(['id', 'product_id', 'stock_item_id']);

            foreach ($existing as $row) {
                $key = (int) $row->product_id . ':' . (int) $row->stock_item_id;
                if (! in_array($key, $keepKeys, true)) {
                    StockSaleDeduction::query()->whereKey($row->id)->delete();
                }
            }
        });
    }

    public function saleDateFor(Order $order): string
    {
        $raw = $order->placed_at ?? $order->created_at ?? now();
        if ($raw instanceof Carbon) {
            return $raw->timezone(config('app.timezone'))->toDateString();
        }

        try {
            return Carbon::parse($raw)->timezone(config('app.timezone'))->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
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

        $since = Carbon::parse($sinceDate, config('app.timezone'))->startOfDay();
        $tz = config('app.timezone');
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
