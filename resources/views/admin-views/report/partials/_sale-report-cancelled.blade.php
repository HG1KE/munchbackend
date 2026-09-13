@php
    $cancelledKey = \App\Support\AdminSaleReportExport::CANCELLED_SECTION;
    $cancelled = $report['sections'][$cancelledKey] ?? [];
    $orders = \App\Support\AdminSaleReportExport::sectionOrders($cancelled);
    $columns = \App\Support\AdminSaleReportExport::sectionColumnLabels($cancelledKey);
    $count = (int) ($report['cancelled']['order_count'] ?? \App\Support\AdminSaleReportExport::sectionOrderCount($cancelled));
    $total = $report['cancelled']['total'] ?? ($cancelled['total'] ?? 0);
@endphp
<div class="munch-sale-report__cancelled">
    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
        <div>
            <h4 class="card-title mb-1">{{ translate('Cancelled') }}</h4>
            <p class="text-muted mb-0 small">{{ translate('Not included in sales') }}</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <span class="munch-sale-report__chip">
                {{ translate('Cancelled Orders') }}
                <strong>{{ $count }}</strong>
            </span>
            <span class="munch-sale-report__chip">
                {{ translate('Cancelled Total') }}
                <strong>{{ \App\Support\AdminSaleReportExport::formatAmount($total) }}</strong>
            </span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-borderless table-thead-bordered table-nowrap table-align-middle card-table w-100 munch-sale-report__cancelled-table">
            <thead class="thead-light">
                <tr>
                    @foreach($columns as $column)
                        <th class="{{ $column === 'Amount' ? 'text-right' : '' }}">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
            @forelse($orders as $order)
                @php $cells = \App\Support\AdminSaleReportExport::orderCells($order, $cancelledKey); @endphp
                <tr>
                    @foreach($cells as $index => $cell)
                        <td class="{{ $index === count($cells) - 1 ? 'text-right' : '' }}">{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) }}" class="text-muted">{{ translate('No cancelled orders') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
