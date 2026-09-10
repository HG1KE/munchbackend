@php
    $isCancelled = \App\Services\PosOrderCancellationService::isCancelledStatus($order['order_status'] ?? $order->order_status ?? null);
    $reason = trim((string) ($order['cancellation_reason'] ?? $order->cancellation_reason ?? ''));
@endphp
@if($isCancelled || $reason !== '')
    <div class="card mb-3 border-danger">
        <div class="card-body">
            <h5 class="text-danger mb-3">{{ translate('Cancelled') }}</h5>
            <dl class="row mb-0">
                @if($reason !== '')
                    <dt class="col-sm-4">{{ translate('Cancellation Reason') }}</dt>
                    <dd class="col-sm-8">{{ $reason }}</dd>
                @endif
                <dt class="col-sm-4">{{ translate('Cancelled by') }}</dt>
                <dd class="col-sm-8">{{ \App\Services\PosOrderCancellationService::actorDisplayName($order instanceof \App\Model\Order ? $order : $order) }}</dd>
                @php
                    $cancelledAt = $order->cancelled_at ?? null;
                    $cancelledAtLabel = $cancelledAt
                        ? \App\Support\TimezoneDisplay::parseStoredUtc($cancelledAt)?->timezone(\App\Support\TimezoneDisplay::businessTimezone())?->format('d M Y H:i')
                        : '';
                @endphp
                @if($cancelledAtLabel)
                    <dt class="col-sm-4">{{ translate('Cancelled at') }}</dt>
                    <dd class="col-sm-8">{{ $cancelledAtLabel }}</dd>
                @endif
            </dl>
        </div>
    </div>
@endif
