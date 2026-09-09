@php
    $timerState = $orderExpressTimerState ?? 'live';
    $showTimer = ($orderExpressTimerVisible ?? true) && $timerState !== 'hidden';

    $placedAtUnix = (int) ($orderPlacedAtUnix ?? 0);
    if ($placedAtUnix <= 0 && isset($order) && ! empty($order->id)) {
        try {
            $placedAtUnix = \App\Support\OrderPlacementTime::unix($order);
        } catch (\Throwable) {
            $placedAtUnix = 0;
        }
    }

    $timerFrozen = (bool) ($orderExpressTimerFrozen ?? false);
    $frozenElapsed = (int) ($orderExpressElapsedSeconds ?? 0);
    $frozenDisplay = $orderExpressElapsedDisplay ?? null;
    $isCompleted = $timerState === 'completed';
    $isDispatched = $timerState === 'dispatched';
@endphp
@if($showTimer)
<div class="meatco-express-detail-panel{{ $isDispatched ? ' meatco-express-detail-panel--dispatched' : '' }}{{ $isCompleted ? ' meatco-express-detail-panel--completed' : '' }}" data-meatco-express-detail-panel>
    <div class="meatco-express-detail-panel__header">
        <label class="font-weight-bold text-dark fz-14 mb-0">
            @if($isCompleted)
                {{ translate('Fulfillment time') }}
            @elseif($isDispatched)
                {{ translate('Prep to dispatch time') }}
            @else
                {{ translate('Time Since Order Placed') }}
            @endif
        </label>
        @unless($isCompleted)
            <span class="meatco-express-detail-panel__dispatch">
                {{ $isDispatched ? translate('Out for delivery') : translate('Dispatch ASAP') }}
            </span>
        @endunless
    </div>
    <div class="meatco-express-detail-panel__timer-wrap">
        @if($isCompleted)
            <span class="meatco-express-detail-panel__timer meatco-express-card__timer--frozen">
                {{ translate('Delivered in') }} {{ $frozenDisplay ?? \App\Support\OrderDispatchedTime::formatElapsedDisplay($frozenElapsed) }}
            </span>
        @else
            <span class="meatco-express-detail-panel__timer{{ $timerFrozen ? ' meatco-express-card__timer--frozen' : '' }}"
                  data-meatco-order-timer
                  data-order-id="{{ $order->id ?? '' }}"
                  data-placed-at="{{ $placedAtUnix }}"
                  @if($timerFrozen) data-timer-frozen="1" data-frozen-elapsed="{{ $frozenElapsed }}" @endif>{{ $frozenDisplay ?? '00:00:00' }}</span>
        @endif
    </div>
    <p class="meatco-express-detail-panel__hint mb-0">
        @if($isCompleted)
            {{ translate('Order fulfilled — SLA closed') }}
        @elseif($isDispatched)
            {{ translate('Frozen at dispatch — prep SLA complete') }}
        @else
            {{ translate('Target delivery: 60–90 mins') }}
        @endif
    </p>
</div>
@endif
