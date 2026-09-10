<div class="table-responsive datatable-custom">
    <table id="datatable" class="width-100-percent">
        <tbody id="set-rows">
        <tr>
            <td>{{translate('#')}} </td>
            <td>{{translate('order')}}</td>
            <td>{{translate('date')}}</td>
            <td>{{translate('qty')}}</td>
            <td>{{translate('customer')}}</td>
            <td>{{translate('amount')}}</td>
        </tr>
        @foreach($data as $key=>$row)
            <tr>
                <td class="pull-right">
                    {{$key+1}}
                </td>
                <td class="table-column-pl-0">
                    <a href="{{route('admin.orders.details',['id'=>$row['order_id']])}}">{{ $row['order_display_id'] ?? $row['order_id'] }}</a>
                </td>
                <td>{{date('d M Y',strtotime($row['date']))}}</td>
                <td>{{$row['quantity']}}</td>
                <td>
                    @if($row['customer'])
                        <a class="text-body text-capitalize">{{$row['customer']->f_name.' '.$row['customer']->l_name}}</a>
                    @else
                        <label
                            class="badge badge-danger">{{translate('invalid')}} {{translate('customer')}} {{translate('data')}}</label>
                    @endif
                </td>
                <td>{{ \App\CentralLogics\Helpers::set_symbol($row['price']) }}</td>
            </tr>
        @endforeach
        </tbody>
        @if(!empty($summary ?? null))
            <tfoot>
                <tr>
                    <td colspan="5">{{ translate('Gross Sales') }}</td>
                    <td>{{ $summary['gross_sales'] }}</td>
                </tr>
                <tr>
                    <td colspan="5">{{ translate('Total Discounts') }}</td>
                    <td>{{ $summary['total_discounts'] }}</td>
                </tr>
                <tr>
                    <td colspan="5">{{ translate('Net Sales') }}</td>
                    <td>{{ $summary['net_sales'] }}</td>
                </tr>
                <tr>
                    <td colspan="5">{{ translate('Munch Sales') }}</td>
                    <td>{{ $summary['munch_sales'] ?? '' }}</td>
                </tr>
                <tr>
                    <td colspan="5">{{ translate('Marketplace Sales') }}</td>
                    <td>{{ $summary['marketplace_sales'] ?? '' }}</td>
                </tr>
                <tr>
                    <td colspan="5">{{ translate('tax') }}</td>
                    <td>{{ $summary['tax'] }}</td>
                </tr>
                <tr>
                    <td colspan="5">{{ translate('Delivery Fees') }}</td>
                    <td>{{ $summary['delivery_fees'] }}</td>
                </tr>
                <tr>
                    <td colspan="5">{{ translate('Total Sales') }}</td>
                    <td>{{ $summary['total_sales'] }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
