@if(empty($orders))
    <p class="empty">No orders</p>
@else
    <table>
        <thead>
        <tr>
            <th>Timestamp</th>
            <th>Munch Order #</th>
            <th>Marketplace Order #</th>
            <th>Sales Category</th>
            <th>Order Type</th>
            <th class="num">Amount</th>
        </tr>
        </thead>
        <tbody>
        @foreach($orders as $order)
            <tr>
                <td>{{ $order['timestamp'] ?? '' }}</td>
                <td>{{ $order['order_number'] ?? '' }}</td>
                <td>{{ $order['platform_order_number'] ?? '' }}</td>
                <td>{{ $order['sales_category'] ?? '' }}</td>
                <td>{{ $order['order_type'] ?? '' }}</td>
                <td class="num">{{ \App\Support\AdminSaleReportExport::formatAmount($order['amount'] ?? 0) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
