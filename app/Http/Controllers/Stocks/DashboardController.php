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
        $branch = $manager->branch;
        $branchId = (int) $manager->branch_id;
        $date = $request->input('date') ?: now()->toDateString();

        $rows = $this->variance->rowsForBranchDate($branchId, $date);
        $branchName = $branch?->name ?: translate('Stocks');

        return view('stocks-views.dashboard.index', compact('rows', 'date', 'branchName', 'branchId'));
    }

    public function saveCounts(Request $request): RedirectResponse
    {
        $manager = auth('stocks')->user();
        $branchId = (int) $manager->branch_id;
        $date = $request->input('date') ?: now()->toDateString();

        $request->validate([
            'date' => 'required|date',
            'opening' => 'nullable|array',
            'closing' => 'nullable|array',
        ]);

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

        return redirect()->route('stocks.dashboard', ['date' => $date]);
    }
}
