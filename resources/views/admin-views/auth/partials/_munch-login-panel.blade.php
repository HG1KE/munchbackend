{{--
  Shared portal login panel (chooser + credentials).
  Requires: $pageMode (admin|branch|stocks), $logo
  Keep Blade php assignments as one-liners only — multi-line php blocks are uncompiled in this app.
--}}
@php($pageMode = $pageMode ?? 'admin')
@php($showLoginForm = $errors->any() || filled(old('email')) || filled(old('login_panel')) || request()->boolean('form'))
@php($activeMode = old('login_panel', $pageMode))
@php($recaptcha = \App\CentralLogics\Helpers::get_business_settings('recaptcha'))
@php($formAction = $activeMode === 'branch' ? route('branch.auth.login') : ($activeMode === 'stocks' ? route('stocks.auth.login') : route('admin.auth.login')))
@php($captchaSrc = $activeMode === 'branch' ? url('/branch/auth/code/captcha/1') : ($activeMode === 'stocks' ? url('/stocks/auth/code/captcha/1') : url('/admin/auth/code/captcha/1')))
@php($modeBadge = $activeMode === 'branch' ? translate('branch_sign_in') : ($activeMode === 'stocks' ? translate('stocks_manager_sign_in') : translate('admin_or_employee_sign_in')))
@php($demoEmailLabel = $activeMode === 'branch' ? translate('Email : mainb@mainb.com') : translate('Email : admin@admin.com'))

<div class="munch-auth-mobile-logo">
    <img src="{{ $logo }}" alt="{{ translate('logo') }}">
</div>

<div
    data-munch-login-root
    data-munch-login-initial="{{ $activeMode }}"
    data-munch-login-show-form="{{ $showLoginForm ? '1' : '0' }}"
>
    <div id="munch-auth-chooser-wrap" @if($showLoginForm) hidden @endif>
        @include('admin-views.auth.partials._munch-login-chooser')
    </div>

    <div id="munch-auth-form-wrap" @unless($showLoginForm) hidden @endunless>
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

            @if(isset($recaptcha) && $recaptcha['status'] == 1)
                <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
                <input type="hidden" name="set_default_captcha" id="set_default_captcha_value" value="0">

                <div class="row p-2 d-none" id="reload-captcha">
                    <div class="col-5 pr-0">
                        <input type="text" class="form-control form-control-lg default-captcha-value" name="default_captcha_value" value=""
                            placeholder="{{ translate('Enter captcha value') }}" autocomplete="off">
                    </div>
                    <div class="col-7 input-icons bg-white rounded">
                        <a class="re-captcha" href="javascript:">
                            <img src="{{ $captchaSrc }}" class="input-field default-recaptcha" id="default_recaptcha_id" alt="">
                            <i class="tio-refresh icon" aria-hidden="true"></i>
                        </a>
                    </div>
                </div>
            @else
                <div class="row p-2 mb-2">
                    <div class="col-5 pr-0">
                        <input type="text" class="form-control form-control-lg default-captcha-value" name="default_captcha_value" value=""
                            placeholder="{{ translate('Enter captcha value') }}" autocomplete="off">
                    </div>
                    <div class="col-7 input-icons bg-white rounded">
                        <a class="re-captcha" href="javascript:">
                            <img src="{{ $captchaSrc }}" class="input-field default-recaptcha" id="default_recaptcha_id" alt="">
                            <i class="tio-refresh icon" aria-hidden="true"></i>
                        </a>
                    </div>
                </div>
            @endif

            <button type="submit" class="btn btn-lg btn-block btn-primary" id="signInBtn">
                <span class="munch-auth-spinner" aria-hidden="true"></span>
                <span class="munch-auth-btn-label">{{ translate('sign_in') }}</span>
            </button>

            <div class="munch-auth-alt-login">
                <p class="munch-auth-alt-login__label mb-2">{{ translate('Choose your login') }}</p>
                <button type="button" class="munch-auth-alt-login__btn munch-auth-chooser__btn--admin mb-2" data-munch-login-mode="admin">
                    {{ translate('Admin Login') }}
                </button>
                <button type="button" class="munch-auth-alt-login__btn munch-auth-chooser__btn--branch mb-2" data-munch-login-mode="branch">
                    {{ translate('Branch Login') }}
                </button>
                <button type="button" class="munch-auth-alt-login__btn munch-auth-chooser__btn--stocks" data-munch-login-mode="stocks">
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
