<?php

namespace App\Support;

use App\Model\Order;
use App\Services\OrderReadableIdService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical human-facing order identifier (readable_order_id, e.g. A10001).
 *
 * Internal FKs and jobs continue to use orders.id; UI uses this value.
 */
final class OrderPublicNumber
{
    public const COLUMN = 'readable_order_id';

    public static function forOrder(Order $order): string
    {
        if (! empty($order->readable_order_id)) {
            return (string) $order->readable_order_id;
        }

        return (string) $order->id;
    }

    public static function display(Order|array|string|int $order): string
    {
        if ($order instanceof Order) {
            return self::forOrder($order);
        }

        if (is_array($order)) {
            if (! empty($order[self::COLUMN])) {
                return (string) $order[self::COLUMN];
            }
            if (! empty($order['readable_order_id'])) {
                return (string) $order['readable_order_id'];
            }
            if (array_key_exists('id', $order)) {
                return (string) $order['id'];
            }
        }

        return (string) $order;
    }

    public static function resolveForCustomer(string $identifier, int $userId, int $isGuest): ?Order
    {
        return self::resolve($identifier, static function ($query) use ($userId, $isGuest) {
            $query->where(['user_id' => $userId, 'is_guest' => $isGuest]);
        });
    }

    public static function resolve(string $identifier, ?callable $scope = null): ?Order
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $base = Order::query();
        if ($scope !== null) {
            $scope($base);
        }

        $readable = self::normalizeReadable($identifier);
        if ($readable !== null) {
            $order = (clone $base)->where(self::COLUMN, $readable)->first();
            if ($order) {
                return $order;
            }
        }

        if (ctype_digit($identifier)) {
            return (clone $base)->where('id', (int) $identifier)->first();
        }

        return null;
    }

    public static function normalizeReadable(string $value): ?string
    {
        $parsed = app(OrderReadableIdService::class)->parse($value);
        if ($parsed !== null) {
            return $parsed['formatted'];
        }

        $upper = strtoupper(ltrim(trim($value), '#'));
        if (preg_match('/^[A-Z]\d{5}$/', $upper)) {
            return $upper;
        }

        return null;
    }

    /**
     * @param  callable(Builder<Order>): void|null  $scope
     */
    public static function applySearch(Builder $query, ?string $search): Builder
    {
        return OrderReadableIdService::applySearch($query, $search);
    }
}
