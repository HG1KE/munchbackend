<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Services\DashboardOrderOperationsService;
use Illuminate\Contracts\Support\Renderable;

class OrderOperationsController extends Controller
{
    public function __construct(
        private DashboardOrderOperationsService $operations,
    ) {}

    public function online(): Renderable
    {
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
