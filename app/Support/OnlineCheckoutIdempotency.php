<?php

namespace App\Support;

use App\CentralLogics\Helpers;
use App\Model\Order;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;
use function App\CentralLogics\translate;

/**
 * One online checkout UUID creates at most one order.
 * Enforced by orders.online_checkout_uuid unique (not POS client_uuid).
 */
class OnlineCheckoutIdempotency
{
    public const REQUEST_KEYS = ['online_checkout_uuid', 'checkout_uuid', 'client_uuid'];

    public static function resolveFromRequest(Request $request): ?string
    {
        foreach (self::REQUEST_KEYS as $key) {
            $normalized = self::normalize($request->input($key));
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function resolveFromArray(array $payload): ?string
    {
        foreach (self::REQUEST_KEYS as $key) {
            $normalized = self::normalize($payload[$key] ?? null);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    public static function normalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        return substr($trimmed, 0, 64);
    }

    public static function hasColumn(): bool
    {
        try {
            return Schema::hasColumn((new Order())->getTable(), 'online_checkout_uuid');
        } catch (Throwable) {
            return false;
        }
    }

    public static function findOrder(string $uuid): ?Order
    {
        if (! self::hasColumn()) {
            return null;
        }

        return Order::query()->where('online_checkout_uuid', $uuid)->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function applyToOrderAttributes(array $attributes, ?string $uuid): array
    {
        if ($uuid !== null && self::hasColumn()) {
            $attributes['online_checkout_uuid'] = $uuid;
        }

        return $attributes;
    }

    public static function isCheckoutUuidConflict(Throwable $e): bool
    {
        if (! $e instanceof QueryException && ! $e instanceof UniqueConstraintViolationException) {
            return false;
        }

        $haystack = strtolower($e->getMessage().' '.($e->getSql() ?? ''));

        return str_contains($haystack, 'online_checkout_uuid')
            || str_contains($haystack, 'orders_online_checkout_uuid_unique');
    }

    public static function recoverExistingFromException(?string $uuid, Throwable $e): ?Order
    {
        if ($uuid === null || ! self::isCheckoutUuidConflict($e)) {
            return null;
        }

        $order = self::findOrder($uuid);
        if ($order) {
            self::logDuplicate($uuid, $order, 'unique_constraint');
        }

        return $order;
    }

    public static function logDuplicate(string $uuid, Order $order, string $detection): void
    {
        Log::info('online_checkout.duplicate', [
            'online_checkout_uuid' => $uuid,
            'existing_order_id' => $order->id,
            'existing_order_number' => $order->readable_order_id,
            'detection' => $detection,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public static function logMissingUuid(string $paymentMethod): void
    {
        Log::info('online_checkout.missing_uuid', [
            'payment_method' => $paymentMethod,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public static function successResponse(Order $order): JsonResponse
    {
        return response()->json([
            'message' => translate('order_success'),
            'order_id' => $order->id,
            'readable_order_id' => $order->readable_order_id,
            'order_display_id' => Helpers::order_display_id($order),
        ], 200);
    }
}
