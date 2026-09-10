<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Model\Order;
use App\Services\DashboardOrderOperationsService;
use App\Support\BranchOnlineOrdering;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Support\Renderable;

class SystemController extends Controller
{
    public function __construct(
        private Order $order,
        private DashboardOrderOperationsService $operations,
    )
    {}

    /**
     * @return JsonResponse
     */
    public function restaurantData(): JsonResponse
    {
        $branchId = (int) auth('branch')->id();
        $payload = BranchOnlineOrdering::isEnabled(auth('branch')->user())
            ? $this->operations->pendingOrderAlertPayload($branchId)
            : BranchOnlineOrdering::silencedAlertPayload();

        return response()->json([
            'success' => 1,
            'data' => $payload,
        ]);
    }

}
