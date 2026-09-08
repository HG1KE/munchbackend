{{-- Paste into layouts/admin/partials/_sidebar.blade.php (e.g. near other system_management items), or use: @include('admin-views.marketing.sidebar-menu-snippet') --}}
@if(Helpers::module_permission_check(MANAGEMENT_SECTION['system_management']))
    <li class="navbar-vertical-aside-has-menu {{ Request::is('admin/marketing*') ? 'show active' : '' }}">
        <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle" href="javascript:">
            <i class="tio-chart-bar-4 nav-icon"></i>
            <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">Marketing</span>
        </a>
        <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
            style="display: {{ Request::is('admin/marketing*') ? 'block' : 'none' }}">
            <li class="nav-item {{ Request::is('admin/marketing/promotional-sms-gateway*') ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('admin.marketing.promotional-sms-gateway.index') }}">
                    <span class="tio-circle nav-indicator-icon"></span>
                    <span class="text-truncate">{{ translate('Promotional SMS gateway') }}</span>
                </a>
            </li>
            <li class="nav-item {{ Request::is('admin/marketing/abandoned-checkout*') ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('admin.marketing.abandoned-checkout.index') }}">
                    <span class="tio-circle nav-indicator-icon"></span>
                    <span class="text-truncate">{{ translate('Abandoned Checkout') }}</span>
                </a>
            </li>
            <li class="nav-item {{ Request::is('admin/marketing/reorder-reminders*') ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('admin.marketing.reorder-reminders.index') }}">
                    <span class="tio-circle nav-indicator-icon"></span>
                    <span class="text-truncate">{{ translate('Reorder Reminders') }}</span>
                </a>
            </li>
            <li class="nav-item {{ Request::is('admin/marketing/loyalty-delivery-sms') && ! Request::is('admin/marketing/loyalty-delivery-sms-activity*') ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('admin.marketing.loyalty-delivery-sms.index') }}">
                    <span class="tio-circle nav-indicator-icon"></span>
                    <span class="text-truncate">{{ translate('Loyalty Delivery SMS') }}</span>
                </a>
            </li>
            <li class="nav-item {{ Request::is('admin/marketing/sms-queue-activity*') ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('admin.marketing.sms-queue-activity.index') }}">
                    <span class="tio-circle nav-indicator-icon"></span>
                    <span class="text-truncate">{{ translate('Abandoned Checkout Activity') }}</span>
                </a>
            </li>
            <li class="nav-item {{ Request::is('admin/marketing/reorder-sms-queue-activity*') ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('admin.marketing.reorder-sms-queue-activity.index') }}">
                    <span class="tio-circle nav-indicator-icon"></span>
                    <span class="text-truncate">{{ translate('Reorder Reminder Activity') }}</span>
                </a>
            </li>
            <li class="nav-item {{ Request::is('admin/marketing/loyalty-delivery-sms-activity*') ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('admin.marketing.loyalty-delivery-sms-activity.index') }}">
                    <span class="tio-circle nav-indicator-icon"></span>
                    <span class="text-truncate">{{ translate('Loyalty Delivery Activity') }}</span>
                </a>
            </li>
        </ul>
    </li>
@endif
