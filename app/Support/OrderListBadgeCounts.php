<?php

namespace App\Support;

use App\Model\Order;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Single GROUP BY aggregation for admin/branch order-list status badges
 * (Meatco hot-path port: kill N× COUNT queries).
 */
final class OrderListBadgeCounts
{
    /** @var list<string> */
    public const STATUSES = [
        'pending',
        'confirmed',
        'processing',
        'out_for_delivery',
        'delivered',
        'canceled',
        'returned',
        'failed',
    ];

    /**
     * @param  Closure(Builder): void  $applySharedFilters  branch/date/search filters shared by every badge
     * @return array<string, int>
     */
    public static function aggregate(Order $order, bool $includeSearchablePos, Closure $applySharedFilters): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);

        $scheduledExcluded = $order->newQuery()
            ->forOrderList($includeSearchablePos)
            ->notSchedule();
        $applySharedFilters($scheduledExcluded);
        $scheduledExcluded = self::pluckCounts(
            $scheduledExcluded
                ->selectRaw('order_status, COUNT(*) as aggregate_count')
                ->groupBy('order_status')
                ->pluck('aggregate_count', 'order_status')
        );

        foreach ($scheduledExcluded as $status => $count) {
            if (array_key_exists($status, $counts) && $status !== 'delivered') {
                $counts[$status] = $count;
            }
        }

        // Admin list historically counted delivered without notSchedule(); preserve that.
        $deliveredQuery = $order->newQuery()
            ->forOrderList($includeSearchablePos)
            ->where('order_status', 'delivered');
        $applySharedFilters($deliveredQuery);
        $counts['delivered'] = (int) $deliveredQuery->count();

        return $counts;
    }

    /**
     * Branch list applies notSchedule() to every badge including delivered.
     *
     * @param  Closure(Builder): void  $applySharedFilters
     * @return array<string, int>
     */
    public static function aggregateBranch(Order $order, bool $includeSearchablePos, Closure $applySharedFilters): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);

        $query = $order->newQuery()
            ->forOrderList($includeSearchablePos)
            ->notSchedule();
        $applySharedFilters($query);
        $rows = self::pluckCounts(
            $query
                ->selectRaw('order_status, COUNT(*) as aggregate_count')
                ->groupBy('order_status')
                ->pluck('aggregate_count', 'order_status')
        );

        foreach ($rows as $status => $count) {
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $count;
            }
        }

        return $counts;
    }

    /**
     * @param  Collection<string, int|string>  $pluck
     * @return array<string, int>
     */
    private static function pluckCounts(Collection $pluck): array
    {
        $out = [];
        foreach ($pluck as $status => $count) {
            $out[(string) $status] = (int) $count;
        }

        return $out;
    }
}
