<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminDashboardSalesKpiBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardSalesKpiEventsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $since = (int) $request->query('since', 0);
        $isPoll = $request->boolean('poll');
        $wait = (! $isPoll || app()->environment('testing')) ? 0.0 : 20.0;

        if (! $isPoll) {
            $handshake = AdminDashboardSalesKpiBus::snapshot($since);
            $handshake['events'] = [];

            return response()->json($handshake);
        }

        return response()->json(AdminDashboardSalesKpiBus::waitForEvents($since, $wait));
    }
}
