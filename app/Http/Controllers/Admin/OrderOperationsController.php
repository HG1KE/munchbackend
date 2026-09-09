<?php

namespace App\Http\Controllers\Admin;

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
        $this->operations->acknowledgePendingQueue(null);

        $pending = $this->operations->mapExpressOrderCards(
            $this->operations->expressPendingQueue(null),
            'pending'
        );
        $packing = $this->operations->mapExpressOrderCards(
            $this->operations->expressPackingQueue(null),
            'packing'
        );
        $dispatched = $this->operations->mapExpressOrderCards(
            $this->operations->expressDispatchedQueue(null),
            'dispatched'
        );

        return view('admin-views.orders.online', [
            'pendingOrders' => $pending,
            'packingOrders' => $packing,
            'dispatchedOrders' => $dispatched,
            'detailRoute' => 'admin.orders.details',
            'panel' => 'admin',
        ]);
    }
}
