<?php

namespace App\Http\Controllers\Stocks;

use App\Http\Controllers\Controller;
use App\Services\Stock\StockAllocationCoverageService;
use App\Services\Stock\StockCountPersistService;
use App\Services\Stock\StockExportArchiveService;
use App\Services\Stock\StockOpeningContinuityService;
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
        private StockCountPersistService $counts,
        private StockOpeningContinuityService $openingContinuity,
        private StockAllocationCoverageService $allocationCoverage,
        private StockExportArchiveService $exportArchive
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

        $openingGaps = $branchId > 0
            ? $this->openingContinuity->mismatches($branchId, $date)
            : [];
        $allocationGaps = $branchId > 0
            ? $this->allocationCoverage->gapsForBranchDate($branchId, $date)
            : ['products' => [], 'addons' => []];
        $soldDrilldown = $branchId > 0
            ? $this->variance->soldDrilldownByItem($branchId, $date)
            : [];
        $hiddenPdfItems = array_values(array_filter($rows, static function (array $row) {
            return array_key_exists('show_in_stock_pdf', $row)
                && ! filter_var($row['show_in_stock_pdf'], FILTER_VALIDATE_BOOLEAN);
        }));

        $currentBranch = $accessible->firstWhere('id', $branchId);
        $branchName = $currentBranch?->name ?: translate('Stocks');

        return view('stocks-views.dashboard.index', compact(
            'rows',
            'date',
            'branchName',
            'branchId',
            'accessible',
            'showBranchPicker',
            'openingGaps',
            'allocationGaps',
            'soldDrilldown',
            'hiddenPdfItems'
        ));
    }

    public function exportReport(Request $request): Response|RedirectResponse
    {
        $manager = auth('stocks')->user();
        $manager->loadMissing('branches');

        $request->validate([
            'date' => 'required|date',
            'branch_id' => 'required|integer|exists:branches,id',
            'opening' => 'nullable|array',
            'opening.*' => 'nullable|numeric|min:0',
            'received' => 'nullable|array',
            'received.*' => 'nullable|numeric|min:0',
            'spoiled' => 'nullable|array',
            'spoiled.*' => 'nullable|numeric|min:0',
            'closing' => 'nullable|array',
            'closing.*' => 'nullable|numeric|min:0',
            'acknowledge_opening_gap' => 'nullable',
            'acknowledge_hidden_items' => 'nullable',
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
            $neg = $this->counts->rejectNegativeMaps($posted);
            if ($neg !== null) {
                Toastr::error(translate($neg));

                return redirect()->route('stocks.dashboard', [
                    'date' => $date,
                    'branch_id' => $branchId,
                ]);
            }

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

        $openingGaps = $this->openingContinuity->mismatches($branchId, $date, $posted);
        if ($openingGaps !== [] && ! $request->boolean('acknowledge_opening_gap')) {
            Toastr::error(translate(StockOpeningContinuityService::WARN_MESSAGE));

            return redirect()->route('stocks.dashboard', [
                'date' => $date,
                'branch_id' => $branchId,
            ])->with('opening_gaps', $openingGaps);
        }

        $soldDrilldown = $this->variance->soldDrilldownByItem($branchId, $date);
        $report = StocksAuditExport::build($branchName, $date, $rows, $soldDrilldown);

        if (! empty($report['requires_hidden_ack']) && ! $request->boolean('acknowledge_hidden_items')) {
            Toastr::error(translate(StocksAuditExport::HIDDEN_ACK_MESSAGE));

            return redirect()->route('stocks.dashboard', [
                'date' => $date,
                'branch_id' => $branchId,
            ])->with('omitted_appendix', $report['omitted_appendix'] ?? []);
        }

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

        $pdf = Pdf::loadView('stocks-views.dashboard.partials._stocks-audit-export', compact('report'));
        $binary = $pdf->output();
        $this->exportArchive->archive(
            $branchId,
            $date,
            $report,
            $report['omitted_appendix'] ?? [],
            $binary,
            'stocks',
            (int) $manager->id,
            null
        );

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.StocksAuditExport::filename($report).'"',
        ]);
    }

    public function saveCounts(Request $request): RedirectResponse|JsonResponse
    {
        $manager = auth('stocks')->user();
        $manager->loadMissing('branches');

        $request->validate([
            'date' => 'required|date',
            'branch_id' => 'required|integer|exists:branches,id',
            'opening' => 'nullable|array',
            'opening.*' => 'nullable|numeric|min:0',
            'received' => 'nullable|array',
            'received.*' => 'nullable|numeric|min:0',
            'spoiled' => 'nullable|array',
            'spoiled.*' => 'nullable|numeric|min:0',
            'closing' => 'nullable|array',
            'closing.*' => 'nullable|numeric|min:0',
            'partial' => 'nullable',
            'acknowledge_opening_gap' => 'nullable',
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

            $openingGaps = $this->openingContinuity->mismatches($branchId, $date, $posted);

            return response()->json([
                'ok' => true,
                'saved' => $result['saved'],
                'message' => translate('Saved'),
                'opening_gaps' => $openingGaps,
                'opening_gap_warning' => $openingGaps === [] ? null : translate(StockOpeningContinuityService::WARN_MESSAGE),
            ]);
        }

        $openingGaps = $this->openingContinuity->mismatches($branchId, $date, $posted);
        if ($openingGaps !== [] && ! $request->boolean('acknowledge_opening_gap')) {
            Toastr::error(translate(StockOpeningContinuityService::WARN_MESSAGE));

            return back()->withInput()->with('opening_gaps', $openingGaps);
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
