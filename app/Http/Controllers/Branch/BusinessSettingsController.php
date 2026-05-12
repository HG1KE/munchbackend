<?php

namespace App\Http\Controllers\Branch;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Model\Branch;
use App\Model\BranchTimeSchedule;
use App\Model\TimeSchedule;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BusinessSettingsController extends Controller
{
    /**
     * @return Application|Factory|View
     */
    public function branchIndex(): Factory|View|Application
    {
        $branch = Branch::with('branch_time_schedules')->find(auth('branch')->user()->id);
        return view('branch-views.business-settings.branch-index', compact('branch'));
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function settingsUpdate(Request $request): RedirectResponse
    {
        $branch = Branch::find(auth('branch')->user()->id);
        $branch->name = $request->name;
        $branch->preparation_time = $request->preparation_time;
        $branch->save();

        Toastr::success(translate('settings updated!'));
        return back();
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function addBranchSchedule(Request $request): JsonResponse
    {
        $branchId = auth('branch')->user()->id;
        
        $validator = Validator::make($request->all(), [
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'day' => 'required|integer|min:0|max:6'
        ], [
            'end_time.after' => translate('End time must be after the start time')
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }

        // Check if branch is active
        $branch = Branch::find($branchId);
        if (!$branch || $branch->status != 1) {
            return response()->json(['errors' => [
                ['code' => 'branch', 'message' => translate('Branch is not active')]
            ]]);
        }

        // Check for overlapping schedules within the same branch
        $temp = BranchTimeSchedule::where('branch_id', $branchId)
            ->where('day', $request->day)
            ->where(function ($q) use ($request) {
                return $q->where(function ($query) use ($request) {
                    return $query->where('opening_time', '<=', $request->start_time)->where('closing_time', '>=', $request->start_time);
                })->orWhere(function ($query) use ($request) {
                    return $query->where('opening_time', '<=', $request->end_time)->where('closing_time', '>=', $request->end_time);
                });
            })
            ->first();

        if (isset($temp)) {
            return response()->json(['errors' => [
                ['code' => 'time', 'message' => translate('schedule_overlapping_warning')]
            ]]);
        }

        BranchTimeSchedule::create([
            'branch_id' => $branchId,
            'day' => $request->day,
            'opening_time' => $request->start_time,
            'closing_time' => $request->end_time
        ]);

        $branch = Branch::with('branch_time_schedules')->find($branchId);

        return response()->json([
            'view' => view('branch-views.business-settings.partials._branch-schedule', compact('branch'))->render()
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function removeBranchSchedule(Request $request): JsonResponse
    {
        $branchId = auth('branch')->user()->id;
        $schedule = BranchTimeSchedule::where('branch_id', $branchId)->find($request['schedule_id']);
        
        if (!$schedule) {
            return response()->json([], 404);
        }

        $schedule->delete();

        $branch = Branch::with('branch_time_schedules')->find($branchId);

        return response()->json([
            'view' => view('branch-views.business-settings.partials._branch-schedule', compact('branch'))->render(),
        ]);
    }
}
