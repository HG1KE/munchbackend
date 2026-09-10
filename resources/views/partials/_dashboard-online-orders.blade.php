@php
    $onlineCount = $operations['online'] ?? $operations['express'] ?? 0;
    $onlineRoute = $onlineRoute ?? 'admin.orders.online';
    $posRoute = $posRoute ?? null;
    $showOnlineOrders = $showOnlineOrders ?? true;
@endphp

<div class="meatco-operations-panel meatco-ops-dashboard" id="munch-dashboard-live-root"@if($showOnlineOrders && !empty($liveCardsUrl)) data-live-cards-url="{{ $liveCardsUrl }}"@endif>
    <h4 class="meatco-ops-dashboard__title text-center">{{ translate('Operations') }}</h4>
    <p class="meatco-ops-dashboard__subtitle text-center">{{ translate('Live kitchen prep and rider dispatch') }}</p>

    <div class="meatco-ops-dashboard__grid{{ ($posRoute && $showOnlineOrders) ? '' : ' meatco-ops-dashboard__grid--single' }}">
        @if($showOnlineOrders)
            <a href="{{ route($onlineRoute) }}" class="meatco-ops-tile meatco-ops-tile--express">
                <span class="meatco-ops-tile__label">{{ translate('Live queue') }}</span>
                <span class="meatco-ops-tile__heading">{{ translate('Online Orders') }}</span>
                <p class="meatco-ops-tile__hint">{{ translate('60–90 min delivery · alerts enabled') }}</p>
                <span class="meatco-ops-tile__count" data-live-card="online">{{ $onlineCount }}</span>
            </a>
        @endif
        @if($posRoute)
            <a href="{{ route($posRoute) }}" class="meatco-ops-tile meatco-ops-tile--express">
                <span class="meatco-ops-tile__label">{{ translate('In-store') }}</span>
                <span class="meatco-ops-tile__heading">{{ translate('POS') }}</span>
                <p class="meatco-ops-tile__hint">{{ translate('Full-screen checkout · works offline') }}</p>
                <span class="meatco-ops-tile__count">{{ translate('Open') }}</span>
            </a>
        @endif
    </div>
</div>
