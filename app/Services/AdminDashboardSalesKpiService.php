<?php

namespace App\Services;

use App\Model\Order;
use App\Support\AdminDashboardSalesKpis;
use Illuminate\Http\Request;

class AdminDashboardSalesKpiService
{
    /**
     * @return array<string, mixed>
     */
    public function forRequest(Request $request): array
    {
        $branch = $request->input('branch_id', 'all');
        $branchId = ($branch === 'all' || $branch === '' || $branch === null) ? null : (int) $branch;
        if ($branchId !== null && $branchId < 1) {
            $branchId = null;
        }

        $timeframe = (string) $request->input('timeframe', 'today');
        if ($timeframe === '') {
            $timeframe = 'today';
        }

        return $this->summarize(
            $branchId,
            $timeframe,
            $request->input('from'),
            $request->input('to')
        );
    }

    /**
     * One aggregated query grouped by payment_method and sales_channel.
     *
     * @return array<string, mixed>
     */
    public function summarize(?int $branchId, string $timeframe = 'today', ?string $from = null, ?string $to = null): array
    {
        $period = AdminDashboardSalesKpis::period($timeframe, $from, $to);

        $rows = $this->aggregatedQuery($branchId, $period)
            ->get()
            ->map(fn ($row) => [
                'payment_method' => $row->payment_method,
                'sales_channel' => $row->sales_channel,
                'total' => (float) $row->total,
            ])
            ->all();

        return AdminDashboardSalesKpis::present(
            AdminDashboardSalesKpis::fromGroupedRows($rows),
            $period,
            $timeframe,
            $branchId
        );
    }

    /**
     * @param  array{from: \Illuminate\Support\Carbon, to: \Illuminate\Support\Carbon}  $period
     */
    public function aggregatedQuery(?int $branchId, array $period): \Illuminate\Database\Eloquent\Builder
    {
        return Order::query()
            ->earningReport()
            ->when($branchId !== null, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->whereBetween('created_at', [$period['from'], $period['to']])
            ->selectRaw('payment_method, sales_channel, COALESCE(SUM(order_amount), 0) as total')
            ->groupBy('payment_method', 'sales_channel');
    }
}
