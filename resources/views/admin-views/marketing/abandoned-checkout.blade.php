@extends('layouts.admin.app')

@section('title', translate('Abandoned Checkout'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <span class="page-header-title">{{ translate('Marketing') }} — {{ translate('Abandoned Checkout') }}</span>
            </h2>
        </div>

        @include('admin-views.marketing.partials.subnav')

        <div class="card">
            <div class="card-header">
                <h4 class="mb-0">{{ translate('Campaign settings') }}</h4>
                <small class="text-muted">{{ translate('Credentials are configured under Promotional SMS gateway') }}</small>
            </div>
            <div class="card-body">
                <form action="{{ route('admin.marketing.abandoned-checkout.update') }}" method="POST">
                    @csrf
                    <input type="hidden" name="gateway" value="textsms_ke_abandoned_cart">

                    <div class="d-flex align-items-center gap-4 mb-4">
                        <div class="custom-radio">
                            <input type="radio" id="ab-active" name="status" value="1" {{ (int)($values['status'] ?? 0) === 1 ? 'checked' : '' }}>
                            <label for="ab-active">{{ translate('Active') }}</label>
                        </div>
                        <div class="custom-radio">
                            <input type="radio" id="ab-inactive" name="status" value="0" {{ (int)($values['status'] ?? 0) !== 1 ? 'checked' : '' }}>
                            <label for="ab-inactive">{{ translate('Inactive') }}</label>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">{{ translate('message_template') }}</label>
                        <textarea class="form-control" rows="4" name="message_template">{{ $values['message_template'] ?? '' }}</textarea>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">{{ translate('delay_minutes') }}</label>
                        <input type="text" class="form-control" name="delay_minutes" value="{{ $values['delay_minutes'] ?? '30' }}">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">{{ translate('max_attempts') }}</label>
                        <input type="text" class="form-control" name="max_attempts" value="{{ $values['max_attempts'] ?? '1' }}">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">{{ translate('cooldown_hours') }}</label>
                        <input type="text" class="form-control" name="cooldown_hours" value="{{ $values['cooldown_hours'] ?? '24' }}">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">{{ translate('quiet_hours_start') }}</label>
                        <input type="text" class="form-control" name="quiet_hours_start" value="{{ $values['quiet_hours_start'] ?? '21:00' }}">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">{{ translate('quiet_hours_end') }}</label>
                        <input type="text" class="form-control" name="quiet_hours_end" value="{{ $values['quiet_hours_end'] ?? '08:00' }}">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">{{ translate('recovery_url') }}</label>
                        <input type="text" class="form-control" name="recovery_url" value="{{ $values['recovery_url'] ?? '' }}">
                    </div>

                    <button type="submit" class="btn btn-primary demo_check">{{ translate('Update') }}</button>
                </form>
            </div>
        </div>
    </div>
@endsection
