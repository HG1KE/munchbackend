@php
    $platformOrderNumber = trim((string) ($order->platform_order_number ?? ''));
    $platformOrderChannel = $order->sales_channel ?? null;
@endphp
@if($platformOrderNumber !== '' && \App\Support\PosOrderTypes::isMarketplaceChannel($platformOrderChannel))
    <div class="d-flex gap-3 justify-content-sm-end mb-3">
        <span>{{ \App\Support\PosOrderTypes::platformOrderNumberLabel($platformOrderChannel) }} :</span>
        <span class="text-dark">{{ $platformOrderNumber }}</span>
    </div>
@endif
