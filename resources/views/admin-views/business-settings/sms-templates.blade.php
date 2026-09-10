@extends('layouts.admin.app')

@section('title', translate('Transactional SMS'))

@push('css_or_js')
    <link rel="stylesheet" href="{{ asset('public/assets/admin/css/transactional-sms.css') }}?v=1.0">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="tio-message nav-icon" style="font-size: 1.5rem;"></i>
                <span class="page-header-title">{{ translate('Transactional SMS') }}</span>
            </h2>
        </div>

        @include('admin-views.business-settings.partials._3rdparty-inline-menu')

        <ul class="nav nav-pills mb-3 transactional-sms-tabs">
            <li class="nav-item">
                <a class="nav-link" href="{{ route('admin.business-settings.web-app.sms-module') }}">
                    {{ translate('SMS Provider Configuration') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="{{ route('admin.business-settings.web-app.transactional-sms-templates') }}">
                    {{ translate('SMS Templates') }}
                </a>
            </li>
        </ul>

        <form action="{{ route('admin.business-settings.web-app.transactional-sms-templates-update') }}" method="post">
            @csrf
            <div class="row">
                <div class="col-lg-8">
                    <div class="card mb-3">
                        <div class="card-header">
                            <h5 class="mb-0">{{ translate('POS Cancellation Notification Number') }}</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted fz-12 mb-2">{{ translate('Single phone number that receives POS Order Cancelled SMS. Not sent to customers, riders, or cashiers. Required only when that template is enabled.') }}</p>
                            <label class="input-label" for="pos_cancellation_notification_phone">{{ translate('Phone') }}</label>
                            <input type="text"
                                   id="pos_cancellation_notification_phone"
                                   name="pos_cancellation_notification_phone"
                                   class="form-control"
                                   value="{{ old('pos_cancellation_notification_phone', $posCancellationPhone ?? '') }}"
                                   placeholder="0712345678">
                        </div>
                    </div>
                    @foreach($templates as $type => $row)
                        <div class="card mb-3 js-tx-sms-template-card" data-template-type="{{ $type }}">
                            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <h5 class="mb-0">{{ translate($row['label']) }}</h5>
                                <label class="switcher mb-0">
                                    <input type="hidden" name="templates[{{ $type }}][status]" value="0">
                                    <input type="checkbox" class="switcher_input js-tx-sms-template-enabled"
                                           name="templates[{{ $type }}][status]" value="1"
                                           {{ (int) ($row['status'] ?? 0) === 1 ? 'checked' : '' }}>
                                    <span class="switcher_control"></span>
                                </label>
                            </div>
                            <div class="card-body">
                                @if(!empty($row['help']))
                                    <p class="text-muted fz-12 mb-2">{{ translate($row['help']) }}</p>
                                @endif
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="input-label mb-0" for="tpl-{{ $type }}">{{ translate('SMS message') }}</label>
                                    <span class="tx-sms-char-counter js-tx-sms-char-counter">0</span>
                                </div>
                                <textarea id="tpl-{{ $type }}"
                                          name="templates[{{ $type }}][message]"
                                          class="form-control js-tx-sms-template-body"
                                          rows="4">{{ $row['message'] ?? '' }}</textarea>
                                <div class="form-group mb-0 mt-3">
                                    <label class="input-label" for="tpl-gateway-{{ $type }}">{{ translate('Gateway') }}</label>
                                    <select id="tpl-gateway-{{ $type }}"
                                            name="templates[{{ $type }}][gateway]"
                                            class="form-control">
                                        <option value="transactional" {{ ($row['gateway'] ?? 'transactional') === 'transactional' ? 'selected' : '' }}>
                                            {{ translate('TextSMS Transactional') }}
                                        </option>
                                        <option value="promotional" {{ ($row['gateway'] ?? '') === 'promotional' ? 'selected' : '' }}>
                                            {{ translate('TextSMS Promotional') }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <button type="submit" class="btn btn-primary mb-4">{{ translate('Save Configuration') }}</button>
                </div>

                <div class="col-lg-4">
                    <div class="card mb-3 position-sticky" style="top: 1rem;">
                        <div class="card-header">
                            <h5 class="mb-0">{{ translate('Supported Variables') }}</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted fz-13">{{ translate('Click to insert at cursor position') }}</p>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                @foreach($variables as $token)
                                    <button type="button" class="btn btn-sm btn-soft-secondary js-tx-sms-var-chip"
                                            data-insert="{{ $token }}"
                                            data-var="{{ trim($token, '{}#') }}">
                                        {{ $token }}
                                    </button>
                                @endforeach
                            </div>
                            <h6 class="mb-2">{{ translate('SMS Preview') }}</h6>
                            <div class="tx-sms-preview-card js-tx-sms-preview-card">
                                <div class="tx-sms-preview-card__body js-tx-sms-preview-text">
                                    {{ translate('Select a template to preview') }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('script_2')
    <script src="{{ asset('public/assets/admin/js/transactional-sms.js') }}?v=1.1"></script>
@endpush
