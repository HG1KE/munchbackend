@php
    $values = $textSmsGateways[$gatewayKey] ?? [];
    $isDemo = config('app.mode') == 'demo';
@endphp
<div class="col-md-6 mb-30 sms-gatway-cards mb-5">
    <div class="card">
        <div class="card-header">
            <h4 class="page-title">{{ translate($titleKey) }}</h4>
        </div>
        <div class="card-body p-30">
            <p class="text-muted mb-4">{{ translate($helpKey) }}</p>
            <form action="{{ route('admin.business-settings.web-app.sms-module-update', [$gatewayKey]) }}" method="POST"
                  id="{{ $gatewayKey }}-form">
                @csrf
                <div class="discount-type">
                    <div class="d-flex align-items-center gap-4 gap-xl-5 mb-30">
                        <div class="custom-radio">
                            <input type="radio" id="{{ $gatewayKey }}-active" name="status"
                                   value="1" {{ (int)($values['status'] ?? 0) === 1 ? 'checked' : '' }}>
                            <label for="{{ $gatewayKey }}-active">{{ translate('Active') }}</label>
                        </div>
                        <div class="custom-radio">
                            <input type="radio" id="{{ $gatewayKey }}-inactive" name="status"
                                   value="0" {{ (int)($values['status'] ?? 0) !== 1 ? 'checked' : '' }}>
                            <label for="{{ $gatewayKey }}-inactive">{{ translate('Inactive') }}</label>
                        </div>
                    </div>

                    <input name="gateway" value="{{ $gatewayKey }}" class="d-none">
                    <input name="mode" value="live" class="d-none">

                    <div class="form-floating mb-30 mt-30">
                        <label class="form-label">{{ translate('api_key') }} *</label>
                        <input type="text" class="form-control mb-3" name="api_key"
                               placeholder="{{ translate('api_key') }} *"
                               value="{{ $isDemo ? '' : ($values['api_key'] ?? '') }}">
                    </div>
                    <div class="form-floating mb-30 mt-30">
                        <label class="form-label">{{ translate('partner_id') }} *</label>
                        <input type="text" class="form-control mb-3" name="partner_id"
                               placeholder="{{ translate('partner_id') }} *"
                               value="{{ $isDemo ? '' : ($values['partner_id'] ?? '') }}">
                    </div>
                    <div class="form-floating mb-30 mt-30">
                        <label class="form-label">{{ translate('sender_id') }} *</label>
                        <input type="text" class="form-control mb-3" name="sender_id"
                               placeholder="{{ translate('sender_id') }} *"
                               value="{{ $isDemo ? '' : ($values['sender_id'] ?? '') }}">
                    </div>
                    <div class="form-floating mb-30 mt-30">
                        <label class="form-label">{{ translate('endpoint') }}</label>
                        <input type="text" class="form-control mb-3" name="endpoint"
                               placeholder="{{ translate('endpoint') }}"
                               value="{{ $values['endpoint'] ?? '' }}">
                    </div>
                    <div class="form-floating mb-30 mt-30">
                        <label class="form-label">{{ translate('http_timeout_seconds') }}</label>
                        <input type="number" min="5" max="120" class="form-control mb-3" name="http_timeout_seconds"
                               value="{{ $values['http_timeout_seconds'] ?? '30' }}">
                    </div>
                </div>
                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary demo_check">
                        {{ translate('Update') }}
                    </button>
                </div>
            </form>

            <hr class="my-4">
            <h5 class="mb-3">{{ translate('Test SMS') }}</h5>
            <form action="{{ route('admin.business-settings.web-app.sms-module-test', [$gatewayKey]) }}" method="POST"
                  class="d-flex flex-wrap gap-2 align-items-end">
                @csrf
                <div class="form-group mb-0 flex-grow-1" style="min-width: 220px;">
                    <label class="form-label">{{ translate('Test phone number') }}</label>
                    <input type="text" class="form-control" name="test_phone"
                           value="{{ old('test_phone') }}" placeholder="+2547XXXXXXXX">
                </div>
                <button type="submit" class="btn btn-outline-primary demo_check">{{ translate('Send test') }}</button>
            </form>
        </div>
    </div>
</div>
