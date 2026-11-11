<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Model\Branch;
use App\Services\Stock\StockVarianceService;
use App\Support\StocksAuditExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StockReportController extends Controller
{
    public const MAX_RANGE_DAYS = 62;

    public function __construct(
        private Branch $branch,
        private StockVarianceService $variance
    ) {}

    public function index(Request $request): Renderable
    {
        $branches = $this->branch->orderBy('name')->get(['id', 'name']);
        $branchId = (int) ($request->input('branch_id') ?: ($branches->first()->id ?? 0));
        [$from, $to] = $this->resolveRange($request, clamp: true);

        $sections = $branchId > 0
            ? $this->sectionsForRange($branchId, $from, $to)
            : [];

        $selectedBranch = $branches->firstWhere('id', $branchId);

        return view('admin-views.stock-report.index', compact(
            'branches',
            'branchId',
            'from',
            'to',
            'sections',
            'selectedBranch'
        ));
    }

    public function export(Request $request): Response|RedirectResponse
    {
        $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            // Back-compat: older single-day links may still send `date`.
            'date' => 'nullable|date',
        ]);

        $branchId = (int) $request->input('branch_id');
        $branch = $this->branch->newQuery()->find($branchId);
        if (! $branch) {
            Toastr::error(translate('Branch not found'));

            return redirect()->route('admin.stock-report.index');
        }

        [$from, $to] = $this->resolveRange($request, clamp: false);
        if ($from->diffInDays($to) + 1 > self::MAX_RANGE_DAYS) {
            Toastr::error(translate('Date range cannot exceed '.self::MAX_RANGE_DAYS.' days.'));

            return redirect()->route('admin.stock-report.index', [
                'branch_id' => $branchId,
                'from' => $from->toDateString(),
                'to' => $from->copy()->addDays(self::MAX_RANGE_DAYS - 1)->toDateString(),
            ]);
        }

        $dayRows = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $day = $cursor->toDateString();
            $dayRows[] = [
                'date' => $day,
                'rows' => $this->variance->rowsForBranchDate($branchId, $day, 'short'),
            ];
            $cursor->addDay();
        }

        $report = StocksAuditExport::buildRange((string) $branch->name, $from, $to, $dayRows);

        return Pdf::loadView('stocks-views.dashboard.partials._stocks-audit-export', compact('report'))
            ->download(StocksAuditExport::filename($report));
    }

    /**
     * @return list<array{date:string,date_label:string,rows:list<array<string,mixed>>}>
     */
    private function sectionsForRange(int $branchId, Carbon $from, Carbon $to): array
    {
        $sections = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $day = $cursor->toDateString();
            $sections[] = [
                'date' => $day,
                'date_label' => StocksAuditExport::formatReportDate($day),
                'rows' => $this->variance->rowsForBranchDate($branchId, $day, 'short'),
            ];
            $cursor->addDay();
        }

        return $sections;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(Request $request, bool $clamp = true): array
    {
        $today = now()->toDateString();
        $fromRaw = $request->input('from') ?: $request->input('date') ?: $today;
        $toRaw = $request->input('to') ?: $fromRaw;

        $from = Carbon::parse((string) $fromRaw)->startOfDay();
        $to = Carbon::parse((string) $toRaw)->startOfDay();

        if ($to->lt($from)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }

        if ($clamp && $from->diffInDays($to) + 1 > self::MAX_RANGE_DAYS) {
            $to = $from->copy()->addDays(self::MAX_RANGE_DAYS - 1);
        }

        return [$from, $to];
    }
}
