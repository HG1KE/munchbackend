@extends('layouts.admin.app')

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
        @include('admin-views.business-settings.partials._business-setup-inline-menu')
        @include('admin-views.business-settings.partials._receipt-template-editor', [
            'scopeSelect' => true,
            'branches' => $branches,
            'payload' => $payload,
            'urls' => [
                'index' => route('admin.business-settings.restaurant.receipt-templates'),
                'payload' => route('admin.business-settings.restaurant.receipt-templates.payload'),
                'save' => route('admin.business-settings.restaurant.receipt-templates.save'),
                'reset' => route('admin.business-settings.restaurant.receipt-templates.reset'),
                'logo' => route('admin.business-settings.restaurant.receipt-templates.logo'),
                'qr' => route('admin.business-settings.restaurant.receipt-templates.qr'),
            ],
        ])
    </div>
@endsection

@push('script_2')
    <script src="{{ asset('public/assets/admin/js/munch-receipt-ticket.js') }}?v=1.7"></script>
    <script>
        window.MUNCH_RECEIPT_EDITOR = {
            payload: @json($payload),
            csrf: @json(csrf_token()),
            currency: @json(\App\CentralLogics\Helpers::currency_symbol()),
            urls: {
                index: @json(route('admin.business-settings.restaurant.receipt-templates')),
                payload: @json(route('admin.business-settings.restaurant.receipt-templates.payload')),
                save: @json(route('admin.business-settings.restaurant.receipt-templates.save')),
                reset: @json(route('admin.business-settings.restaurant.receipt-templates.reset')),
                logo: @json(route('admin.business-settings.restaurant.receipt-templates.logo')),
                qr: @json(route('admin.business-settings.restaurant.receipt-templates.qr')),
            }
        };
    </script>
    <script src="{{ asset('public/assets/admin/js/munch-receipt-templates.js') }}?v=1.3"></script>
@endpush
