@php
    $pendingOrders = $pendingOrders ?? collect();
    $packingOrders = $packingOrders ?? collect();
    $dispatchedOrders = $dispatchedOrders ?? collect();
    $pendingCount = $pendingOrders->count();
    $packingCount = $packingOrders->count();
    $dispatchedCount = $dispatchedOrders->count();
@endphp

<section class="meatco-express-section meatco-express-section--pending" data-meatco-express-section="pending">
    <header class="meatco-express-section__header">
        <h2 class="meatco-express-section__title">{{ translate('Pending') }}</h2>
        <span class="meatco-express-section__count">({{ $pendingCount }})</span>
        <p class="meatco-express-section__subtitle mb-0">{{ translate('Orders waiting to be accepted into the kitchen.') }}</p>
    </header>
    @include('partials.order-operations._online-grid', [
        'orders' => $pendingOrders,
        'detailRoute' => $detailRoute,
        'showBranch' => $showBranch ?? true,
        'section' => 'pending',
        'emptyMessage' => translate('No pending online orders.'),
    ])
</section>

<section class="meatco-express-section meatco-express-section--packing" data-meatco-express-section="packing">
    <header class="meatco-express-section__header">
        <h2 class="meatco-express-section__title">{{ translate('Preparing') }}</h2>
        <span class="meatco-express-section__count">({{ $packingCount }})</span>
        <p class="meatco-express-section__subtitle mb-0">{{ translate('Orders currently being prepared.') }}</p>
    </header>
    @include('partials.order-operations._online-grid', [
        'orders' => $packingOrders,
        'detailRoute' => $detailRoute,
        'showBranch' => $showBranch ?? true,
        'section' => 'packing',
        'emptyMessage' => translate('No orders currently being prepared.'),
    ])
</section>

<section class="meatco-express-section meatco-express-section--dispatched" data-meatco-express-section="dispatched">
    <header class="meatco-express-section__header">
        <h2 class="meatco-express-section__title">{{ translate('Dispatched') }}</h2>
        <span class="meatco-express-section__count">({{ $dispatchedCount }})</span>
        <p class="meatco-express-section__subtitle mb-0">{{ translate('Orders that are out for delivery.') }}</p>
    </header>
    @include('partials.order-operations._online-grid', [
        'orders' => $dispatchedOrders,
        'detailRoute' => $detailRoute,
        'showBranch' => $showBranch ?? true,
        'section' => 'dispatched',
        'emptyMessage' => translate('No dispatched orders right now.'),
    ])
</section>
