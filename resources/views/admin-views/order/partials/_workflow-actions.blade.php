@php
    $statusRoute = $statusRoute ?? 'admin.orders.status';
    $currentStatus = $order['order_status'] ?? '';
    $showBeginPreparing = in_array($currentStatus, ['pending', 'confirmed'], true);
    $showOutForDelivery = $currentStatus === 'processing';
    $showMarkDelivered = $currentStatus === 'out_for_delivery';
@endphp

<div class="w-100">
    <label class="font-weight-bold text-dark fz-14">{{ translate('Change_Order_Status') }}</label>
    <div class="form-control h--45px d-flex align-items-center mb-3 text-capitalize">
        {{ translate($currentStatus) }}
    </div>
    <div class="d-flex flex-column gap-2">
        @if($showBeginPreparing)
            <a class="btn btn-primary route-alert"
               href="javascript:"
               data-route="{{ route($statusRoute, ['id' => $order['id'], 'order_status' => 'processing']) }}"
               data-message="{{ translate('Change status to processing ?') }}">
                {{ translate('Begin Preparing') }}
            </a>
        @endif
        @if($showOutForDelivery)
            <a class="btn btn-primary route-alert"
               href="javascript:"
               data-route="{{ route($statusRoute, ['id' => $order['id'], 'order_status' => 'out_for_delivery']) }}"
               data-message="{{ translate('Change status to out for delivery ?') }}">
                {{ translate('Out for Delivery') }}
            </a>
        @endif
        @if($showMarkDelivered)
            <a class="btn btn-primary route-alert"
               href="javascript:"
               data-route="{{ route($statusRoute, ['id' => $order['id'], 'order_status' => 'delivered']) }}"
               data-message="{{ translate('Change status to delivered ?') }}">
                {{ translate('Mark as Delivered') }}
            </a>
        @endif
    </div>
</div>
