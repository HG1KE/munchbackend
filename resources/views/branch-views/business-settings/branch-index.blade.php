@extends('layouts.branch.app')

@section('title', translate('Business Settings'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <img width="20" class="avatar-img" src="{{asset('public/assets/admin/img/icons/business_setup2.png')}}" alt="">
                <span class="page-header-title">
                    {{translate('business_setup')}}
                </span>
            </h2>
        </div>

        <form action="{{ route('branch.business-settings.update') }}" method="post" enctype="multipart/form-data">
            @csrf
            <div class="card mb-3">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 col-sm-6">
                            <div class="form-group">
                                <label class="input-label text-capitalize">{{translate('Branch Name')}}</label>
                                <input value="{{$branch['name']}}" type="text" name="name"  maxlength="255" class="form-control"
                                       placeholder="{{translate('Ex: ABC Branch')}}">
                            </div>
                        </div>
                        <div class="col-md-6 col-sm-6">
                            <div class="form-group">
                                <label class="input-label text-capitalize">{{translate('preparation_time')}}<span class="text-danger mx-1">*</span>
                                    <i class="tio-info-outined"
                                       data-toggle="tooltip"
                                       data-placement="top"
                                       title="{{ translate('Food preparation time will show to customer.') }}">
                                    </i>
                                </label>
                                <input value="{{ $branch['preparation_time'] }}" type="number" name="preparation_time" class="form-control"
                                       placeholder=" Ex: {{ translate('30') }}" min="1" required>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="btn--container mt-4">
                <button type="reset" class="btn btn-secondary">{{translate('reset')}}</button>
                <button type="{{config('app.mode')!='demo'?'submit':'button'}}" class="btn btn-primary call-demo">{{translate('submit')}}</button>
            </div>
        </form>

        <!-- Branch Time Schedule Section -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0 d-flex gap-2 align-items-center">
                    <i class="tio-calendar"></i>
                    {{ translate('Branch_Availability_Time_Slot') }}
                </h5>
                <div class="text-muted small">
                    {{ translate('Set your branch specific availability time slots. If no time slot is set, your branch will use the Restaurant Availability Time Slot.') }}
                </div>
            </div>
            @include('branch-views.business-settings.partials._branch-schedule', compact('branch'))
        </div>
    </div>

    <!-- Branch Schedule Modal -->
    <div class="modal fade" id="branchScheduleModal" tabindex="-1" role="dialog" aria-labelledby="branchScheduleModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="branchScheduleModalLabel">{{translate('Create Schedule')}}</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form action="javascript:" method="post" id="add-branch-schedule">
                        @csrf
                        <input type="hidden" name="day" id="branch_day_id_input">
                        
                        <div class="form-group">
                            <label for="day_name_display">{{translate('Day')}}</label>
                            <input type="text" class="form-control" id="day_name_display" readonly>
                        </div>
                        
                        <div class="row">
                            <div class="col-6">
                                <div class="form-group">
                                    <label for="start_time">{{translate('Start Time')}}</label>
                                    <input type="time" class="form-control" name="start_time" id="start_time" required>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-group">
                                    <label for="end_time">{{translate('End Time')}}</label>
                                    <input type="time" class="form-control" name="end_time" id="end_time" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <small>
                                <strong>{{translate('Note')}}:</strong> 
                                {{translate('set_branch_specific_availability')}}
                            </small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{translate('Close')}}</button>
                    <button type="button" class="btn btn-primary" onclick="addBranchSchedule()">{{translate('Save')}}</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script_2')
    <script>
        function openBranchScheduleModal(day, dayName) {
            $('#branch_day_id_input').val(day);
            $('#day_name_display').val(dayName);
            $('#start_time').val('');
            $('#end_time').val('');
            $('#branchScheduleModal').modal('show');
        }

        function addBranchSchedule() {
            let formData = new FormData(document.getElementById('add-branch-schedule'));
            
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                }
            });
            
            $.post({
                url: '{{route('branch.business-settings.add-schedule')}}',
                data: formData,
                cache: false,
                contentType: false,
                processData: false,
                success: function (data) {
                    if (data.errors) {
                        for (let i = 0; i < data.errors.length; i++) {
                            toastr.error(data.errors[i].message, {
                                CloseButton: true,
                                ProgressBar: true
                            });
                        }
                    } else {
                        $('#branch-schedule-view').html(data.view);
                        $('#branchScheduleModal').modal('hide');
                        toastr.success('{{translate("Schedule added successfully!")}}', {
                            CloseButton: true,
                            ProgressBar: true
                        });
                    }
                }
            });
        }

        function deleteBranchSchedule(route) {
            Swal.fire({
                title: '{{translate("Are you sure?")}}',
                text: '{{translate("You will not be able to revert this!")}}',
                type: 'warning',
                showCancelButton: true,
                cancelButtonColor: 'default',
                confirmButtonColor: '#FC6A57',
                cancelButtonText: '{{translate("No")}}',
                confirmButtonText: '{{translate("Yes")}}',
                reverseButtons: true
            }).then((result) => {
                if (result.value) {
                    $.get({
                        url: route,
                        success: function (data) {
                            $('#branch-schedule-view').html(data.view);
                            toastr.success('{{translate("Schedule removed successfully!")}}', {
                                CloseButton: true,
                                ProgressBar: true
                            });
                        }
                    });
                }
            })
        }
    </script>
@endpush
