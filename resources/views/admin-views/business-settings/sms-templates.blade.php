@extends('layouts.admin.app')

@section('title', translate('Transactional SMS Templates'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <img width="20" class="avatar-img" src="{{asset('public/assets/admin/img/icons/third-party.png')}}" alt="">
                <span class="page-header-title">
                    {{ translate('Transactional SMS') }}
                </span>
            </h2>
        </div>

        @include('admin-views.business-settings.partials._3rdparty-inline-menu')

        <div class="mb-4">
            <h3 class="mb-1">{{ translate('SMS Templates') }}</h3>
            <p class="text-muted mb-0">
                {{ translate('Each template can be enabled independently and sent through TextSMS Transactional or TextSMS Promotional. Gateway credentials are configured under SMS Config.') }}
            </p>
        </div>

        <form action="{{ route('admin.business-settings.web-app.transactional-sms-templates-update') }}" method="POST">
            @csrf
            <div class="row g-3">
                @foreach($templates as $key => $template)
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header">
                                <h4 class="mb-0">{{ translate($template['label']) }}</h4>
                            </div>
                            <div class="card-body">
                                @if(!empty($template['help']))
                                    <p class="text-muted small">{{ translate($template['help']) }}</p>
                                @endif

                                <div class="d-flex align-items-center gap-4 gap-xl-5 mb-30">
                                    <div class="custom-radio">
                                        <input type="radio" id="tpl-{{ $key }}-active"
                                               name="templates[{{ $key }}][status]"
                                               value="1" {{ (int)($template['status'] ?? 0) === 1 ? 'checked' : '' }}>
                                        <label for="tpl-{{ $key }}-active">{{ translate('Active') }}</label>
                                    </div>
                                    <div class="custom-radio">
                                        <input type="radio" id="tpl-{{ $key }}-inactive"
                                               name="templates[{{ $key }}][status]"
                                               value="0" {{ (int)($template['status'] ?? 0) !== 1 ? 'checked' : '' }}>
                                        <label for="tpl-{{ $key }}-inactive">{{ translate('Inactive') }}</label>
                                    </div>
                                </div>

                                <div class="form-group mb-3">
                                    <label class="form-label">{{ translate('message_template') }}</label>
                                    <textarea class="form-control" rows="4" name="templates[{{ $key }}][message]">{{ $template['message'] ?? '' }}</textarea>
                                    @if(!empty($template['placeholders']))
                                        <small class="text-muted d-block mt-1">
                                            {{ translate('Available variables:') }}
                                            {{ implode(', ', $template['placeholders']) }}
                                        </small>
                                    @endif
                                </div>

                                <div class="form-group mb-0">
                                    <label class="form-label d-block">{{ translate('Gateway') }}</label>
                                    <div class="custom-radio mb-2">
                                        <input type="radio" id="tpl-{{ $key }}-gw-tx"
                                               name="templates[{{ $key }}][gateway]"
                                               value="transactional" {{ ($template['gateway'] ?? 'transactional') === 'transactional' ? 'checked' : '' }}>
                                        <label for="tpl-{{ $key }}-gw-tx">{{ translate('TextSMS Transactional') }}</label>
                                    </div>
                                    <div class="custom-radio">
                                        <input type="radio" id="tpl-{{ $key }}-gw-promo"
                                               name="templates[{{ $key }}][gateway]"
                                               value="promotional" {{ ($template['gateway'] ?? '') === 'promotional' ? 'checked' : '' }}>
                                        <label for="tpl-{{ $key }}-gw-promo">{{ translate('TextSMS Promotional') }}</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="d-flex justify-content-end mt-4 mb-5">
                <button type="submit" class="btn btn-primary demo_check">{{ translate('Update') }}</button>
            </div>
        </form>
    </div>
@endsection
