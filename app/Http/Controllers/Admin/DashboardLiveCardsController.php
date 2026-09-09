<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardOrderOperationsService;
use Illuminate\Http\JsonResponse;

class DashboardLiveCardsController extends Controller
{
    public function __construct(
        private DashboardOrderOperationsService $operations,
    ) {}

    public function __invoke(): JsonResponse
    {
        $counts = $this->operations->dashboardCounts(null);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'operations' => [
                'online' => (int) $counts['online'],
                'express' => (int) $counts['express'],
            ],
        ]);
    }
}
