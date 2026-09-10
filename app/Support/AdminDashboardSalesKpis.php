<?php

namespace App\Support;

use App\CentralLogics\Helpers;
use Illuminate\Support\Carbon;

/**
 * Admin Dashboard executive sales KPIs. Does not change Sale Report math.
 */
class AdminDashboardSalesKpis
{
    public const MUNCH_METHODS = ['cash', 'card', 'mpesa'];

    public const MARKETPLACE_CHANNELS = ['glovo', 'uber', 'bolt_food'];

    /**
     * @return array{from: Carbon, to: Carbon}
     */
    public static function period(string $timeframe, ?string $from = null, ?string $to = null, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now())->copy();

        return match ($timeframe) {
            'yesterday' => [
                'from' => $now->copy()->subDay()->startOfDay(),
                'to' => $now->copy()->subDay()->endOfDay(),
            ],
            'last_7_days' => [
                'from' => $now->copy()->subDays(6)->startOfDay(),
                'to' => $now->copy()->endOfDay(),
            ],
            'this_week' => [
                'from' => $now->copy()->startOfWeek()->startOfDay(),
                'to' => $now->copy()->endOfWeek()->endOfDay(),
            ],
            'last_week' => [
                'from' => $now->copy()->subWeek()->startOfWeek()->startOfDay(),
                'to' => $now->copy()->subWeek()->endOfWeek()->endOfDay(),
            ],
            'this_month' => [
                'from' => $now->copy()->startOfMonth()->startOfDay(),
                'to' => $now->copy()->endOfMonth()->endOfDay(),
            ],
            'last_month' => [
                'from' => $now->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay(),
                'to' => $now->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay(),
            ],
            'custom' => self::customPeriod($from, $to, $now),
            default => [
                'from' => $now->copy()->startOfDay(),
                'to' => $now->copy()->endOfDay(),
            ],
        };
    }

    /**
     * @param  list<array{payment_method?: mixed, sales_channel?: mixed, total?: mixed}>  $rows
     * @return array{
     *     cash: float,
     *     card: float,
     *     mpesa: float,
     *     munch_sales: float,
     *     glovo: float,
     *     uber: float,
     *     bolt_food: float
     * }
     */
    public static function fromGroupedRows(array $rows): array
    {
        $totals = [
            'cash' => 0.0,
            'card' => 0.0,
            'mpesa' => 0.0,
            'glovo' => 0.0,
            'uber' => 0.0,
            'bolt_food' => 0.0,
        ];

        foreach ($rows as $row) {
            $method = (string) ($row['payment_method'] ?? '');
            $channel = (string) ($row['sales_channel'] ?? '');
            $amount = round((float) ($row['total'] ?? 0), 2);
            if ($amount == 0.0) {
                continue;
            }

            $market = null;
            if (in_array($method, self::MARKETPLACE_CHANNELS, true)) {
                $market = $method;
            } elseif (in_array($channel, self::MARKETPLACE_CHANNELS, true)) {
                $market = $channel;
            }

            if ($market !== null) {
                $totals[$market] += $amount;
                continue;
            }

            if (in_array($method, self::MUNCH_METHODS, true)) {
                $totals[$method] += $amount;
            }
        }

        $groups = AdminSaleReportSummary::fromPaymentTotals($totals);
        $totals['munch_sales'] = $groups['munch_sales'];

        return $totals;
    }

    /**
     * @param  array{
     *     cash: float,
     *     card: float,
     *     mpesa: float,
     *     munch_sales: float,
     *     glovo: float,
     *     uber: float,
     *     bolt_food: float
     * }  $totals
     * @param  array{from: Carbon, to: Carbon}  $period
     * @return array<string, mixed>
     */
    public static function present(array $totals, array $period, string $timeframe = 'today', int|string|null $branchId = null): array
    {
        $formatted = [];
        foreach (['munch_sales', 'cash', 'card', 'mpesa', 'glovo', 'uber', 'bolt_food'] as $key) {
            $formatted[$key] = Helpers::set_symbol((float) ($totals[$key] ?? 0));
        }

        $formatted['timeframe'] = $timeframe === '' ? 'today' : $timeframe;
        $formatted['branch_id'] = $branchId === null || $branchId === '' ? 'all' : $branchId;
        $formatted['from'] = $period['from']->toDateString();
        $formatted['to'] = $period['to']->toDateString();

        return $formatted;
    }

    /**
     * @return array{from: Carbon, to: Carbon}
     */
    private static function customPeriod(?string $from, ?string $to, Carbon $now): array
    {
        $start = $from ? Carbon::parse($from)->startOfDay() : $now->copy()->startOfDay();
        $end = $to ? Carbon::parse($to)->endOfDay() : $now->copy()->endOfDay();
        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return ['from' => $start, 'to' => $end];
    }
}
