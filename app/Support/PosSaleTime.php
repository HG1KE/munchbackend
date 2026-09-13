<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * POS sale instant: client placed_at (UTC) is the business sale time.
 * created_at remains the server insert/sync time and is only a fallback
 * when placed_at is missing on older rows.
 */
class PosSaleTime
{
    /**
     * @return array{from: Carbon, to: Carbon, from_utc: Carbon, to_utc: Carbon}
     */
    public static function nairobiBounds(CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $tz = TimezoneDisplay::businessTimezone();
        $fromDay = Carbon::parse(
            $from instanceof CarbonInterface ? $from->toDateString() : (string) $from,
            $tz
        )->startOfDay();
        $toDay = Carbon::parse(
            $to instanceof CarbonInterface ? $to->toDateString() : (string) $to,
            $tz
        )->endOfDay();

        return [
            'from' => $fromDay,
            'to' => $toDay,
            'from_utc' => $fromDay->copy()->utc(),
            'to_utc' => $toDay->copy()->utc(),
        ];
    }

    /**
     * @param  Builder<\App\Model\Order>  $query
     * @return Builder<\App\Model\Order>
     */
    public static function constrainBusinessPeriod(Builder $query, CarbonInterface|string $from, CarbonInterface|string $to): Builder
    {
        $bounds = self::nairobiBounds($from, $to);
        $fromUtc = $bounds['from_utc']->format('Y-m-d H:i:s');
        $toUtc = $bounds['to_utc']->format('Y-m-d H:i:s');
        $fromLocal = $bounds['from']->format('Y-m-d H:i:s');
        $toLocal = $bounds['to']->format('Y-m-d H:i:s');
        $table = $query->getModel()->getTable();

        if (! Schema::hasColumn($table, 'placed_at')) {
            return $query->whereBetween('created_at', [$fromLocal, $toLocal]);
        }

        // TIMESTAMP columns reject '' (MySQL error 1525) and then match nothing.
        // New POS rows use UTC placed_at; legacy NULL placed_at falls back to created_at.
        return $query->where(function (Builder $outer) use ($fromUtc, $toUtc, $fromLocal, $toLocal) {
            $outer->where(function (Builder $inner) use ($fromUtc, $toUtc) {
                $inner->whereNotNull('placed_at')
                    ->whereBetween('placed_at', [$fromUtc, $toUtc]);
            })->orWhere(function (Builder $inner) use ($fromLocal, $toLocal) {
                $inner->whereNull('placed_at')
                    ->whereBetween('created_at', [$fromLocal, $toLocal]);
            });
        });
    }

    /**
     * @param  Builder<\App\Model\Order>  $query
     * @return Builder<\App\Model\Order>
     */
    public static function orderBySaleInstant(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        if (! Schema::hasColumn($table, 'placed_at')) {
            return $query->orderBy('created_at');
        }

        return $query->orderByRaw('CASE WHEN placed_at IS NULL THEN created_at ELSE placed_at END');
    }

    public static function matchesPeriod(mixed $instant, CarbonInterface|string $from, CarbonInterface|string $to): bool
    {
        $parsed = $instant instanceof CarbonInterface
            ? $instant->copy()->utc()
            : TimezoneDisplay::parseStoredUtc($instant);
        if ($parsed === null) {
            return false;
        }

        $bounds = self::nairobiBounds($from, $to);

        return $parsed->betweenIncluded($bounds['from_utc'], $bounds['to_utc']);
    }

    public static function instant(object|array $order): mixed
    {
        $placed = self::value($order, 'placed_at');
        if ($placed !== null && $placed !== '') {
            return $placed;
        }

        return self::value($order, 'created_at');
    }

    public static function sortKey(object|array $order): string
    {
        $instant = self::instant($order);
        if ($instant instanceof CarbonInterface) {
            return $instant->copy()->utc()->toIso8601String();
        }

        $parsed = TimezoneDisplay::parseStoredUtc($instant);

        return $parsed?->utc()->toIso8601String() ?: (string) ($instant ?? '');
    }

    private static function value(object|array $order, string $key): mixed
    {
        if (is_array($order)) {
            return $order[$key] ?? null;
        }

        return $order->{$key} ?? null;
    }
}
