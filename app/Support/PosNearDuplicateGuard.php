<?php

namespace App\Support;

use App\Model\Order;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Blocks a second Branch POS place when the same branch already has a recent
 * non-cancelled, non-marketplace sale with the same cart fingerprint and total.
 * Catches channel re-posts (new client_uuid) like A24199 → A24200.
 */
class PosNearDuplicateGuard
{
    public const WINDOW_SECONDS = 120;

    public const CODE = 'near_duplicate';

    public static function message(): string
    {
        return 'Duplicate order detected. You just posted the same order.';
    }

    /**
     * Stable hash of product lines (product, qty, variations, add-ons).
     *
     * @param  list<array<string, mixed>>|\Illuminate\Support\Collection<int, mixed>  $details
     */
    public static function fingerprintFromDetails(iterable $details): string
    {
        $lines = [];
        foreach ($details as $detail) {
            if (is_object($detail)) {
                $detail = [
                    'product_id' => $detail->product_id ?? null,
                    'quantity' => $detail->quantity ?? null,
                    'variation' => $detail->variation ?? null,
                    'add_on_ids' => $detail->add_on_ids ?? null,
                    'add_on_qtys' => $detail->add_on_qtys ?? null,
                ];
            }
            if (! is_array($detail)) {
                continue;
            }

            $productId = (int) ($detail['product_id'] ?? 0);
            $quantity = (int) ($detail['quantity'] ?? 0);
            if ($productId < 1 || $quantity < 1) {
                continue;
            }

            $lines[] = [
                'p' => $productId,
                'q' => $quantity,
                'v' => self::normalizeJsonValue($detail['variation'] ?? null),
                'a' => self::normalizeAddons(
                    $detail['add_on_ids'] ?? null,
                    $detail['add_on_qtys'] ?? null
                ),
            ];
        }

        usort($lines, static function (array $left, array $right): int {
            return strcmp(
                (string) json_encode($left, JSON_UNESCAPED_UNICODE),
                (string) json_encode($right, JSON_UNESCAPED_UNICODE)
            );
        });

        return hash('sha256', (string) json_encode($lines, JSON_UNESCAPED_UNICODE));
    }

    public static function fingerprintFromOrder(Order $order): string
    {
        if (! $order->relationLoaded('details')) {
            $order->load('details');
        }

        return self::fingerprintFromDetails($order->details ?? []);
    }

    /**
     * @return Order|null Matching recent open POS order, or null.
     */
    public static function findRecent(
        int $branchId,
        float $orderAmount,
        string $fingerprint,
        ?CarbonInterface $now = null
    ): ?Order {
        if ($branchId < 1 || $fingerprint === '' || $orderAmount < 0) {
            return null;
        }

        $now = ($now ?? Carbon::now())->utc();
        $since = $now->copy()->subSeconds(self::WINDOW_SECONDS);
        $amount = round($orderAmount, 2);

        $query = Order::query()
            ->where('branch_id', $branchId)
            ->where('order_amount', $amount)
            ->whereNotIn('order_status', ['canceled', 'cancelled'])
            ->where(function ($builder) {
                $builder->where('order_type', 'pos')
                    ->orWhereIn('sales_channel', ['pos', 'delivery', 'takeaway', 'dine_in']);
            })
            ->where(function ($builder) {
                $builder->whereNull('sales_channel')
                    ->orWhereNotIn('sales_channel', ['glovo', 'uber', 'bolt_food']);
            });

        if (Schema::hasColumn((new Order())->getTable(), 'placed_at')) {
            $query->where(function ($builder) use ($since) {
                $builder->where('placed_at', '>=', $since->format('Y-m-d H:i:s'))
                    ->orWhere(function ($inner) use ($since) {
                        $inner->whereNull('placed_at')
                            ->where('created_at', '>=', $since);
                    });
            });
        } else {
            $query->where('created_at', '>=', $since);
        }

        $candidates = $query
            ->with('details')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        foreach ($candidates as $candidate) {
            if (PosOrderTypes::isMarketplaceChannel((string) $candidate->sales_channel)) {
                continue;
            }
            if (! PosOrderTypes::isPosFamily($candidate->order_type, $candidate->sales_channel)) {
                continue;
            }
            if (self::fingerprintFromOrder($candidate) === $fingerprint) {
                return $candidate;
            }
        }

        return null;
    }

    public static function log(?string $clientUuid, Order $existing): void
    {
        Log::info('pos_checkout.near_duplicate', [
            'client_uuid' => $clientUuid,
            'existing_order_id' => $existing->id,
            'existing_order_number' => $existing->readable_order_id,
            'branch_id' => $existing->branch_id,
            'order_amount' => $existing->order_amount,
            'window_seconds' => self::WINDOW_SECONDS,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private static function normalizeJsonValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return self::sortRecursive($decoded);
            }

            return $value;
        }

        if (is_array($value)) {
            return self::sortRecursive($value);
        }

        return $value;
    }

    /**
     * @return list<array{id:int,qty:int}>
     */
    private static function normalizeAddons(mixed $ids, mixed $qtys): array
    {
        $idList = self::asList($ids);
        $qtyList = self::asList($qtys);
        $pairs = [];

        foreach ($idList as $index => $id) {
            $addonId = (int) $id;
            if ($addonId < 1) {
                continue;
            }
            $qty = isset($qtyList[$index]) ? (int) $qtyList[$index] : 1;
            if ($qty < 1) {
                continue;
            }
            $pairs[] = ['id' => $addonId, 'qty' => $qty];
        }

        usort($pairs, static function (array $left, array $right): int {
            return [$left['id'], $left['qty']] <=> [$right['id'], $right['qty']];
        });

        return $pairs;
    }

    /**
     * @return list<mixed>
     */
    private static function asList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            } else {
                return [];
            }
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values($value);
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (self::isList($value)) {
            $normalized = [];
            foreach ($value as $item) {
                $normalized[] = self::sortRecursive($item);
            }

            return $normalized;
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursive($item);
        }

        return $value;
    }

    private static function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
