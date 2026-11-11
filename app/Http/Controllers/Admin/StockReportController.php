<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Model\Branch;
use App\Services\Stock\StockVarianceService;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;

class StockReportController extends Controller
{
    public function __construct(
        private Branch $branch,
        private StockVarianceService $variance
    ) {}

    public function index(Request $request): Renderable
    {
        $branches = $this->branch->orderBy('name')->get(['id', 'name']);
        $branchId = (int) ($request->input('branch_id') ?: ($branches->first()->id ?? 0));
        $date = $request->input('date') ?: now()->toDateString();

        $rows = $branchId > 0
            ? $this->variance->rowsForBranchDate($branchId, $date)
            : [];

        $selectedBranch = $branches->firstWhere('id', $branchId);

        return view('admin-views.stock-report.index', compact(
            'branches',
            'branchId',
            'date',
            'rows',
            'selectedBranch'
        ));
    }
}
