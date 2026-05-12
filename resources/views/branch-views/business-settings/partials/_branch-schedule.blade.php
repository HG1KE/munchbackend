<div class="card-body" id="branch-schedule-view">
    @php($days = [
        1 => 'monday',
        2 => 'tuesday', 
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
        0 => 'sunday'
    ])
    
    @php($restaurantSchedules = \App\Model\TimeSchedule::get())
    @php($restaurantData = [])
    @foreach ($restaurantSchedules as $schedule)
        @php($restaurantData[$schedule->day][] = ['start_time' => $schedule->opening_time, 'end_time' => $schedule->closing_time])
    @endforeach

    @php($branchData = [])
    @foreach ($branch->branch_time_schedules as $schedule)
        @php($branchData[$schedule->day][] = ['id' => $schedule->id, 'start_time' => $schedule->opening_time, 'end_time' => $schedule->closing_time])
    @endforeach
    
    @if($branch->status != 1)
        <div class="alert alert-warning">
            <i class="tio-info"></i>
            {{translate('Your branch is currently inactive. Contact admin to activate your branch to set availability time slots.')}}
        </div>
    @endif
    
    @foreach($days as $dayNum => $dayName)
        <div class="time-schedule-row">
            <span class="time-schedule-date">{{translate($dayName)}}</span>
            
            @if(isset($branchData[$dayNum]) && count($branchData[$dayNum]))
                <div class="d-flex flex-wrap align-items-center gap-4 gap-sm-2 gapx-30">
                    @foreach ($branchData[$dayNum] as $schedule)
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <div class="border rounded py-2 px-3">
                                <div class="d-flex gap-2">
                                    <i class="tio-time mt-1"></i>
                                    <div>
                                        <div>{{translate('Opening_Time')}}</div>
                                        <div>{{date(config('time_format'), strtotime($schedule['start_time']))}}</div>
                                    </div>
                                </div>
                            </div>
                            <div class="border rounded py-2 px-3">
                                <div class="d-flex gap-2">
                                    <i class="tio-time mt-1"></i>
                                    <div>
                                        <div>{{translate('Closing_Time')}}</div>
                                        <div>{{date(config('time_format'), strtotime($schedule['end_time']))}}</div>
                                    </div>
                                </div>
                            </div>
                            <div class="badge badge-danger rounded-circle cursor-pointer" 
                                 onclick="deleteBranchSchedule('{{route('branch.business-settings.remove-schedule',['schedule_id'=>$schedule['id']])}}')">X</div>
                        </div>
                    @endforeach
                </div>
            @else
                @if(isset($restaurantData[$dayNum]) && count($restaurantData[$dayNum]))
                    <span class="btn btn-sm btn-outline-info m-1 disabled">
                        {{translate('Using Restaurant Schedule')}}
                        @foreach($restaurantData[$dayNum] as $restSchedule)
                            ({{date(config('time_format'), strtotime($restSchedule['start_time']))}} - {{date(config('time_format'), strtotime($restSchedule['end_time']))}})
                        @endforeach
                    </span>
                @else
                    <span class="btn btn-sm btn-outline-danger m-1 disabled">{{translate('Offday')}}</span>
                @endif
            @endif
            
            @if($branch->status == 1)
                <span class="add-schedule-btn ml-3" 
                      onclick="openBranchScheduleModal({{$dayNum}}, '{{translate($dayName)}}')">
                    <i class="tio-add"></i>
                </span>
            @endif
        </div>
    @endforeach
</div>