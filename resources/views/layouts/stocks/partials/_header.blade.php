<header id="header" class="navbar navbar-expand-lg navbar-fixed navbar-height navbar-flush navbar-container navbar-bordered">
    <div class="navbar-nav-wrap">
        <div class="navbar-brand-wrapper">
            @php($restaurantLogo = \App\Model\BusinessSetting::where(['key' => 'logo'])->first()->value ?? '')
            <a class="navbar-brand" href="{{ route('stocks.dashboard') }}">
                <img class="navbar-brand-logo" style="object-fit: contain;"
                     src="{{ asset('storage/app/public/restaurant/' . $restaurantLogo) }}"
                     onerror="this.src='{{ asset('public/assets/admin/img/160x160/img1.jpg') }}'"
                     alt="{{ translate('logo') }}">
            </a>
        </div>
        <div class="navbar-nav-wrap-content-right ml-auto">
            <ul class="navbar-nav align-items-center flex-row">
                <li class="nav-item">
                    <span class="nav-link text-dark font-weight-bold">
                        {{ auth('stocks')->user()->name ?? '' }}
                        @if(auth('stocks')->user()?->branch)
                            <span class="text-muted font-weight-normal">— {{ auth('stocks')->user()->branch->name }}</span>
                        @endif
                    </span>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('stocks.auth.logout') }}">{{ translate('logout') }}</a>
                </li>
            </ul>
        </div>
    </div>
</header>
