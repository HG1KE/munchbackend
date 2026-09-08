@extends('layouts.admin.app')

@section('title', translate('Promotional SMS gateway'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <span class="page-header-title">{{ translate('Marketing') }} — {{ translate('Promotional SMS gateway') }}</span>
            </h2>
        </div>

        @include('admin-views.marketing.partials.subnav')

        <div class="card">
            <div class="card-header">
                <h4 class="mb-0">{{ translate('Global marketing TextSMS credentials') }}</h4>
                <small class="text-muted">{{ translate('Used by abandoned checkout and future promotional campaigns') }}</small>
            </div>
            <div class="card-body">
                <form action="{{ route('admin.marketing.promotional-sms-gateway.update') }}" method="POST">
                    @csrf
                    <input type="hidden" name="gateway" value="textsms_ke_promotional">

                    <div class="d-flex align-items-center gap-4 mb-4">
                        <div class="custom-radio">
                            <input type="radio" id="promo-active" name="status" value="1" {{ (int)($values['status'] ?? 0) === 1 ? 'checked' : '' }}>
                            <label for="promo-active">{{ translate('Active') }}</label>
                        </div>
                        <div class="custom-radio">
                            <input type="radio" id="promo-inactive" name="status" value="0" {{ (int)($values['status'] ?? 0) !== 1 ? 'checked' : '' }}>
                            <label for="promo-inactive">{{ translate('Inactive') }}</label>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">{{ translate('api_key') }}</label>
                        <input type="text" class="form-control" name="api_key" value="{{ env('APP_MODE')=='demo' ? '' : ($values['api_key'] ?? '') }}">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">{{ translate('partner_id') }}</label>
                        <input type="text" class="form-control" name="partner_id" value="{{ env('APP_MODE')=='demo' ? '' : ($values['partner_id'] ?? '') }}">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">{{ translate('sender_id') }}</label>
                        <input type="text" class="form-control" name="sender_id" value="{{ env('APP_MODE')=='demo' ? '' : ($values['sender_id'] ?? '') }}">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">{{ translate('http_timeout_seconds') }}</label>
                        <input type="number" min="5" max="120" class="form-control" name="http_timeout_seconds" value="{{ $values['http_timeout_seconds'] ?? '30' }}">
                    </div>

                    <button type="submit" class="btn btn-primary demo_check">{{ translate('Update') }}</button>
                </form>
            </div>
        </div>
    </div>
@endsection
