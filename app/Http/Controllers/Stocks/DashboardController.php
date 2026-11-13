<?php

namespace App\Http\Controllers\Stocks;

use App\Http\Controllers\Controller;
use App\Model\StockDailyCount;
use App\Model\StockItem;
use App\Services\Stock\StockVarianceService;
use App\Support\StocksAuditExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function __construct(
        private StockVarianceService $variance
    ) {}

    public function index(Request $request): Renderable
    {
        $manager = auth('stocks')->user();
        $manager->loadMissing('branches');

        $accessible = $manager->accessibleBranches();
        $showBranchPicker = $accessible->count() > 1 || (bool) $manager->all_branches;

        $branchId = (int) $request->input('branch_id', 0);
        if ($branchId > 0 && ! $manager->canAccessBranch($branchId)) {
            abort(403, translate('You do not have access to this branch'));
        }

        if ($branchId <= 0) {
            $branchId = (int) ($accessible->first()->id ?? 0);
        }

        $date = $request->input('date') ?: now()->toDateString();

        $rows = $branchId > 0
            ? $this->variance->rowsForBranchDate($branchId, $date, 'short')
            : [];

        $currentBranch = $accessible->firstWhere('id', $branchId);
        $branchName = $currentBranch?->name ?: translate('Stocks');

        return view('stocks-views.dashboard.index', compact(
            'rows',
            'date',
            'branchName',
            'branchId',
            'accessible',
            'showBranchPicker'
        ));
    }

    public function exportReport(Request $request): Response|RedirectResponse
    {
        $manager = auth('stocks')->user();
        $manager->loadMissing('branches');

        $request->validate([
            'date' => 'required|date',
            'branch_id' => 'required|integer|exists:branches,id',
        ]);

        $branchId = (int) $request->input('branch_id');
        if (! $manager->canAccessBranch($branchId)) {
            abort(403, translate('You do not have access to this branch'));
        }

        $date = $request->input('date') ?: now()->toDateString();
        $accessible = $manager->accessibleBranches();
        $currentBranch = $accessible->firstWhere('id', $branchId);
        $branchName = $currentBranch?->name ?: translate('Stocks');

        // Prefer the filled table on screen (posted values). Fall back to saved counts.
        $rows = $this->variance->rowsForBranchDate($branchId, $date, 'short');
        if (
            $request->has('opening')
            || $request->has('received')
            || $request->has('spoiled')
            || $request->has('closing')
        ) {
            $openings = is_array($request->input('opening')) ? $request->input('opening') : [];
            $receiveds = is_array($request->input('received')) ? $request->input('received') : [];
            $spoileds = is_array($request->input('spoiled')) ? $request->input('spoiled') : [];
            $closings = is_array($request->input('closing')) ? $request->input('closing') : [];
            $rows = array_map(function (array $row) use ($openings, $receiveds, $spoileds, $closings) {
                $id = (int) $row['stock_item_id'];
                $key = (string) $id;
                $openingRaw = $openings[$id] ?? $openings[$key] ?? null;
                $receivedRaw = $receiveds[$id] ?? $receiveds[$key] ?? null;
                $spoiledRaw = $spoileds[$id] ?? $spoileds[$key] ?? null;
                $closingRaw = $closings[$id] ?? $closings[$key] ?? null;

                $opening = ($openingRaw === null || $openingRaw === '') ? null : (float) $openingRaw;
                $received = ($receivedRaw === null || $receivedRaw === '') ? null : (float) $receivedRaw;
                $spoiled = ($spoiledRaw === null || $spoiledRaw === '') ? null : (float) $spoiledRaw;
                $closing = ($closingRaw === null || $closingRaw === '') ? null : (float) $closingRaw;
                $sold = (float) ($row['expected_sold'] ?? 0);
                $variance = StockVarianceService::result($opening, $received, $sold, $closing, $spoiled);

                $row['opening_qty'] = $opening;
                $row['received_qty'] = $received;
                $row['spoiled_qty'] = $spoiled;
                $row['closing_qty'] = $closing;
                $row['variance'] = $variance;
                $row['variance_label'] = $this->variance->formatVarianceLabel($variance, 'short');

                return $row;
            }, $rows);
        }

        $report = StocksAuditExport::build($branchName, $date, $rows);

        if (! StocksAuditExport::isComplete($report)) {
            Toastr::error(translate(StocksAuditExport::INCOMPLETE_MESSAGE));

            return redirect()->route('stocks.dashboard', [
                'date' => $date,
                'branch_id' => $branchId,
            ]);
        }

        return Pdf::loadView('stocks-views.dashboard.partials._stocks-audit-export', compact('report'))
            ->download(StocksAuditExport::filename($report));
    }

    public function saveCounts(Request $request): RedirectResponse
    {
        $manager = auth('stocks')->user();
        $manager->loadMissing('branches');

        $request->validate([
            'date' => 'required|date',
            'branch_id' => 'required|integer|exists:branches,id',
            'opening' => 'nullable|array',
            'received' => 'nullable|array',
            'spoiled' => 'nullable|array',
            'closing' => 'nullable|array',
        ]);

        $branchId = (int) $request->input('branch_id');
        if (! $manager->canAccessBranch($branchId)) {
            abort(403, translate('You do not have access to this branch'));
        }

        $date = $request->input('date') ?: now()->toDateString();

        if (! Schema::hasTable('stock_daily_counts') || ! Schema::hasTable('stock_items')) {
            Toastr::error(translate('Stock counts table is missing. Run migrations.'));
            return back();
        }

        $openings = is_array($request->input('opening')) ? $request->input('opening') : [];
        $receiveds = is_array($request->input('received')) ? $request->input('received') : [];
        $spoileds = is_array($request->input('spoiled')) ? $request->input('spoiled') : [];
        $closings = is_array($request->input('closing')) ? $request->input('closing') : [];
        $activeItemIds = StockItem::query()->active()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($activeItemIds as $stockItemId) {
            $openingRaw = $openings[$stockItemId] ?? $openings[(string) $stockItemId] ?? null;
            $receivedRaw = $receiveds[$stockItemId] ?? $receiveds[(string) $stockItemId] ?? null;
            $spoiledRaw = $spoileds[$stockItemId] ?? $spoileds[(string) $stockItemId] ?? null;
            $closingRaw = $closings[$stockItemId] ?? $closings[(string) $stockItemId] ?? null;
            $hasOpening = $openingRaw !== null && $openingRaw !== '';
            $hasReceived = $receivedRaw !== null && $receivedRaw !== '';
            $hasSpoiled = $spoiledRaw !== null && $spoiledRaw !== '';
            $hasClosing = $closingRaw !== null && $closingRaw !== '';
            if (! $hasOpening || ! $hasReceived || ! $hasSpoiled || ! $hasClosing) {
                Toastr::error(translate('Fill in all boxes before saving'));
                return back()->withInput();
            }
        }

        foreach ($activeItemIds as $stockItemId) {
            $openingRaw = $openings[$stockItemId] ?? $openings[(string) $stockItemId];
            $receivedRaw = $receiveds[$stockItemId] ?? $receiveds[(string) $stockItemId];
            $spoiledRaw = $spoileds[$stockItemId] ?? $spoileds[(string) $stockItemId];
            $closingRaw = $closings[$stockItemId] ?? $closings[(string) $stockItemId];

            StockDailyCount::query()->updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'stock_item_id' => $stockItemId,
                    'count_date' => $date,
                ],
                [
                    'opening_qty' => (float) $openingRaw,
                    'received_qty' => (float) $receivedRaw,
                    'spoiled_qty' => (float) $spoiledRaw,
                    'closing_qty' => (float) $closingRaw,
                    'stock_manager_id' => (int) $manager->id,
                ]
            );
        }

        Toastr::success(translate('Stock counts saved.'));

        return redirect()->route('stocks.dashboard', [
            'date' => $date,
            'branch_id' => $branchId,
            'saved' => 1,
        ]);
    }
}
