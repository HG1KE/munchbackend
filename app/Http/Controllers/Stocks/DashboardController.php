<?php

namespace App\Http\Controllers\Stocks;

use App\Http\Controllers\Controller;
use App\Model\StockDailyCount;
use App\Services\Stock\StockVarianceService;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

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
            ? $this->variance->rowsForBranchDate($branchId, $date)
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

    public function saveCounts(Request $request): RedirectResponse
    {
        $manager = auth('stocks')->user();
        $manager->loadMissing('branches');

        $request->validate([
            'date' => 'required|date',
            'branch_id' => 'required|integer|exists:branches,id',
            'opening' => 'nullable|array',
            'closing' => 'nullable|array',
        ]);

        $branchId = (int) $request->input('branch_id');
        if (! $manager->canAccessBranch($branchId)) {
            abort(403, translate('You do not have access to this branch'));
        }

        $date = $request->input('date') ?: now()->toDateString();

        if (! Schema::hasTable('stock_daily_counts')) {
            Toastr::error(translate('Stock counts table is missing. Run migrations.'));
            return back();
        }

        $openings = is_array($request->input('opening')) ? $request->input('opening') : [];
        $closings = is_array($request->input('closing')) ? $request->input('closing') : [];
        $itemIds = array_unique(array_merge(array_keys($openings), array_keys($closings)));

        foreach ($itemIds as $rawId) {
            $stockItemId = (int) $rawId;
            if ($stockItemId <= 0) {
                continue;
            }

            $openingRaw = $openings[$rawId] ?? null;
            $closingRaw = $closings[$rawId] ?? null;

            $hasOpening = $openingRaw !== null && $openingRaw !== '';
            $hasClosing = $closingRaw !== null && $closingRaw !== '';
            if (! $hasOpening && ! $hasClosing) {
                continue;
            }

            $existing = StockDailyCount::query()
                ->where('branch_id', $branchId)
                ->where('stock_item_id', $stockItemId)
                ->whereDate('count_date', $date)
                ->first();

            $opening = $hasOpening
                ? (float) $openingRaw
                : (float) ($existing->opening_qty ?? 0);
            $closing = $hasClosing ? (float) $closingRaw : ($existing->closing_qty ?? null);

            StockDailyCount::query()->updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'stock_item_id' => $stockItemId,
                    'count_date' => $date,
                ],
                [
                    'opening_qty' => $opening,
                    'closing_qty' => $closing,
                    'stock_manager_id' => (int) $manager->id,
                ]
            );
        }

        Toastr::success(translate('Stock counts saved.'));

        return redirect()->route('stocks.dashboard', [
            'date' => $date,
            'branch_id' => $branchId,
        ]);
    }
}
