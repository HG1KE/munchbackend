<div class="card mb-4">
    <div class="card-body py-2">
        <ul class="nav nav-segment nav-segment-light">
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('admin.marketing.promotional-sms-gateway.*') ? 'active' : '' }}"
                   href="{{ route('admin.marketing.promotional-sms-gateway.index') }}">
                    {{ translate('Promotional SMS gateway') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('admin.marketing.abandoned-checkout.*') ? 'active' : '' }}"
                   href="{{ route('admin.marketing.abandoned-checkout.index') }}">
                    {{ translate('Abandoned Checkout') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('admin.marketing.reorder-reminders.*') ? 'active' : '' }}"
                   href="{{ route('admin.marketing.reorder-reminders.index') }}">
                    {{ translate('Reorder Reminders') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('admin.marketing.loyalty-delivery-sms.*') ? 'active' : '' }}"
                   href="{{ route('admin.marketing.loyalty-delivery-sms.index') }}">
                    {{ translate('Loyalty Delivery SMS') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('admin.marketing.sms-queue-activity.*') ? 'active' : '' }}"
                   href="{{ route('admin.marketing.sms-queue-activity.index') }}">
                    {{ translate('Abandoned Checkout Activity') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('admin.marketing.reorder-sms-queue-activity.*') ? 'active' : '' }}"
                   href="{{ route('admin.marketing.reorder-sms-queue-activity.index') }}">
                    {{ translate('Reorder Reminder Activity') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('admin.marketing.loyalty-delivery-sms-activity.*') ? 'active' : '' }}"
                   href="{{ route('admin.marketing.loyalty-delivery-sms-activity.index') }}">
                    {{ translate('Loyalty Delivery Activity') }}
                </a>
            </li>
        </ul>
    </div>
</div>
