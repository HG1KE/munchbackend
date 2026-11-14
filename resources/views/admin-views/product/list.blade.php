@extends('layouts.admin.app')

@section('title', translate('Product List'))

@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('public/assets/admin/css/munch-product-pricing.css') }}?v=1.7">
    <link rel="stylesheet" href="{{ asset('public/assets/admin/css/munch-product-stock.css') }}?v=1.2">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <img width="20" class="avatar-img" src="{{asset('public/assets/admin/img/icons/product.png')}}" alt="">
                <span class="page-header-title">
                    {{translate('Product_List')}}
                </span>
            </h2>
            <span class="badge badge-soft-dark rounded-50 fz-14">{{ $products->total() }}</span>
        </div>


        <div class="row g-2">
            <div class="col-12">
                <div class="card">
                    <div class="card-top px-card pt-4">
                        <div class="row justify-content-between align-items-center gy-2">
                            <div class="col-lg-4">
                                <form action="{{url()->current()}}" method="GET">
                                    <div class="input-group">
                                        <input id="datatableSearch_" type="search" name="search" class="form-control" placeholder="{{translate('search_by_product_name')}}" aria-label="Search" value="{{$search}}" required="" autocomplete="off">
                                        <div class="input-group-append">
                                            <button type="submit" class="btn btn-primary">{{translate('Search')}}</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            <div class="col-lg-8">
                                <div class="d-flex gap-3 justify-content-end text-nowrap flex-wrap">
                                    <button type="button" class="btn btn-outline-secondary" data-toggle="modal" data-target="#unallocatedProductsModal" id="munch-unallocated-open">
                                        {{ translate('Unallocated products') }}
                                    </button>
                                    <button type="button" class="btn btn-outline-dark" data-bulk-pricing>
                                        {{ translate('Bulk Price Edit') }}
                                    </button>
                                    <div>
                                        <button type="button" class="btn btn-outline-primary" data-toggle="dropdown" aria-expanded="false">
                                            <i class="tio-download-to"></i>
                                            Export
                                            <i class="tio-chevron-down"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-right">
                                            <li>
                                                <a type="submit" class="dropdown-item d-flex align-items-center gap-2" href="{{route('admin.product.excel-import', ['search' => $search])}}">
                                                    <img width="14" src="{{asset('public/assets/admin/img/icons/excel.png')}}" alt="">
                                                    {{translate('Excel')}}
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                    <a href="{{route('admin.product.add-new')}}" class="btn btn-primary">
                                        <i class="tio-add"></i> {{translate('add_New_Product')}}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="py-4">
                        <div class="table-responsive datatable-custom">
                            <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                                <thead class="thead-light">
                                <tr>
                                    <th>{{translate('SL')}}</th>
                                    <th>{{translate('product_name')}}</th>
                                    <th>{{translate('selling_price')}}</th>
                                    <th class="text-center">{{translate('total_sale')}}</th>
                                    <th>{{translate('stock')}}</th>
                                    <th>{{translate('status')}}</th>
                                    <th>{{translate('recommended')}}</th>
                                    <th class="text-center">{{translate('action')}}</th>
                                </tr>
                                </thead>

                                <tbody id="set-rows">
                                @foreach($products as $key=>$product)
                                    <tr>
                                        <td>{{$products->firstitem()+$key}}</td>
                                        <td>
                                            <div class="media align-items-center gap-3">
                                                <div class="avatar">
                                                    <img src="{{$product['imageFullPath']}}" class="rounded img-fit" alt="{{ translate('product') }}">
                                                </div>

                                                <div class="media-body">
                                                    <a class="text-dark" href="{{route('admin.product.view',[$product['id']])}}">
                                                        {{ Str::limit($product['name'], 30) }}
                                                    </a>
                                                </div>
                                            </div>
                                        </td>
                                        <td>{{ \App\CentralLogics\Helpers::set_symbol($product['price']) }}</td>
                                        <td class="text-center">{{\App\Model\OrderDetail::whereHas('order', function ($q){
                                                    $q->where('order_status', 'delivered');
                                                })->where('product_id', $product->id)->sum('quantity')}}
                                        </td>
                                        <td>
                                            <div><span class="">{{ translate('Stock Type') }} : {{ ucfirst($product->main_branch_product?->stock_type) }}</span></div>
                                            @if(isset($product->main_branch_product) && $product->main_branch_product->stock_type != 'unlimited')
                                                <div><span class="">{{ translate('Stock') }} : {{ $product->main_branch_product->stock - $product->main_branch_product->sold_quantity }}</span></div>
                                            @endif
                                        </td>
                                        <td>
                                            <div>
                                                <label class="switcher">
                                                    <input id="{{$product['id']}}" class="switcher_input status-change" type="checkbox" {{$product['status']==1? 'checked' : ''}}
                                                        data-url="{{route('admin.product.status',[$product['id'],0])}}">
                                                    <span class="switcher_control"></span>
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <label class="switcher">
                                                    <input id="recommended-{{$product['id']}}" class="switcher_input recommended-status-change" type="checkbox" {{$product['is_recommended']==1? 'checked' : ''}}
                                                    data-url="{{route('admin.product.recommended',[$product['id'],0])}}">
                                                    <span class="switcher_control"></span>
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex justify-content-center gap-2">
                                                <button type="button" class="btn btn-outline-primary btn-sm" data-pricing="{{ $product['id'] }}">
                                                    {{ translate('Pricing') }}
                                                </button>
                                                <button type="button" class="btn btn-outline-dark btn-sm" data-stock="{{ $product['id'] }}">
                                                    {{ translate('Stock') }}
                                                </button>
                                                <a class="btn btn-outline-info btn-sm edit square-btn"
                                                href="{{route('admin.product.edit',[$product['id']])}}"><i class="tio-edit"></i></a>
                                                <button type="button" class="btn btn-outline-danger btn-sm delete square-btn form-alert"
                                                        data-id="product-{{$product['id']}}"
                                                        data-message="{{translate('Want to delete this item ?')}}">
                                                    <i class="tio-delete"></i>
                                                </button>
                                            </div>
                                            <form action="{{route('admin.product.delete',[$product['id']])}}"
                                                method="post" id="product-{{$product['id']}}">
                                                @csrf @method('delete')
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="table-responsive mt-4 px-3">
                            <div class="d-flex justify-content-lg-end">
                                {!! $products->links() !!}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="munch-pricing-root"
         data-show-url="{{ route('admin.product.pricing.show', ['id' => '__ID__']) }}"
         data-update-url="{{ route('admin.product.pricing.update', ['id' => '__ID__']) }}"
         data-meta-url="{{ route('admin.product.pricing.meta') }}"
         data-products-url="{{ route('admin.product.pricing.products') }}"
         data-current-price-url="{{ route('admin.product.pricing.bulk-price.current') }}"
         data-preview-price-url="{{ route('admin.product.pricing.bulk-price.preview') }}"
         data-apply-price-url="{{ route('admin.product.pricing.bulk-price.apply') }}"
         data-preview-avail-url="{{ route('admin.product.pricing.bulk-availability.preview') }}"
         data-apply-avail-url="{{ route('admin.product.pricing.bulk-availability.apply') }}"
         data-copy-preview-url="{{ route('admin.product.pricing.copy.preview') }}"
         data-copy-apply-url="{{ route('admin.product.pricing.copy.apply') }}"
         data-currency="{{ \App\CentralLogics\Helpers::currency_symbol() }}"></div>

    <div id="munch-stock-root"
         data-show-url="{{ route('admin.product.stock-allocations.show', ['id' => '__ID__']) }}"
         data-update-url="{{ route('admin.product.stock-allocations.update', ['id' => '__ID__']) }}"
         data-unallocated-url="{{ route('admin.product.stock-allocations.unallocated') }}"></div>

    <div class="modal fade" id="unallocatedProductsModal" tabindex="-1" role="dialog" aria-labelledby="unallocatedProductsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0" id="unallocatedProductsModalLabel">{{ translate('Unallocated products') }}</h5>
                        <div class="text-muted small mt-1">{{ translate('Products with no stock item links. Unallocated sales stay untracked for Sold.') }}</div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="munch-unallocated-status" class="text-muted mb-2">{{ translate('Loading…') }}</div>
                    <div class="table-responsive">
                        <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table mb-0">
                            <thead class="thead-light">
                            <tr>
                                <th style="width: 5rem;">{{ translate('ID') }}</th>
                                <th>{{ translate('product_name') }}</th>
                                <th class="text-center" style="width: 6rem;">{{ translate('action') }}</th>
                            </tr>
                            </thead>
                            <tbody id="munch-unallocated-rows">
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ translate('Close') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div id="munch-stock-backdrop" class="munch-stock-backdrop" hidden></div>
    <div id="munch-stock-modal" class="munch-stock-modal" hidden>
        <div class="munch-stock-modal__panel" role="dialog" aria-modal="true" aria-labelledby="munch-stock-modal-title">
            <div class="munch-stock-modal__header">
                <div>
                    <h2 id="munch-stock-modal-title" class="munch-stock-modal__title">{{ translate('Stock') }}</h2>
                    <div id="munch-stock-modal-meta" class="munch-stock-modal__meta"></div>
                </div>
                <button type="button" class="btn btn-soft-secondary square-btn rounded-circle" data-stock-close aria-label="Close">
                    <i class="tio-clear"></i>
                </button>
            </div>
            <p class="munch-stock-modal__hint">
                {{ translate('Allocate stock items deducted when this product is sold (e.g. Double Burger = 2 patties + 2 buns).') }}
            </p>
            <div class="munch-stock-modal__body">
                <div id="munch-stock-rows"></div>
                <div id="munch-stock-empty" class="munch-stock-empty" hidden>
                    {{ translate('No stock items yet. Add them under Stocks first.') }}
                </div>
            </div>
            <div class="munch-stock-modal__footer">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="munch-stock-add-row">
                        <i class="tio-add"></i> {{ translate('Add item') }}
                    </button>
                    <span id="munch-stock-status"></span>
                </div>
                <div class="munch-stock-modal__footer-actions">
                    <button type="button" class="btn btn-outline-secondary" data-stock-close>{{ translate('Cancel') }}</button>
                    <button type="button" class="btn btn-primary" id="munch-stock-save">{{ translate('Save') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div id="munch-pricing-backdrop" class="munch-pricing-backdrop" hidden></div>

    <aside id="munch-pricing-drawer" class="munch-pricing-drawer" hidden>
        <div class="munch-pricing-drawer__header">
            <div class="munch-pricing-drawer__identity">
                <img id="munch-pricing-drawer-image" alt="">
                <div>
                    <h2 id="munch-pricing-drawer-title" class="munch-pricing-drawer__title">{{ translate('Pricing') }}</h2>
                    <div id="munch-pricing-drawer-meta" class="munch-pricing-drawer__meta"></div>
                    <div class="munch-pricing-default">
                        <label for="munch-pricing-default-price">{{ translate('Default Selling Price') }}</label>
                        <input id="munch-pricing-default-price" type="number" min="0" step="0.01" class="form-control" readonly>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-soft-secondary square-btn rounded-circle" data-pricing-close aria-label="Close">
                <i class="tio-clear"></i>
            </button>
        </div>
        <div class="munch-pricing-drawer__toolbar">
            <input id="munch-pricing-branch-search" class="form-control" type="search" placeholder="{{ translate('Search branches') }}">
            <div class="position-relative">
                <input id="munch-pricing-product-search" class="form-control" type="search" placeholder="{{ translate('Search products') }}">
                <div id="munch-pricing-product-results" class="dropdown-menu mt-1" hidden></div>
            </div>
            <select id="munch-pricing-category-filter" class="custom-select">
                <option value="">{{ translate('All categories') }}</option>
            </select>
            <select id="munch-pricing-channel-filter" class="custom-select">
                <option value="">{{ translate('All channels') }}</option>
                <option value="pos">POS</option>
                <option value="uber">Uber</option>
                <option value="glovo">Glovo</option>
                <option value="bolt_food">Bolt Food</option>
            </select>
        </div>
        <div id="munch-pricing-drawer-body" class="munch-pricing-drawer__body"></div>
        <div class="munch-pricing-drawer__footer">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <button type="button" class="btn btn-outline-dark" data-copy-from-branch>{{ translate('Copy From Branch') }}</button>
                <span id="munch-pricing-dirty-count" class="text-muted">{{ translate('No unsaved changes') }}</span>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary" data-pricing-close>{{ translate('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="munch-pricing-save" disabled>{{ translate('Save changes') }}</button>
            </div>
        </div>
    </aside>

    <div id="munch-pricing-modal" class="munch-pricing-modal" hidden>
        <div class="munch-pricing-modal__panel">
            <div class="munch-pricing-modal__header">
                <h2 class="h4 mb-0">{{ translate('Bulk Price Edit') }}</h2>
                <button type="button" class="btn btn-soft-secondary square-btn rounded-circle" data-pricing-close aria-label="Close">
                    <i class="tio-clear"></i>
                </button>
            </div>
            <div id="munch-pricing-modal-body" class="munch-pricing-modal__body"></div>
        </div>
    </div>

    <div id="munch-pricing-copy-modal" class="munch-pricing-modal munch-pricing-copy-modal" hidden>
        <div class="munch-pricing-modal__panel" style="width:min(720px,100%)">
            <div class="munch-pricing-modal__header">
                <h2 class="h4 mb-0">{{ translate('Copy From Branch') }}</h2>
                <button type="button" class="btn btn-soft-secondary square-btn rounded-circle" data-copy-close aria-label="Close">
                    <i class="tio-clear"></i>
                </button>
            </div>
            <div id="munch-pricing-copy-body" class="munch-pricing-modal__body"></div>
        </div>
    </div>
@endsection

@push('script_2')
    <script>
        "use strict";

        $(".recommended-status-change").change(function() {
            var value = $(this).val();
            let url = $(this).data('url');
            console.log(value, url);
            status_change(this, url);
        });

        function recommended_status_change(t) {
            let url = $(t).data('url');
            let checked = $(t).prop("checked");
            let status = checked === true ? 1 : 0;

            Swal.fire({
                title: 'Are you sure?',
                text: 'Want to change the recommended status',
                type: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#E7032D',
                cancelButtonColor: 'default',
                cancelButtonText: '{{translate("No")}}',
                confirmButtonText: '{{translate("Yes")}}',
                reverseButtons: true
            }).then((result) => {
                    if (result.value) {
                        $.ajaxSetup({
                            headers: {
                                'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')
                            }
                        });
                        $.ajax({
                            url: url,
                            data: {
                                status: status
                            },
                            success: function (data, status) {
                                toastr.success("{{translate('updated successfully')}}");
                            },
                            error: function (data) {
                                toastr.error("{{translate('updated failed')}}");
                            }
                        });
                    }
                    else if (result.dismiss) {
                        if (status == 1) {
                            $('#' + t.id).prop('checked', false)

                        } else if (status == 0) {
                            $('#'+ t.id).prop('checked', true)
                        }
                        toastr.info("{{translate("recommended status has not changed")}}");
                    }
                }
            )
        }
    </script>
    <script src="{{ asset('public/assets/admin/js/munch-product-pricing.js') }}?v=2.2"></script>
    <script src="{{ asset('public/assets/admin/js/munch-product-stock.js') }}?v=1.2"></script>
@endpush
