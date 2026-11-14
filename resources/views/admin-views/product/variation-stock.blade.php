@extends('layouts.admin.app')

@section('title', translate('Variation stock'))

@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('public/assets/admin/css/munch-product-stock.css') }}?v=1.3">
    <link rel="stylesheet" href="{{ asset('public/assets/admin/css/munch-variation-stock.css') }}?v=1.0">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <img width="20" class="avatar-img" src="{{ asset('public/assets/admin/img/icons/product.png') }}" alt="">
                <span class="page-header-title">{{ translate('Variation stock') }}</span>
            </h2>
            <span class="badge badge-soft-dark rounded-50 fz-14">{{ $products->count() }}</span>
        </div>

        <p class="text-muted mb-3">
            {{ translate('Assign stock items and qty per variation option without opening product edit. Options with no allocations stay untracked for Sold.') }}
        </p>

        <div class="card">
            <div class="card-top px-card pt-4">
                <div class="row justify-content-between align-items-center gy-2">
                    <div class="col-lg-5">
                        <form action="{{ url()->current() }}" method="GET">
                            <div class="input-group">
                                <input type="search" name="search" class="form-control"
                                       placeholder="{{ translate('search_by_product_name') }}"
                                       value="{{ $search }}" autocomplete="off">
                                <div class="input-group-append">
                                    <button type="submit" class="btn btn-primary">{{ translate('Search') }}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="col-lg-7">
                        <div class="d-flex gap-2 justify-content-end flex-wrap">
                            <a href="{{ route('admin.product.list') }}" class="btn btn-outline-secondary">
                                {{ translate('Product List') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="py-4">
                <div class="table-responsive">
                    <table class="table table-borderless table-thead-bordered table-align-middle card-table">
                        <thead class="thead-light">
                        <tr>
                            <th style="width: 4rem;">{{ translate('ID') }}</th>
                            <th>{{ translate('product_name') }}</th>
                            <th>{{ translate('Variations') }}</th>
                            <th class="text-center">{{ translate('Allocated') }}</th>
                            <th class="text-center" style="width: 8rem;">{{ translate('action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($products as $product)
                            <tr>
                                <td>{{ $product['id'] }}</td>
                                <td>
                                    <div class="font-weight-semibold">{{ $product['name'] }}</div>
                                    @if(! $product['status'])
                                        <span class="badge badge-soft-secondary">{{ translate('disabled') }}</span>
                                    @endif
                                </td>
                                <td>
                                    <ul class="munch-var-stock-options mb-0 pl-3">
                                        @foreach($product['options'] as $option)
                                            <li>
                                                <span class="text-muted">{{ $option['group'] }}</span>
                                                · {{ $option['label'] }}
                                                @if($option['allocation_count'] > 0)
                                                    <span class="badge badge-soft-success ml-1">{{ $option['allocation_count'] }}</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td class="text-center">
                                    {{ $product['allocated_option_count'] }} / {{ $product['option_count'] }}
                                </td>
                                <td class="text-center">
                                    <button type="button"
                                            class="btn btn-outline-dark btn-sm"
                                            data-variation-stock="{{ $product['id'] }}">
                                        {{ translate('Allocate') }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-5">
                                    {{ $search !== ''
                                        ? translate('No products with variations match this search.')
                                        : translate('No products with variations yet.') }}
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="munch-var-stock-root"
         data-show-url="{{ route('admin.product.variation-stock-allocations.show', ['id' => '__ID__']) }}"
         data-update-url="{{ route('admin.product.variation-stock-allocations.update', ['id' => '__ID__']) }}"></div>

    <div id="munch-var-stock-backdrop" class="munch-stock-backdrop" hidden></div>
    <div id="munch-var-stock-modal" class="munch-stock-modal munch-var-stock-modal" hidden>
        <div class="munch-stock-modal__panel munch-var-stock-modal__panel" role="dialog" aria-modal="true" aria-labelledby="munch-var-stock-modal-title">
            <div class="munch-stock-modal__header">
                <div>
                    <h2 id="munch-var-stock-modal-title" class="munch-stock-modal__title">{{ translate('Variation stock') }}</h2>
                    <div id="munch-var-stock-modal-meta" class="munch-stock-modal__meta"></div>
                </div>
                <button type="button" class="btn btn-soft-secondary square-btn rounded-circle" data-var-stock-close aria-label="Close">
                    <i class="tio-clear"></i>
                </button>
            </div>
            <p class="munch-stock-modal__hint">
                {{ translate('Allocate stock items deducted when each variation option is sold. Leave blank to keep that option untracked.') }}
            </p>
            <div class="munch-stock-modal__body">
                <div id="munch-var-stock-sections"></div>
                <div id="munch-var-stock-empty" class="munch-stock-empty" hidden>
                    {{ translate('No stock items yet. Add them under Stocks first.') }}
                </div>
            </div>
            <div class="munch-stock-modal__footer">
                <span id="munch-var-stock-status"></span>
                <div class="munch-stock-modal__footer-actions">
                    <button type="button" class="btn btn-outline-secondary" data-var-stock-close>{{ translate('Cancel') }}</button>
                    <button type="button" class="btn btn-primary" id="munch-var-stock-save">{{ translate('Save') }}</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script_2')
    <script src="{{ asset('public/assets/admin/js/munch-variation-stock.js') }}?v=1.0"></script>
@endpush
