<?php

namespace App\Http\Controllers\Admin;

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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BranchTimeScheduleController extends Controller
{
    public function __construct(
        private BranchTimeSchedule $branchTimeSchedule,
        private Branch $branch,
        private TimeSchedule $timeSchedule
    ) {}

    /**
     * @return Application|Factory|View|\Illuminate\Foundation\Application
     */
    public function branchTimeScheduleIndex(): View|\Illuminate\Foundation\Application|Factory|Application
    {
        $branches = $this->branch
            ->with(['branch_time_schedules'])
            ->get(['id', 'name', 'status']);

        $restaurantSchedules = $this->timeSchedule->get();

        return view('admin-views.business-settings.restaurant.branch-time-schedule', compact('branches', 'restaurantSchedules'));
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function addBranchSchedule(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|exists:branches,id',
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
        $branch = $this->branch->find($request->branch_id);
        if (!$branch || $branch->status != 1) {
            return response()->json(['errors' => [
                ['code' => 'branch', 'message' => translate('Branch is not active')]
            ]]);
        }

        // Check for overlapping schedules within the same branch
        $temp = $this->branchTimeSchedule->where('branch_id', $request->branch_id)
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

        $this->branchTimeSchedule->create([
            'branch_id' => $request->branch_id,
            'day' => $request->day,
            'opening_time' => $request->start_time,
            'closing_time' => $request->end_time
        ]);

        $branches = $this->branch->with(['branch_time_schedules'])->get(['id', 'name', 'status']);
        $restaurantSchedules = $this->timeSchedule->get();

        return response()->json([
            'view' => view('admin-views.business-settings.partials._branch-schedule', compact('branches', 'restaurantSchedules'))->render()
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function removeBranchSchedule(Request $request): JsonResponse
    {
        $schedule = $this->branchTimeSchedule->find($request['schedule_id']);
        if (!$schedule) {
            return response()->json([], 404);
        }

        $schedule->delete();

        $branches = $this->branch->with(['branch_time_schedules'])->get(['id', 'name', 'status']);
        $restaurantSchedules = $this->timeSchedule->get();

        return response()->json([
            'view' => view('admin-views.business-settings.partials._branch-schedule', compact('branches', 'restaurantSchedules'))->render(),
        ]);
    }
}