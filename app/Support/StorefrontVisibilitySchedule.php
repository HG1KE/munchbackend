<?php

namespace App\Support;

use App\Model\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Storefront visibility: optional one-time (visible_from / visible_until) plus optional
 * weekly recurring rules (recurring_visibility_rules JSON).
 *
 * SQL scope (MariaDB / MySQL compatible, no JSON_TABLE):
 * - Rows with no scheduling pass.
 * - Rows with one-time bounds pass when inside the window.
 * - Rows with non-empty recurring JSON pass the SQL layer (recurring evaluated in PHP).
 *
 * Use {@see self::filterProducts()} / {@see self::filterPaginatorProducts()} on result sets
 * so recurring day/time rules match {@see self::productPasses()}.
 */
final class StorefrontVisibilitySchedule
{
    /** @var list<string> */
    public const WEEKDAYS = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
    ];

    public static function productPasses(Product $product, Carbon $at): bool
    {
        $rules = $product->recurring_visibility_rules;
        $recurringConfigured = self::recurringConfigured($rules);
        $oneTimeConfigured = $product->visible_from !== null || $product->visible_until !== null;

        if (! $oneTimeConfigured && ! $recurringConfigured) {
            return true;
        }

        if ($oneTimeConfigured && self::oneTimeWindowContains($product, $at)) {
            return true;
        }

        if ($recurringConfigured && self::recurringMatchesAt($at, $rules)) {
            return true;
        }

        return false;
    }

    /**
     * @param mixed $rules
     */
    public static function recurringConfigured($rules): bool
    {
        return is_array($rules) && count($rules) > 0;
    }

    public static function oneTimeWindowContains(Product $product, Carbon $at): bool
    {
        if ($product->visible_from !== null && $product->visible_from->gt($at)) {
            return false;
        }

        if ($product->visible_until !== null && $product->visible_until->lte($at)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>>|null $rules
     */
    public static function recurringMatchesAt(Carbon $at, ?array $rules): bool
    {
        if (! self::recurringConfigured($rules)) {
            return false;
        }

        $day = strtolower($at->englishDayOfWeek);
        $nowSec = $at->hour * 3600 + $at->minute * 60 + $at->second;

        foreach ($rules as $rule) {
            $d = isset($rule['day']) ? strtolower((string) $rule['day']) : '';
            if (! in_array($d, self::WEEKDAYS, true) || $d !== $day) {
                continue;
            }

            $startSec = self::parseTimeToSeconds($rule['start'] ?? null);
            $endSec = self::parseEndTimeToInclusiveSeconds($rule['end'] ?? null);
            if ($startSec === null || $endSec === null) {
                continue;
            }

            if ($startSec < $endSec && $nowSec >= $startSec && $nowSec <= $endSec) {
                return true;
            }
        }

        return false;
    }

    /**
     * SQL pre-filter: one-time windows + "has recurring rules" (rules evaluated in PHP).
     */
    public static function applyStorefrontScope(Builder $query, ?Carbon $at = null): Builder
    {
        $at = $at ?? now();

        return $query->where(function (Builder $outer) use ($at) {
            $outer->where(function (Builder $w) {
                $w->whereNull('visible_from')->whereNull('visible_until')
                    ->where(function ($r) {
                        $r->whereNull('recurring_visibility_rules')
                            ->orWhereRaw('JSON_LENGTH(recurring_visibility_rules) = 0');
                    });
            })->orWhere(function (Builder $w) use ($at) {
                $w->where(function ($c) {
                    $c->whereNotNull('visible_from')->orWhereNotNull('visible_until');
                })->where(function ($inner) use ($at) {
                    $inner->where(function ($x) use ($at) {
                        $x->whereNull('visible_from')->orWhere('visible_from', '<=', $at);
                    })->where(function ($x) use ($at) {
                        $x->whereNull('visible_until')->orWhere('visible_until', '>', $at);
                    });
                });
            })->orWhere(function (Builder $w) {
                $w->whereNotNull('recurring_visibility_rules')
                    ->whereRaw('JSON_LENGTH(recurring_visibility_rules) > 0');
            });
        });
    }

    /**
     * @param iterable<int, Product> $products
     */
    public static function filterProducts(iterable $products, ?Carbon $at = null): Collection
    {
        $at = $at ?? now();

        return collect($products)->values()->filter(function ($product) use ($at) {
            return $product instanceof Product && self::productPasses($product, $at);
        })->values();
    }

    public static function filterPaginatorProducts(AbstractPaginator $paginator, ?Carbon $at = null): AbstractPaginator
    {
        $filtered = self::filterProducts($paginator->getCollection(), $at);
        $paginator->setCollection($filtered);

        return $paginator;
    }

    /**
     * @param list<int|string> $ids
     * @return list<int>
     */
    public static function filterProductIds(array $ids, ?Carbon $at = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $at = $at ?? now();

        return Product::query()->where('status', 1)->whereIn('id', $ids)
            ->select(['id', 'visible_from', 'visible_until', 'recurring_visibility_rules'])
            ->get()
            ->filter(fn (Product $p) => self::productPasses($p, $at))
            ->pluck('id')
            ->all();
    }

    private static function parseTimeToSeconds(?string $t): ?int
    {
        if ($t === null || trim($t) === '') {
            return null;
        }

        try {
            $c = Carbon::parse('2000-01-01 '.trim($t));

            return $c->hour * 3600 + $c->minute * 60 + $c->second;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * End time is inclusive through the end minute when given as HH:MM (e.g. 23:59).
     */
    private static function parseEndTimeToInclusiveSeconds(?string $t): ?int
    {
        $base = self::parseTimeToSeconds($t);
        if ($base === null) {
            return null;
        }

        if ($t !== null && preg_match('/^\d{1,2}:\d{2}$/', trim($t))) {
            return $base + 59;
        }

        return $base;
    }
}
