@php
    $channel = $channel ?? null;
    $fallbackType = $fallbackType ?? null;
    $label = \App\Support\PosOrderTypes::channelLabel($channel, $fallbackType);
@endphp
@once
    <style>
        .munch-channel-badge {
            display: inline-block;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            line-height: 1.2;
            white-space: nowrap;
        }
        .munch-channel-badge--glovo { background: #facc15; color: #1c1917; }
        .munch-channel-badge--uber { background: #111827; color: #fff; }
        .munch-channel-badge--bolt_food { background: #16a34a; color: #fff; }
        .munch-channel-badge--plain { background: #dcfce7; color: #166534; }
    </style>
@endonce
@if(\App\Support\PosOrderTypes::isMarketplaceChannel($channel))
    <span class="{{ \App\Support\PosOrderTypes::channelBadgeClass($channel) }}">{{ $label }}</span>
@else
    <span class="badge-soft-success px-2 py-1 rounded">{{ $label }}</span>
@endif
