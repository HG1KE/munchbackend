@extends('layouts.admin.app')

@section('title', translate('Sale Report'))

@push('css_or_js')
    <link rel="stylesheet" href="{{ asset('public/assets/admin/css/munch-sale-report.css') }}?v=1.0">
@endpush

@section('content')
    <div class="content container-fluid munch-sale-report">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <img width="20" class="avatar-img" src="{{asset('public/assets/admin/img/icons/sales.png')}}" alt="">
                <span class="page-header-title">
                    {{translate('Sale_Report')}}
                </span>
            </h2>
        </div>

        <div class="card mb-3 munch-sale-report__filters">
            <div class="card-body">
                <form action="javascript:" id="search-form" method="POST">
                    @csrf
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-6 col-lg-3">
                            <label class="input-label" for="branch_id">{{ translate('Select Branch') }}</label>
                            <select class="custom-select custom-select" name="branch_id" id="branch_id" required>
                                <option  disabled>{{translate('Select Branch')}}</option>
                                <option value="all">All</option>
                                @foreach(\App\Model\Branch::all() as $branch)
                                    <option value="{{$branch['id']}}" {{session('branch_filter')==$branch['id']?'selected':''}}>{{$branch['name']}}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-sm-6 col-lg-2">
                            <label class="input-label" for="sales_channel">{{ translate('Sales Channel') }}</label>
                            <select class="custom-select" name="sales_channel" id="sales_channel">
                                <option value="all">{{ translate('All') }} {{ translate('Sales Channel') }}</option>
                                <option value="delivery">{{ translate('Delivery') }}</option>
                                <option value="takeaway">{{ translate('Take Away') }}</option>
                                <option value="dine_in">{{ translate('Dine In') }}</option>
                                <option value="glovo">Glovo</option>
                                <option value="uber">Uber</option>
                                <option value="bolt_food">Bolt Food</option>
                                <option value="pos">POS</option>
                            </select>
                        </div>
                        <div class="col-sm-6 col-lg-2">
                            <label class="input-label" for="from_date">{{ translate('from') }}</label>
                            <input type="date" name="from" id="from_date" class="form-control" required>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label class="input-label" for="to_date">{{ translate('to') }}</label>
                            <input type="date" name="to" id="to_date" class="form-control" required>
                        </div>
                        <div class="col-sm-6 col-lg-2">
                            <button type="submit" class="btn btn-primary btn-block">{{translate('show')}}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="munch-sale-report__meta">
            <span class="munch-sale-report__chip">
                {{translate('total_Orders')}}
                <strong id="order_count">—</strong>
            </span>
            <span class="munch-sale-report__chip">
                {{translate('total_Item_Qty')}}
                <strong id="item_count">—</strong>
            </span>
        </div>

        <span class="munch-sale-report__visually-hidden" id="order_amount"></span>
        <span class="munch-sale-report__visually-hidden" id="total-discounts-highlight"></span>

        <div class="munch-sale-report__grid munch-sale-report__grid--kpis" id="sale-summary">
            <article class="munch-sale-report__card">
                <span class="munch-sale-report__label">{{ translate('Gross Sales') }}</span>
                <p class="munch-sale-report__value" id="sum-gross-sales">—</p>
            </article>
            <article class="munch-sale-report__card munch-sale-report__card--discount">
                <span class="munch-sale-report__label">{{ translate('Total Discounts') }}</span>
                <p class="munch-sale-report__value" id="sum-total-discounts">—</p>
            </article>
            <article class="munch-sale-report__card">
                <span class="munch-sale-report__label">{{ translate('Net Sales') }}</span>
                <p class="munch-sale-report__value" id="sum-net-sales">—</p>
            </article>
            <article class="munch-sale-report__card munch-sale-report__card--munch">
                <span class="munch-sale-report__label">{{ translate('Munch Sales') }}</span>
                <p class="munch-sale-report__value" id="sum-munch-sales">—</p>
            </article>
            <article class="munch-sale-report__card munch-sale-report__card--market">
                <span class="munch-sale-report__label">{{ translate('Marketplace Sales') }}</span>
                <p class="munch-sale-report__value" id="sum-marketplace-sales">—</p>
            </article>
            <article class="munch-sale-report__card munch-sale-report__card--accent">
                <span class="munch-sale-report__label">{{ translate('Total Sales') }}</span>
                <p class="munch-sale-report__value" id="sum-total-sales">—</p>
            </article>
        </div>

        <div class="munch-sale-report__grid munch-sale-report__grid--secondary">
            <article class="munch-sale-report__card munch-sale-report__card--compact">
                <span class="munch-sale-report__label">{{ translate('tax') }}</span>
                <p class="munch-sale-report__value" id="sum-tax">—</p>
            </article>
            <article class="munch-sale-report__card munch-sale-report__card--compact">
                <span class="munch-sale-report__label">{{ translate('Delivery Fees') }}</span>
                <p class="munch-sale-report__value" id="sum-delivery-fees">—</p>
            </article>
        </div>

        <h3 class="munch-sale-report__section">{{ translate('Payment methods') }}</h3>
        <div class="munch-sale-report__grid munch-sale-report__grid--payments" id="payment-totals">
            <article class="munch-sale-report__card munch-sale-report__card--pay">
                <span class="munch-sale-report__label">{{ translate('Cash') }}</span>
                <p class="munch-sale-report__value" id="pay-cash">—</p>
            </article>
            <article class="munch-sale-report__card munch-sale-report__card--pay">
                <span class="munch-sale-report__label">{{ translate('Card') }}</span>
                <p class="munch-sale-report__value" id="pay-card">—</p>
            </article>
            <article class="munch-sale-report__card munch-sale-report__card--pay">
                <span class="munch-sale-report__label">{{ translate('M-PESA') }}</span>
                <p class="munch-sale-report__value" id="pay-mpesa">—</p>
            </article>
            <article class="munch-sale-report__card munch-sale-report__card--pay">
                <span class="munch-sale-report__label">Glovo</span>
                <p class="munch-sale-report__value" id="pay-glovo">—</p>
            </article>
            <article class="munch-sale-report__card munch-sale-report__card--pay">
                <span class="munch-sale-report__label">Uber</span>
                <p class="munch-sale-report__value" id="pay-uber">—</p>
            </article>
            <article class="munch-sale-report__card munch-sale-report__card--pay">
                <span class="munch-sale-report__label">Bolt Food</span>
                <p class="munch-sale-report__value" id="pay-bolt_food">—</p>
            </article>
        </div>

        <div class="card munch-sale-report__table-card">
            <div class="card-header">
                <h4 class="card-title mb-0">{{ translate('order') }}</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive datatable_wrapper_row" id="set-rows">
                    @include('admin-views.report.partials._table',['data'=>[]])
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script_2')
    <script>
        $('#search-form').on('submit', function () {
            $.post({
                url: "{{route('admin.report.sale-report-filter')}}",
                data: $('#search-form').serialize(),

                beforeSend: function () {
                    $('#loading').show();
                },
                success: function (data) {
                    $('#order_count').html(data.order_count);
                    $('#order_amount').html(data.order_sum);
                    $('#item_count').html(data.item_qty);
                    if (data.summary) {
                        $('#sum-gross-sales').html(data.summary.gross_sales);
                        $('#sum-total-discounts').html(data.summary.total_discounts);
                        $('#sum-net-sales').html(data.summary.net_sales);
                        $('#sum-munch-sales').html(data.summary.munch_sales);
                        $('#sum-marketplace-sales').html(data.summary.marketplace_sales);
                        $('#sum-tax').html(data.summary.tax);
                        $('#sum-delivery-fees').html(data.summary.delivery_fees);
                        $('#sum-total-sales').html(data.summary.total_sales);
                        $('#total-discounts-highlight').html(data.summary.total_discounts);
                    }
                    if (data.payment_totals) {
                        $('#pay-cash').html(data.payment_totals.cash);
                        $('#pay-card').html(data.payment_totals.card);
                        $('#pay-mpesa').html(data.payment_totals.mpesa);
                        $('#pay-glovo').html(data.payment_totals.glovo);
                        $('#pay-uber').html(data.payment_totals.uber);
                        $('#pay-bolt_food').html(data.payment_totals.bolt_food);
                    }
                    $('#set-rows').html(data.view);
                    $('.card-footer').hide();
                },
                complete: function () {
                    $('#loading').hide();
                },
            });
        });

        $('#from_date,#to_date').change(function () {
            let fr = $('#from_date').val();
            let to = $('#to_date').val();
            if (fr != '' && to != '') {
                if (fr > to) {
                    $('#from_date').val('');
                    $('#to_date').val('');
                    toastr.error('Invalid date range!', Error, {
                        CloseButton: true,
                        ProgressBar: true
                    });
                }
            }
        });
    </script>
    <script type="text/javascript">
        $(document).ready(function () {
            $('input').addClass('form-control');
        });


        var datatable = $.HSCore.components.HSDatatables.init($('#datatable'), {
            dom: 'Bfrtip',
            language: {
                zeroRecords: '<div class="text-center p-4">' +
                    '<img class="mb-3" src="{{asset('public/assets/admin')}}/svg/illustrations/sorry.svg" alt="Image Description" style="width: 7rem;">' +
                    '<p class="mb-0">{{translate('No data to show')}}</p>' +
                    '</div>'
            }
        });
    </script>
@endpush
