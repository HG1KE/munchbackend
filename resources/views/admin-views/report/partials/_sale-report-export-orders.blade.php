@php
    $sectionKey = $sectionKey ?? 'munch_sales';
    $columns = \App\Support\AdminSaleReportExport::sectionColumnLabels($sectionKey);
    $platformColumn = \App\Support\AdminSaleReportExport::marketplaceOrderColumn($sectionKey);
@endphp
@if(empty($orders))
    <p class="empty">No orders</p>
@else
    <table class="report-table">
        <thead>
        <tr>
            @foreach($columns as $column)
                <th class="{{ $column === 'Amount' ? 'num' : '' }}">{{ $column }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @foreach($orders as $order)
            <tr>
                <td>{{ $order['time'] ?? $order['timestamp'] ?? '' }}</td>
                <td>{{ $order['order_number'] ?? '' }}</td>
                @if($platformColumn !== '')
                    <td>{{ $order['platform_order_number'] ?? '' }}</td>
                @endif
                <td>{{ $order['order_type'] ?? '' }}</td>
                <td class="num">{{ \App\Support\AdminSaleReportExport::formatAmount($order['amount'] ?? 0) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
