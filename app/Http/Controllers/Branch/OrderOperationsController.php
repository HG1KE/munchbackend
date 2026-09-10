<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Services\DashboardOrderOperationsService;
use App\Support\BranchOnlineOrdering;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;

class OrderOperationsController extends Controller
{
    public function __construct(
        private DashboardOrderOperationsService $operations,
    ) {}

    public function online(): Renderable|RedirectResponse
    {
        if (! BranchOnlineOrdering::isEnabled(auth('branch')->user())) {
            return redirect()->route('branch.dashboard');
        }

        $branchId = (int) auth('branch')->id();
        $pending = $this->operations->mapExpressOrderCards(
            $this->operations->expressPendingQueue($branchId),
            'pending'
        );
        $packing = $this->operations->mapExpressOrderCards(
            $this->operations->expressPackingQueue($branchId),
            'packing'
        );
        $dispatched = $this->operations->mapExpressOrderCards(
            $this->operations->expressDispatchedQueue($branchId),
            'dispatched'
        );

        return view('branch-views.orders.online', [
            'pendingOrders' => $pending,
            'packingOrders' => $packing,
            'dispatchedOrders' => $dispatched,
            'detailRoute' => 'branch.orders.details',
            'panel' => 'branch',
        ]);
    }
}
