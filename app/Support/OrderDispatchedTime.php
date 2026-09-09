<?php

namespace App\Support;

use App\Model\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Dispatch instant — frozen SLA timer when order goes out_for_delivery.
 */
class OrderDispatchedTime
{
    private static ?bool $hasDispatchedAtColumn = null;

    public static function hasDispatchedAtColumn(string $table = 'orders'): bool
    {
        if (self::$hasDispatchedAtColumn !== null) {
            return self::$hasDispatchedAtColumn;
        }

        try {
            self::$hasDispatchedAtColumn = Schema::hasColumn($table, 'dispatched_at');
        } catch (\Throwable $e) {
            Log::warning('order_dispatched_at_column_check_failed', ['message' => $e->getMessage()]);
            self::$hasDispatchedAtColumn = false;
        }

        return self::$hasDispatchedAtColumn;
    }

    public static function resetColumnCache(): void
    {
        self::$hasDispatchedAtColumn = null;
    }

    public static function unixFromRaw(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return (int) \Carbon\Carbon::parse(trim($raw), 'UTC')->getTimestamp();
    }

    public static function unix(Order $order): ?int
    {
        if (! self::hasDispatchedAtColumn($order->getTable())) {
            return null;
        }

        $dispatchedUnix = self::unixFromRaw($order->getRawOriginal('dispatched_at'));
        if ($dispatchedUnix !== null) {
            return $dispatchedUnix;
        }

        if ((string) $order->order_status === OnlineOrderStatus::OUT_FOR_DELIVERY) {
            return OrderPlacementTime::unixFromRaw($order->getRawOriginal('updated_at'))
                ?? OrderPlacementTime::unixFromRaw($order->getRawOriginal('created_at'));
        }

        return null;
    }

    public static function applyToOrder(Order $order, ?CarbonInterface $dispatchedAt = null): void
    {
        try {
            if (! self::hasDispatchedAtColumn($order->getTable())) {
                return;
            }

            $raw = $order->getRawOriginal('dispatched_at');
            if ($raw !== null && trim((string) $raw) !== '') {
                return;
            }

            $utcString = ($dispatchedAt ?? OrderPlacementTime::utcNow())->utc()->format('Y-m-d H:i:s');
            $order->setRawAttributes(
                array_merge($order->getAttributes(), ['dispatched_at' => $utcString]),
                false
            );
        } catch (\Throwable $e) {
            Log::error('order_dispatched_at_apply_failed', [
                'order_id' => $order->id ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public static function formatElapsedDisplay(int $elapsedSeconds): string
    {
        $seconds = max(0, $elapsedSeconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }
}
