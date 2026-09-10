@extends('layouts.admin.app')

@section('title', translate('Sale Report'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <img width="20" class="avatar-img" src="{{asset('public/assets/admin/img/icons/sales.png')}}" alt="">
                <span class="page-header-title">
                    {{translate('Sale_Report')}}
                </span>
            </h2>
        </div>

        <div class="card mt-3">
            <div class="card-body">
                <div class="media flex-column flex-sm-row flex-wrap align-items-sm-center gap-4">
                    <div class="avatar avatar-xl">
                        <img class="avatar-img" src="{{asset('public/assets/admin')}}/svg/illustrations/credit-card.svg"
                            alt="{{ translate('sale_report') }}">
                    </div>

                    <div class="media-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div class="">
                                <h2 class="page-header-title">{{translate('sale')}} {{translate('report')}} {{translate('overview')}}</h2>

                                <div class="row align-items-center">
                                    <div class="col-auto">
                                        <span>{{translate('admin')}}:</span>
                                        <a href="#">{{auth('admin')->user()->f_name.' '.auth('admin')->user()->l_name}}</a>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex">
                                <a class="btn btn-icon btn-primary rounded-circle px-2" href="{{route('admin.dashboard')}}">
                                    <i class="tio-home-outlined"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body">
                <form action="javascript:" id="search-form" method="POST">
                    @csrf
                    <div class="row g-2">
                        <div class="col-sm-6 col-md-3">
                            <select class="custom-select custom-select" name="branch_id" id="branch_id" required>
                                <option  disabled>{{translate('Select Branch')}}</option>
                                <option value="all">All</option>
                                @foreach(\App\Model\Branch::all() as $branch)
                                    <option value="{{$branch['id']}}" {{session('branch_filter')==$branch['id']?'selected':''}}>{{$branch['name']}}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-sm-6 col-md-2">
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
                        <div class="col-sm-6 col-md-2">
                            <input type="date" name="from" id="from_date" class="form-control" required>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <input type="date" name="to" id="to_date" class="form-control" required>
                        </div>
                        <div class="col-sm-6 col-md-2">
                            <button type="submit" class="btn btn-primary btn-block">{{translate('show')}}</button>
                        </div>

                        <div class="col-md-6 d-flex flex-column gap-2">
                            <div>
                                <strong>
                                    {{translate('total_Orders')}} :
                                    <span id="order_count"> </span>
                                </strong>
                            </div>
                            <div>
                                <strong>
                                    {{translate('total_Item_Qty')}}
                                    : <span
                                        id="item_count"> </span>
                                </strong>
                            </div>
                            <div>
                                <strong>{{translate('total')}}  {{translate('amount')}} : <span
                                        id="order_amount"></span>
                                </strong>
                            </div>
                            <div class="d-flex flex-wrap gap-3 mt-3" id="sale-summary">
                                <span>{{ translate('Gross Sales') }}: <strong id="sum-gross-sales"></strong></span>
                                <span>{{ translate('Total Discounts') }}: <strong id="sum-total-discounts"></strong></span>
                                <span>{{ translate('Net Sales') }}: <strong id="sum-net-sales"></strong></span>
                                <span>{{ translate('tax') }}: <strong id="sum-tax"></strong></span>
                                <span>{{ translate('Delivery Fees') }}: <strong id="sum-delivery-fees"></strong></span>
                                <span>{{ translate('Total Sales') }}: <strong id="sum-total-sales"></strong></span>
                            </div>
                            <div class="mt-2">
                                <strong>{{ translate('Total Discounts') }}: <span id="total-discounts-highlight"></span></strong>
                            </div>
                            <div id="payment-totals" class="d-flex flex-wrap gap-3 mt-2 small">
                                <span>{{ translate('Cash') }}: <strong id="pay-cash"></strong></span>
                                <span>{{ translate('Card') }}: <strong id="pay-card"></strong></span>
                                <span>{{ translate('M-PESA') }}: <strong id="pay-mpesa"></strong></span>
                                <span>Glovo: <strong id="pay-glovo"></strong></span>
                                <span>Uber: <strong id="pay-uber"></strong></span>
                                <span>Bolt Food: <strong id="pay-bolt_food"></strong></span>
                            </div>
                        </div>
                    </div>
                </form>

                <hr>

                <div class="table-responsive datatable_wrapper_row mt-5" id="set-rows">
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
