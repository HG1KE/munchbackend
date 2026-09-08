@extends('layouts.admin.app')

@section('title', translate('Reorder Reminders'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <span class="page-header-title">{{ translate('Marketing') }} — {{ translate('Reorder Reminders') }}</span>
            </h2>
        </div>

        @include('admin-views.marketing.partials.subnav')

        <div class="card">
            <div class="card-header">
                <h4 class="mb-0">{{ translate('Campaign settings') }}</h4>
                <small class="text-muted">{{ translate('Credentials are configured under Promotional SMS gateway') }}</small>
            </div>
            <div class="card-body">
                <form action="{{ route('admin.marketing.reorder-reminders.update') }}" method="POST">
                    @csrf
                    <input type="hidden" name="gateway" value="textsms_ke_reorder_reminder">

                    <div class="d-flex align-items-center gap-4 mb-4">
                        <div class="custom-radio">
                            <input type="radio" id="rr-active" name="status" value="1" {{ (int)($values['status'] ?? 0) === 1 ? 'checked' : '' }}>
                            <label for="rr-active">{{ translate('Active') }}</label>
                        </div>
                        <div class="custom-radio">
                            <input type="radio" id="rr-inactive" name="status" value="0" {{ (int)($values['status'] ?? 0) !== 1 ? 'checked' : '' }}>
                            <label for="rr-inactive">{{ translate('Inactive') }}</label>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">{{ translate('message_template') }}</label>
                        <textarea class="form-control" rows="4" name="message_template">{{ $values['message_template'] ?? '' }}</textarea>
                        <small class="text-muted d-block mt-1">
                            {{ translate('Placeholders') }}:
                            {customer_name}, {branch_name}, {currency}, {last_order_total}, {recovery_url}, {favorite_item}, {last_order_date}
                        </small>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="form-label">{{ translate('Delay Days') }}</label>
                            <input type="text" class="form-control" name="delay_days" value="{{ $values['delay_days'] ?? '14' }}">
                            <small class="text-muted">{{ translate('Days since last delivered order') }}</small>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="form-label">{{ translate('Minimum Completed Orders') }}</label>
                            <input type="text" class="form-control" name="minimum_completed_orders" value="{{ $values['minimum_completed_orders'] ?? '2' }}">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="form-label">{{ translate('max_attempts') }}</label>
                            <input type="text" class="form-control" name="max_attempts" value="{{ $values['max_attempts'] ?? '1' }}">
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="form-label">{{ translate('Cooldown Days') }}</label>
                            <input type="text" class="form-control" name="cooldown_days" value="{{ $values['cooldown_days'] ?? '30' }}">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="form-label">{{ translate('quiet_hours_start') }}</label>
                            <input type="text" class="form-control" name="quiet_hours_start" value="{{ $values['quiet_hours_start'] ?? '21:00' }}">
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="form-label">{{ translate('quiet_hours_end') }}</label>
                            <input type="text" class="form-control" name="quiet_hours_end" value="{{ $values['quiet_hours_end'] ?? '08:00' }}">
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">{{ translate('recovery_url') }}</label>
                        <input type="text" class="form-control" name="recovery_url" value="{{ $values['recovery_url'] ?? '' }}">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">{{ translate('Branch IDs') }} <span class="text-muted">({{ translate('optional') }})</span></label>
                        <input type="text" class="form-control" name="branch_ids" value="{{ $values['branch_ids'] ?? '' }}" placeholder="1, 2, 3">
                        <small class="text-muted">{{ translate('Comma-separated branch IDs. Leave empty for all branches.') }}</small>
                    </div>

                    <button type="submit" class="btn btn-primary demo_check">{{ translate('Update') }}</button>
                </form>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <h4 class="mb-0">{{ translate('Send test SMS') }}</h4>
            </div>
            <div class="card-body">
                <form action="{{ route('admin.marketing.reorder-reminders.send-test') }}" method="POST" class="d-flex flex-wrap gap-2 align-items-end">
                    @csrf
                    <div class="form-group mb-0 flex-grow-1" style="min-width: 220px;">
                        <label class="form-label">{{ translate('Test phone number') }}</label>
                        <input type="text" class="form-control" name="test_phone" value="{{ old('test_phone', $values['test_phone'] ?? '') }}" placeholder="+2547XXXXXXXX">
                    </div>
                    <button type="submit" class="btn btn-outline-primary demo_check">{{ translate('Send test') }}</button>
                </form>
            </div>
        </div>
    </div>
@endsection
