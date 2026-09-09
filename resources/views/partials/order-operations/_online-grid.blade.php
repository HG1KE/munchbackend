@php
    $section = $section ?? 'pending';
    $orders = $orders ?? collect();
    $liveSlaSections = ['pending', 'packing', 'prep'];
    $isLiveSlaSection = in_array($section, $liveSlaSections, true);
    $showBranch = $showBranch ?? true;
@endphp

@if($orders->isEmpty())
    <div class="meatco-ops-empty meatco-ops-empty--compact">
        <p class="mb-0">{{ $emptyMessage ?? translate('No orders in this section.') }}</p>
    </div>
@else
    <div class="meatco-express-grid">
        @foreach($orders as $order)
            @php
                $placedAtUnix = (int) ($order['placed_at_unix'] ?? 0);
                $timerFrozen = ! empty($order['timer_frozen']);
                $elapsedSeconds = (int) ($order['elapsed_seconds'] ?? 0);
                $elapsedDisplay = $order['elapsed_display'] ?? null;
                $canonicalStatus = $order['order_status'] ?? '';
                $cardClasses = ['meatco-express-card'];
                if ($section === 'dispatched') {
                    $cardClasses[] = 'meatco-express-card--dispatched';
                    if ($canonicalStatus === 'out_for_delivery') {
                        $cardClasses[] = 'meatco-express-card--in-transit';
                    }
                }
            @endphp
            <a href="{{ route($detailRoute, ['id' => $order['route_key'] ?? $order['id']]) }}"
               class="{{ implode(' ', $cardClasses) }}"
               data-meatco-express-card
               data-order-id="{{ $order['id'] }}"
               @if($isLiveSlaSection) data-meatco-express-sla="1" @endif>
                <div class="meatco-express-card__top">
                    <span class="meatco-express-card__id">#{{ $order['order_display_id'] ?? $order['readable_order_id'] ?? $order['id'] }}</span>
                    <span class="meatco-express-card__timer{{ $timerFrozen ? ' meatco-express-card__timer--frozen' : '' }}"
                          data-meatco-order-timer
                          data-order-id="{{ $order['id'] }}"
                          data-placed-at="{{ $placedAtUnix }}"
                          @if($timerFrozen) data-timer-frozen="1" data-frozen-elapsed="{{ $elapsedSeconds }}" @endif>{{ $elapsedDisplay ?? '00:00:00' }}</span>
                </div>
                <div class="meatco-express-card__meta meatco-express-card__meta--customer">
                    {{ $order['customer_name'] }}
                </div>
                @if($showBranch && !empty($order['branch_name']))
                    <div class="meatco-express-card__meta">{{ translate('Branch') }}: {{ $order['branch_name'] }}</div>
                @endif
                <div class="meatco-express-card__meta">{{ translate('Area') }}: {{ $order['area'] }}</div>
                @if(!empty($order['item_lines']))
                    <div class="meatco-express-card__meta">{{ translate('Items') }}: {{ implode(', ', $order['item_lines']) }}</div>
                @elseif(isset($order['item_count']))
                    <div class="meatco-express-card__meta">{{ translate('Items') }}: {{ $order['item_count'] }}</div>
                @endif
                <div class="meatco-express-card__meta">{{ translate('Amount') }}: {{ $order['payable_amount_formatted'] ?? $order['order_amount_formatted'] }}</div>
                @php($paymentLabel = $order['payment_method_label'] ?? \App\Support\PaymentMethodLabel::operationalTitle($order['payment_method'] ?? null))
                @if(!empty($paymentLabel))
                    <div class="meatco-express-card__meta">{{ translate('Payment') }}: {{ $paymentLabel }}</div>
                @endif
                @if(!empty($order['rider_name']))
                    <div class="meatco-express-card__meta">{{ translate('Rider') }}: {{ $order['rider_name'] }}</div>
                @endif
                <div class="meatco-express-card__footer">
                    <span class="meatco-ops-badge meatco-ops-badge--status">{{ $order['order_status_label'] }}</span>
                    <span class="meatco-ops-badge {{ ($order['payment_status'] ?? '') === 'paid' ? 'meatco-ops-badge--paid' : 'meatco-ops-badge--unpaid' }}">
                        {{ translate($order['payment_status'] ?? 'unpaid') }}
                    </span>
                    <span class="meatco-ops-badge">{{ $order['fulfillment_label'] ?? translate('Online Orders') }}</span>
                </div>
            </a>
        @endforeach
    </div>
@endif
