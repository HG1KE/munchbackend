<div id="sidebarMain" class="d-none">
    <aside class="js-navbar-vertical-aside navbar navbar-vertical-aside navbar-vertical navbar-vertical-fixed navbar-expand-xl navbar-bordered">
        <div class="navbar-vertical-container">
            <div class="navbar-vertical-footer-offset">
                <div class="navbar-brand-wrapper justify-content-between">
                    @php($restaurantLogo = \App\Model\BusinessSetting::where(['key' => 'logo'])->first()->value ?? '')
                    <a class="navbar-brand" href="{{ route('stocks.dashboard') }}">
                        <img class="navbar-brand-logo" style="object-fit: contain;"
                             src="{{ asset('storage/app/public/restaurant/' . $restaurantLogo) }}"
                             onerror="this.src='{{ asset('public/assets/admin/img/160x160/img2.jpg') }}'"
                             alt="{{ translate('logo') }}">
                    </a>
                </div>
                <div class="navbar-vertical-content text-capitalize">
                    <ul class="navbar-nav navbar-nav-lg nav-tabs">
                        <li class="navbar-vertical-aside-has-menu {{ Request::is('stocks') || Request::is('stocks/') ? 'show' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link" href="{{ route('stocks.dashboard') }}">
                                <i class="tio-cube nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('Daily counts') }}
                                </span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </aside>
</div>
