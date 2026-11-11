<header id="header" class="navbar navbar-expand-lg navbar-fixed navbar-height navbar-flush navbar-container navbar-bordered">
    <div class="navbar-nav-wrap">
        <div class="navbar-brand-wrapper d-flex align-items-center">
            @php($restaurantLogo = \App\Model\BusinessSetting::where(['key' => 'logo'])->first()->value ?? '')
            <a class="navbar-brand mb-0" href="{{ route('stocks.dashboard') }}">
                <img class="navbar-brand-logo" style="object-fit: contain;"
                     src="{{ asset('storage/app/public/restaurant/' . $restaurantLogo) }}"
                     onerror="this.src='{{ asset('public/assets/admin/img/160x160/img1.jpg') }}'"
                     alt="{{ translate('logo') }}">
            </a>
            <span class="munch-stocks-panel-badge">{{ translate('Stocks') }}</span>
        </div>

        <div class="navbar-nav-wrap-content-left d-xl-none">
            <button type="button" class="js-navbar-vertical-aside-toggle-invoker close mr-2">
                <i class="tio-first-page navbar-vertical-aside-toggle-short-align" data-toggle="tooltip"
                   data-placement="right" title="{{ translate('Menu') }}"></i>
                <i class="tio-last-page navbar-vertical-aside-toggle-full-align"
                   data-toggle="tooltip" data-placement="right" title="{{ translate('Menu') }}"></i>
            </button>
        </div>

        <div class="navbar-nav-wrap-content-right ml-auto">
            <ul class="navbar-nav align-items-center flex-row">
                <li class="nav-item">
                    @php($stocksUser = auth('stocks')->user())
                    <div class="munch-stocks-user">
                        <div class="munch-stocks-user__meta">
                            <span class="munch-stocks-user__name">{{ $stocksUser->name ?? '' }}</span>
                            @if($stocksUser)
                                <span class="munch-stocks-user__scope">{{ $stocksUser->branchLabel() }}</span>
                            @endif
                        </div>
                        <a class="munch-stocks-logout" href="{{ route('stocks.auth.logout') }}">
                            {{ translate('logout') }}
                        </a>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</header>
