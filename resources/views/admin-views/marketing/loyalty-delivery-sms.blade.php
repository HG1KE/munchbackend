@extends('layouts.admin.app')

@section('title', translate('Loyalty Delivery SMS'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <span class="page-header-title">{{ translate('Marketing') }} — {{ translate('Loyalty Delivery SMS') }}</span>
            </h2>
        </div>

        @include('admin-views.marketing.partials.subnav')

        <div class="card">
            <div class="card-header">
                <h4 class="mb-0">{{ translate('Campaign settings') }}</h4>
                <small class="text-muted">{{ translate('Sent when loyalty points are credited after a delivered order. This campaign must be Active, and Customer order confirmation SMS (transactional gateway) must also be active with credentials. Test SMS can work even when this campaign is inactive.') }}</small>
            </div>
            <div class="card-body">
                <form action="{{ route('admin.marketing.loyalty-delivery-sms.update') }}" method="POST">
                    @csrf
                    <input type="hidden" name="gateway" value="textsms_ke_loyalty_delivery">

                    <div class="d-flex align-items-center gap-4 mb-4">
                        <div class="custom-radio">
                            <input type="radio" id="ld-active" name="status" value="1" {{ (int)($values['status'] ?? 0) === 1 ? 'checked' : '' }}>
                            <label for="ld-active">{{ translate('Active') }}</label>
                        </div>
                        <div class="custom-radio">
                            <input type="radio" id="ld-inactive" name="status" value="0" {{ (int)($values['status'] ?? 0) !== 1 ? 'checked' : '' }}>
                            <label for="ld-inactive">{{ translate('Inactive') }}</label>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">{{ translate('message_template') }}</label>
                        <textarea class="form-control" rows="5" name="message_template">{{ $values['message_template'] ?? '' }}</textarea>
                        <small class="text-muted d-block mt-1">
                            {{ translate('Placeholders') }}:
                            {customer_name}, {earned_points}, {current_points}, {order_id}
                        </small>
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
                <form action="{{ route('admin.marketing.loyalty-delivery-sms.send-test') }}" method="POST" class="d-flex flex-wrap gap-2 align-items-end">
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

