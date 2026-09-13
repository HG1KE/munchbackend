@php
    $sectionKey = $sectionKey ?? 'munch_sales';
    $columns = \App\Support\AdminSaleReportExport::sectionColumnLabels($sectionKey);
    $emptyLabel = $sectionKey === \App\Support\AdminSaleReportExport::CANCELLED_SECTION
        ? 'No cancelled orders'
        : 'No orders';
@endphp
@if(empty($orders))
    <p class="empty">{{ $emptyLabel }}</p>
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
            @php $cells = \App\Support\AdminSaleReportExport::orderCells($order, $sectionKey); @endphp
            <tr>
                @foreach($cells as $index => $cell)
                    <td class="{{ $index === count($cells) - 1 ? 'num' : '' }}">{{ $cell }}</td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
