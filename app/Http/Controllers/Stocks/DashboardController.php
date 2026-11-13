<?php

namespace App\Http\Controllers\Stocks;

use App\Http\Controllers\Controller;
use App\Services\Stock\StockCountPersistService;
use App\Services\Stock\StockVarianceService;
use App\Support\StocksAuditExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function __construct(
        private StockVarianceService $variance,
        private StockCountPersistService $counts
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
            ? $this->variance->rowsForBranchDate($branchId, $date, 'missing')
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
        $rows = $this->variance->rowsForBranchDate($branchId, $date, 'missing');
        $posted = $this->qtyMapsFromRequest($request);
        if ($posted !== null) {
            $rows = array_map(function (array $row) use ($posted) {
                $id = (int) $row['stock_item_id'];
                $key = (string) $id;
                $openingRaw = $posted['opening'][$id] ?? $posted['opening'][$key] ?? null;
                $receivedRaw = $posted['received'][$id] ?? $posted['received'][$key] ?? null;
                $spoiledRaw = $posted['spoiled'][$id] ?? $posted['spoiled'][$key] ?? null;
                $closingRaw = $posted['closing'][$id] ?? $posted['closing'][$key] ?? null;

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
                $row['variance_label'] = $this->variance->formatVarianceLabel($variance, 'missing');

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

        // Export used to build the PDF from the on-screen form without writing
        // stock_daily_counts — admin + manager reload then looked empty. Persist
        // the complete posted (or already-saved) rows before downloading.
        $persistError = $this->counts->persistComplete($manager, $branchId, $date, $posted);
        if ($persistError !== null) {
            Toastr::error(translate($persistError));

            return redirect()->route('stocks.dashboard', [
                'date' => $date,
                'branch_id' => $branchId,
            ]);
        }

        return Pdf::loadView('stocks-views.dashboard.partials._stocks-audit-export', compact('report'))
            ->download(StocksAuditExport::filename($report));
    }

    public function saveCounts(Request $request): RedirectResponse|JsonResponse
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
            'partial' => 'nullable',
        ]);

        $branchId = (int) $request->input('branch_id');
        $partial = $request->boolean('partial');

        if (! $manager->canAccessBranch($branchId)) {
            if ($partial) {
                return response()->json([
                    'ok' => false,
                    'error' => translate('You do not have access to this branch'),
                ], 403);
            }
            abort(403, translate('You do not have access to this branch'));
        }

        $date = $request->input('date') ?: now()->toDateString();
        $posted = $this->qtyMapsFromRequest($request) ?? [
            'opening' => [],
            'received' => [],
            'spoiled' => [],
            'closing' => [],
        ];

        if ($partial) {
            $result = $this->counts->persistPartial($manager, $branchId, $date, $posted);
            if (! $result['ok']) {
                return response()->json([
                    'ok' => false,
                    'error' => translate($result['error'] ?? 'Could not save counts.'),
                    'saved' => $result['saved'],
                ], 422);
            }

            return response()->json([
                'ok' => true,
                'saved' => $result['saved'],
                'message' => translate('Saved'),
            ]);
        }

        $persistError = $this->counts->persistComplete($manager, $branchId, $date, $posted);
        if ($persistError !== null) {
            Toastr::error(translate($persistError));

            return back()->withInput();
        }

        Toastr::success(translate('Stock counts saved.'));

        return redirect()->route('stocks.dashboard', [
            'date' => $date,
            'branch_id' => $branchId,
            'saved' => 1,
        ]);
    }

    /**
     * @return array{opening:array,received:array,spoiled:array,closing:array}|null
     */
    private function qtyMapsFromRequest(Request $request): ?array
    {
        if (
            ! $request->has('opening')
            && ! $request->has('received')
            && ! $request->has('spoiled')
            && ! $request->has('closing')
        ) {
            return null;
        }

        return [
            'opening' => is_array($request->input('opening')) ? $request->input('opening') : [],
            'received' => is_array($request->input('received')) ? $request->input('received') : [],
            'spoiled' => is_array($request->input('spoiled')) ? $request->input('spoiled') : [],
            'closing' => is_array($request->input('closing')) ? $request->input('closing') : [],
        ];
    }
}
