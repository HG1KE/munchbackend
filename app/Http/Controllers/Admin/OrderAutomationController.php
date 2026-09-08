<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\OrderAutomationService;
use App\Http\Controllers\Controller;
use App\Model\OrderAutomationRun;
use App\Model\OrderAutomationSetting;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderAutomationController extends Controller
{
    public function index(): View
    {
        $settings = OrderAutomationSetting::current();
        $preview = OrderAutomationService::previewEligible(manualRun: true);
        $runs = OrderAutomationRun::query()
            ->orderByDesc('id')
            ->limit(20)
            ->get();
        $lastRun = $runs->first();

        return view('admin-views.order.automation.index', compact('settings', 'preview', 'runs', 'lastRun'));
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'is_enabled' => 'required|in:0,1',
            'auto_complete_hours' => 'required|integer|min:1|max:720',
            'eligible_statuses' => 'nullable|array',
            'eligible_statuses.*' => 'string|max:32',
            'excluded_statuses' => 'nullable|array',
            'excluded_statuses.*' => 'string|max:32',
            'branch_ids' => 'nullable|string|max:255',
            'dry_run' => 'required|in:0,1',
            'require_delivery_man' => 'required|in:0,1',
        ]);

        $settings = OrderAutomationSetting::current();
        $settings->update([
            'is_enabled' => (int) $validated['is_enabled'] === 1,
            'auto_complete_hours' => (int) $validated['auto_complete_hours'],
            'eligible_statuses' => array_values($validated['eligible_statuses'] ?? config('order_automation.default_eligible_statuses')),
            'excluded_statuses' => array_values($validated['excluded_statuses'] ?? config('order_automation.default_excluded_statuses')),
            'branch_ids' => $validated['branch_ids'] ?? null,
            'dry_run' => (int) $validated['dry_run'] === 1,
            'require_delivery_man' => (int) $validated['require_delivery_man'] === 1,
        ]);

        Toastr::success(translate('Settings updated successfully'));

        return back();
    }

    public function preview(Request $request): JsonResponse
    {
        $manual = $request->boolean('manual', true);
        $preview = OrderAutomationService::previewEligible($manual);

        return response()->json($preview);
    }

    public function runManual(Request $request): RedirectResponse
    {
        $dryRun = $request->boolean('dry_run');
        $adminId = auth('admin')->id();

        $result = OrderAutomationService::run('manual', manualRun: true, adminId: is_numeric($adminId) ? (int) $adminId : null, forceDryRun: $dryRun);

        Toastr::success($result['message']);

        return back();
    }
}
