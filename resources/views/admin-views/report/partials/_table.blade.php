<link rel="stylesheet" href="https://cdn.datatables.net/1.11.1/css/jquery.dataTables.min.css">

<table class="table table-hover table-borderless table-thead-bordered table-nowrap table-align-middle card-table w-100 mt-3" id="datatable">
    <thead class="thead-light">
        <tr>
            <th>{{translate('SL')}} </th>
            <th>{{translate('order')}}</th>
            <th>{{translate('Platform')}}</th>
            <th>{{translate('Platform Order No.')}}</th>
            <th>{{translate('date')}}</th>
            <th>{{translate('qty')}}</th>
            <th>{{translate('amount')}}</th>
        </tr>
    </thead>
    <tbody>
    @foreach($data as $key=>$row)
        <tr>
            <td class="">
                {{$key+1}}
            </td>
            <td class="">
                <a href="{{route('admin.orders.details',['id'=>$row['order_id']])}}">{{ $row['order_display_id'] ?? $row['order_id'] }}</a>
            </td>
            <td>{{ $row['sales_channel_label'] ?? '' }}</td>
            <td>{{ $row['platform_order_number'] ?? '' }}</td>
            <td>{{date('d M Y',strtotime($row['date']))}}</td>
            <td>{{$row['quantity']}}</td>
            <td>{{ \App\CentralLogics\Helpers::set_symbol($row['price']) }}</td>
        </tr>
    @endforeach
    </tbody>
    @if(!empty($summary ?? null))
        <tfoot>
            <tr>
                <th colspan="6">{{ translate('Gross Sales') }}</th>
                <th>{{ $summary['gross_sales'] }}</th>
            </tr>
            <tr>
                <th colspan="6">{{ translate('Total Discounts') }}</th>
                <th>{{ $summary['total_discounts'] }}</th>
            </tr>
            <tr>
                <th colspan="6">{{ translate('Net Sales') }}</th>
                <th>{{ $summary['net_sales'] }}</th>
            </tr>
            <tr>
                <th colspan="6">{{ translate('Munch Sales') }}</th>
                <th>{{ $summary['munch_sales'] ?? '' }}</th>
            </tr>
            <tr>
                <th colspan="6">{{ translate('Marketplace Sales') }}</th>
                <th>{{ $summary['marketplace_sales'] ?? '' }}</th>
            </tr>
            <tr>
                <th colspan="6">{{ translate('tax') }}</th>
                <th>{{ $summary['tax'] }}</th>
            </tr>
            <tr>
                <th colspan="6">{{ translate('Delivery Fees') }}</th>
                <th>{{ $summary['delivery_fees'] }}</th>
            </tr>
            <tr>
                <th colspan="6">{{ translate('Total Sales') }}</th>
                <th>{{ $summary['total_sales'] }}</th>
            </tr>
        </tfoot>
    @endif
</table>

<script type="text/javascript">
    $(document).ready(function () {
        $('input').addClass('form-control');
    });
    var datatable = $.HSCore.components.HSDatatables.init($('#datatable'), {
        dom: 'Bfrtip',
        "iDisplayLength": 25,
        @if(!empty($isSaleReport ?? false))
        buttons: [
            { text: 'Excel', action: function () { window.location.assign('{{ route('admin.report.export-sale-report', ['format' => 'xlsx']) }}'); } },
            { text: 'CSV', action: function () { window.location.assign('{{ route('admin.report.export-sale-report', ['format' => 'csv']) }}'); } },
            { text: 'PDF', action: function () { window.location.assign('{{ route('admin.report.export-sale-report', ['format' => 'pdf']) }}'); } },
            { text: 'Print', action: function () { window.location.assign('{{ route('admin.report.export-sale-report', ['format' => 'print']) }}'); } }
        ],
        @elseif(!empty($summary ?? null))
        buttons: [
            { extend: 'copy', footer: true },
            { extend: 'excel', footer: true },
            { extend: 'csv', footer: true },
            { extend: 'pdf', footer: true },
            { extend: 'print', footer: true }
        ],
        @endif
    });
</script>
