@extends('layouts.branch.app')

@section('title', translate('Online Orders'))

@push('css_or_js')
    <link rel="stylesheet" href="{{ asset('public/assets/admin/css/meatco-order-operations.css') }}?v=1.11">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="meatco-express-header">
            <div>
                <h1 class="page-header-title mb-1">{{ translate('Online Orders') }}</h1>
                <p class="text-muted mb-0">{{ translate('Kitchen prep and rider dispatch — live online operations') }}</p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <a href="{{ route('branch.dashboard') }}" class="btn btn-outline-secondary btn-sm">{{ translate('Back to Dashboard') }}</a>
            </div>
        </div>

        @include('partials.order-operations._online-sections', [
            'pendingOrders' => $pendingOrders,
            'packingOrders' => $packingOrders,
            'dispatchedOrders' => $dispatchedOrders,
            'detailRoute' => $detailRoute,
            'showBranch' => false,
        ])
    </div>
@endsection

@push('script_2')
    <script src="{{ asset('public/assets/admin/js/meatco-order-operations.js') }}?v=1.9"></script>
    <script>
        setInterval(function () {
            if (document.hidden) {
                return;
            }
            window.location.reload();
        }, 30000);
    </script>
@endpush
