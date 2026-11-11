{{--
  Shared portal login panel (chooser + credentials).
  Requires: $pageMode (admin|branch|stocks), $logo
  Keep Blade php assignments as one-liners only — multi-line php blocks are uncompiled in this app.
--}}
@php($pageMode = $pageMode ?? 'admin')
@php($showLoginForm = $errors->any() || filled(old('email')) || filled(old('login_panel')) || request()->boolean('form'))
@php($activeMode = old('login_panel', $pageMode))
@php($formAction = $activeMode === 'branch' ? route('branch.auth.login') : ($activeMode === 'stocks' ? route('stocks.auth.login') : route('admin.auth.login')))
@php($modeBadge = $activeMode === 'branch' ? translate('branch_sign_in') : ($activeMode === 'stocks' ? translate('stocks_manager_sign_in') : translate('admin_or_employee_sign_in')))
@php($demoEmailLabel = $activeMode === 'branch' ? translate('Email : mainb@mainb.com') : translate('Email : admin@admin.com'))
@php($chooserStyle = $showLoginForm ? 'display:none' : 'display:block')
@php($formStyle = $showLoginForm ? 'display:block' : 'display:none')

<div class="munch-auth-mobile-logo">
    <img src="{{ $logo }}" alt="{{ translate('logo') }}">
</div>

<div
    data-munch-login-root
    data-munch-login-initial="{{ $activeMode }}"
    data-munch-login-show-form="{{ $showLoginForm ? '1' : '0' }}"
>
    <div id="munch-auth-chooser-wrap" style="{{ $chooserStyle }}">
        @include('admin-views.auth.partials._munch-login-chooser')
    </div>

    <div id="munch-auth-form-wrap" style="{{ $formStyle }}">
        <form id="form-id" action="{{ $formAction }}" method="post" novalidate>
            @csrf
            <input type="hidden" name="login_panel" id="login_panel" value="{{ $showLoginForm ? $activeMode : '' }}">

            <div class="auth-header">
                <div class="mb-0">
                    <button type="button" class="munch-auth-change-mode" data-munch-login-change>
                        &larr; {{ translate('Change login type') }}
                    </button>
                    <h2 class="title">{{ translate('sign_in') }}</h2>
                    <div class="text-capitalize">{{ translate('welcome_back') }}</div>
                    <span class="badge d-inline-block" id="munch-auth-mode-badge">{{ $modeBadge }}</span>
                </div>
            </div>

            @if ($errors->any())
                <div class="alert alert-danger munch-auth-errors" role="alert">
                    <ul class="mb-0 pl-3">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="js-form-message form-group">
                <label class="input-label text-capitalize" for="signinSrEmail">{{ translate('your') }} {{ translate('email') }}</label>
                <input type="email" class="form-control form-control-lg" name="email" id="signinSrEmail"
                    tabindex="1" placeholder="{{ translate('email@address.com') }}" aria-label="email@address.com"
                    value="{{ old('email') }}"
                    required data-msg="{{ translate('Please enter a valid email address') }}">
            </div>

            <div class="js-form-message form-group">
                <label class="input-label" for="signupSrPassword">{{ translate('password') }}</label>
                <div class="input-group input-group-merge">
                    <input type="password" class="js-toggle-password form-control form-control-lg"
                        name="password" id="signupSrPassword" placeholder="{{ translate('8+ characters required') }}"
                        aria-label="8+ characters required" required
                        data-msg="{{ translate('Your password is invalid. Please try again.') }}"
                        data-hs-toggle-password-options='{
                            "target": "#changePassTarget",
                            "defaultClass": "tio-hidden-outlined",
                            "showClass": "tio-visible-outlined",
                            "classChangeTarget": "#changePassIcon"
                        }'>
                    <div id="changePassTarget" class="input-group-append">
                        <a class="input-group-text" href="javascript:" role="button" aria-label="{{ translate('Toggle password visibility') }}">
                            <i id="changePassIcon" class="tio-visible-outlined" aria-hidden="true"></i>
                        </a>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-lg btn-block btn-primary" id="signInBtn">
                <span class="munch-auth-spinner" aria-hidden="true"></span>
                <span class="munch-auth-btn-label">{{ translate('sign_in') }}</span>
            </button>

            <div class="munch-auth-alt-login">
                <p class="munch-auth-alt-login__label mb-2">{{ translate('Choose your login') }}</p>
                <button type="button" class="munch-auth-alt-login__btn munch-auth-chooser__btn--admin mb-2" data-munch-login-mode="admin" data-munch-login-action="{{ route('admin.auth.login') }}" data-munch-login-badge="{{ translate('admin_or_employee_sign_in') }}">
                    {{ translate('Admin Login') }}
                </button>
                <button type="button" class="munch-auth-alt-login__btn munch-auth-chooser__btn--branch mb-2" data-munch-login-mode="branch" data-munch-login-action="{{ route('branch.auth.login') }}" data-munch-login-badge="{{ translate('branch_sign_in') }}">
                    {{ translate('Branch Login') }}
                </button>
                <button type="button" class="munch-auth-alt-login__btn munch-auth-chooser__btn--stocks" data-munch-login-mode="stocks" data-munch-login-action="{{ route('stocks.auth.login') }}" data-munch-login-badge="{{ translate('stocks_manager_sign_in') }}">
                    {{ translate('Stocks Login') }}
                </button>
            </div>
        </form>

        @if(config('app.mode') == 'demo')
            <div class="border-top mt-4 pt-4" style="border-color:#e2e8f0 !important;">
                <div class="row align-items-center">
                    <div class="col-10 text-left">
                        <span class="d-block small text-muted" id="munch-auth-demo-email">{{ $demoEmailLabel }}</span>
                        <span class="d-block small text-muted" id="munch-auth-demo-password">{{ translate('Password : 12345678') }}</span>
                    </div>
                    <div class="col-2 text-right">
                        <button type="button" class="btn btn-primary btn-sm copy-cred px-2" aria-label="{{ translate('Copy') }}">
                            <i class="tio-copy" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
