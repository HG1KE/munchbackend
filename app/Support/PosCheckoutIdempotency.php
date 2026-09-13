<?php

namespace App\Support;

use App\Model\Order;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One Branch POS client UUID (per branch) creates at most one order.
 * Marketplace ticket numbers are additionally keyed so retries cannot
 * insert a second sale. Historical duplicate platform numbers are not backfilled.
 */
class PosCheckoutIdempotency
{
    public static function normalizeUuid(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : substr($trimmed, 0, 64);
    }

    public static function findByClientUuid(int $branchId, ?string $uuid): ?Order
    {
        $uuid = self::normalizeUuid($uuid);
        if ($uuid === null || $branchId < 1) {
            return null;
        }

        return Order::query()
            ->where('branch_id', $branchId)
            ->where('client_uuid', $uuid)
            ->orderBy('id')
            ->first();
    }

    public static function marketplaceKey(int $branchId, ?string $salesChannel, ?string $platformNumber): ?string
    {
        $channel = trim((string) $salesChannel);
        $number = PosOrderTypes::normalizePlatformOrderNumber($platformNumber);
        if ($branchId < 1 || $channel === '' || $number === '' || ! PosOrderTypes::isMarketplaceChannel($channel)) {
            return null;
        }

        return substr($branchId.'|'.$channel.'|'.$number, 0, 128);
    }

    public static function findByMarketplaceTicket(int $branchId, ?string $salesChannel, ?string $platformNumber): ?Order
    {
        $key = self::marketplaceKey($branchId, $salesChannel, $platformNumber);
        $channel = trim((string) $salesChannel);
        $number = PosOrderTypes::normalizePlatformOrderNumber($platformNumber);
        if ($key === null) {
            return null;
        }

        return Order::query()
            ->where('branch_id', $branchId)
            ->where('sales_channel', $channel)
            ->where(function ($query) use ($number, $key) {
                $query->where('platform_order_number', $number);
                if (Schema::hasColumn((new Order())->getTable(), 'marketplace_dedupe_key')) {
                    $query->orWhere('marketplace_dedupe_key', $key);
                }
            })
            ->orderBy('id')
            ->first();
    }

    public static function isClientUuidConflict(Throwable $e): bool
    {
        if (! $e instanceof QueryException && ! $e instanceof UniqueConstraintViolationException) {
            return false;
        }

        $haystack = strtolower($e->getMessage().' '.($e->getSql() ?? ''));

        return str_contains($haystack, 'client_uuid')
            || str_contains($haystack, 'orders_branch_client_uuid_unique');
    }

    public static function isMarketplaceConflict(Throwable $e): bool
    {
        if (! $e instanceof QueryException && ! $e instanceof UniqueConstraintViolationException) {
            return false;
        }

        $haystack = strtolower($e->getMessage().' '.($e->getSql() ?? ''));

        return str_contains($haystack, 'marketplace_dedupe_key')
            || str_contains($haystack, 'orders_marketplace_dedupe_key_unique');
    }

    public static function recoverFromException(
        int $branchId,
        ?string $uuid,
        ?string $salesChannel,
        ?string $platformNumber,
        Throwable $e
    ): ?Order {
        if (self::isClientUuidConflict($e)) {
            $order = self::findByClientUuid($branchId, $uuid);
            if ($order) {
                self::logDuplicate($uuid, $order, 'unique_constraint');
            }

            return $order;
        }

        if (self::isMarketplaceConflict($e)) {
            $order = self::findByMarketplaceTicket($branchId, $salesChannel, $platformNumber);
            if ($order) {
                self::logDuplicate($uuid, $order, 'marketplace_unique_constraint');
            }

            return $order;
        }

        return null;
    }

    public static function logDuplicate(?string $uuid, Order $order, string $detection): void
    {
        Log::info('pos_checkout.duplicate', [
            'client_uuid' => $uuid,
            'existing_order_id' => $order->id,
            'existing_order_number' => $order->readable_order_id,
            'branch_id' => $order->branch_id,
            'detection' => $detection,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
