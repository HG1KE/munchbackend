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
            </div>
            <div class="card-body">
                <p class="mb-3">
                    {{ translate('Promotional credentials live under Business Settings → Web App → Third Party → SMS Config (TextSMS Promotional). This page no longer duplicates that form.') }}
                </p>
                <p class="text-muted mb-4">
                    {{ translate('Current status') }}:
                    <strong>{{ (int)($values['status'] ?? 0) === 1 ? translate('Active') : translate('Inactive') }}</strong>
                </p>
                <a href="{{ route('admin.business-settings.web-app.sms-module') }}" class="btn btn-primary">
                    {{ translate('Open SMS Config') }}
                </a>
            </div>
        </div>
    </div>
@endsection
