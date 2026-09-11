@extends('layouts.branch.app')

@section('title', translate('Receipt Templates'))

@push('css_or_js')
    <link rel="stylesheet" href="{{ asset('public/assets/admin/css/munch-receipt-templates.css') }}?v=1.1">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="tio-receipt"></i>
                <span class="page-header-title">{{ translate('Receipt Templates') }}</span>
            </h2>
        </div>
        <p class="text-muted">{{ translate('Use the company template, or create a custom layout for this branch only.') }}</p>
        @include('admin-views.business-settings.partials._receipt-template-editor', [
            'scopeSelect' => false,
            'branches' => collect(),
            'payload' => $payload,
        ])
    </div>
@endsection

@push('script_2')
    <script src="{{ asset('public/assets/admin/js/munch-receipt-ticket.js') }}?v=1.9"></script>
    <script>
        window.MUNCH_RECEIPT_EDITOR = {
            payload: @json($payload),
            csrf: @json(csrf_token()),
            currency: @json(\App\CentralLogics\Helpers::currency_symbol()),
            urls: {
                index: @json(route('branch.business-settings.receipt-templates')),
                payload: @json(route('branch.business-settings.receipt-templates')),
                save: @json(route('branch.business-settings.receipt-templates.save')),
                reset: @json(route('branch.business-settings.receipt-templates.reset')),
                logo: @json(route('branch.business-settings.receipt-templates.logo')),
                qr: @json(route('branch.business-settings.receipt-templates.qr')),
            }
        };
    </script>
    <script src="{{ asset('public/assets/admin/js/munch-receipt-templates.js') }}?v=1.4"></script>
@endpush
