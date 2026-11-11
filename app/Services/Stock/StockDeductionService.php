<?php

namespace App\Services\Stock;

use App\Model\Order;
use App\Model\ProductStockAllocation;
use App\Model\StockSaleDeduction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockDeductionService
{
    public const SOLD_STATUSES = ['delivered', 'completed'];

    public function isSoldStatus(?string $status): bool
    {
        return in_array((string) $status, self::SOLD_STATUSES, true);
    }

    /**
     * Rebuild ledger rows for an order from current details × product allocations.
     * Idempotent: re-running with the same details leaves net quantities unchanged.
     * Non-sold / canceled orders clear their ledger (reverses prior deduction).
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

        if (! $this->isSoldStatus($order->order_status ?? null)) {
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
}
