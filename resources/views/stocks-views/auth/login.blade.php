<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{{ translate('Stocks') }} | {{ translate('Login') }}</title>

    @php($icon = \App\Model\BusinessSetting::where(['key' => 'fav_icon'])->first()?->value ?? '')
    <link rel="shortcut icon" href="">
    <link rel="icon" type="image/x-icon" href="{{ asset('storage/app/public/restaurant/' . $icon ?? '') }}">

    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/vendor.min.css">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/vendor/icon-set/style.css">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/theme.minc619.css?v=1.2">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/style.css?v=1.2">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/toastr.css">

    @include('admin-views.auth.partials._munch-login-styles')
</head>

<body class="munch-auth-login">
<main id="content" role="main" class="main">
    <div class="auth-wrapper" style="display:flex;flex-wrap:wrap;width:100%;">
        <div class="auth-wrapper-left" aria-hidden="true" style="display:none !important;"></div>

        <aside class="munch-auth-brand" aria-label="{{ translate('Stocks') }}">
            <div class="munch-auth-brand__inner">
                <img class="munch-auth-brand__logo" src="{{ $logo }}" alt="{{ translate('logo') }}">
                <h1 class="munch-auth-brand__title">{{ translate('Stock Control') }}</h1>
                <p class="munch-auth-brand__text">{{ translate('Daily opening and closing counts for your branch.') }}</p>
            </div>
        </aside>

        <div class="auth-wrapper-right">
            <div class="auth-wrapper-form">
                <div class="munch-auth-mobile-logo">
                    <img src="{{ $logo }}" alt="{{ translate('logo') }}">
                </div>

                @php($showLoginForm = $errors->any() || filled(old('email')) || request()->boolean('form'))
                @php($recaptcha = \App\CentralLogics\Helpers::get_business_settings('recaptcha'))

                @unless($showLoginForm)
                    @include('admin-views.auth.partials._munch-login-chooser', ['currentMode' => 'stocks'])
                @else
                <form id="form-id" action="{{ route('stocks.auth.login') }}" method="post" novalidate>
                    @csrf

                    <div class="auth-header">
                        <div class="mb-0">
                            <a href="{{ route('stocks.auth.login') }}" class="munch-auth-change-mode">&larr; {{ translate('Change login type') }}</a>
                            <h2 class="title">{{ translate('sign_in') }}</h2>
                            <div class="text-capitalize">{{ translate('welcome_back') }}</div>
                            <span class="badge d-inline-block">{{ translate('stocks_manager_sign_in') }}</span>
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
                            tabindex="1" placeholder="{{ translate('email@address.com') }}"
                            value="{{ old('email') }}" required>
                    </div>

                    <div class="js-form-message form-group">
                        <label class="input-label" for="signupSrPassword">{{ translate('password') }}</label>
                        <div class="input-group input-group-merge">
                            <input type="password" class="js-toggle-password form-control form-control-lg"
                                name="password" id="signupSrPassword" placeholder="{{ translate('8+ characters required') }}"
                                required
                                data-hs-toggle-password-options='{
                                    "target": "#changePassTarget",
                                    "defaultClass": "tio-hidden-outlined",
                                    "showClass": "tio-visible-outlined",
                                    "classChangeTarget": "#changePassIcon"
                                }'>
                            <div id="changePassTarget" class="input-group-append">
                                <a class="input-group-text" href="javascript:" role="button">
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
                                <input type="text" class="form-control form-control-lg" name="default_captcha_value" value=""
                                    placeholder="{{ translate('Enter captcha value') }}" autocomplete="off">
                            </div>
                            <div class="col-7 input-icons bg-white rounded">
                                <a class="re-captcha" href="javascript:">
                                    <img src="{{ URL('/stocks/auth/code/captcha/1') }}" class="input-field" id="default_recaptcha_id" alt="">
                                    <i class="tio-refresh icon" aria-hidden="true"></i>
                                </a>
                            </div>
                        </div>
                    @else
                        <div class="row p-2 mb-2">
                            <div class="col-5 pr-0">
                                <input type="text" class="form-control form-control-lg" name="default_captcha_value" value=""
                                    placeholder="{{ translate('Enter captcha value') }}" autocomplete="off">
                            </div>
                            <div class="col-7 input-icons bg-white rounded">
                                <a class="re-captcha" href="javascript:">
                                    <img src="{{ URL('/stocks/auth/code/captcha/1') }}" class="input-field" id="default_recaptcha_id" alt="">
                                    <i class="tio-refresh icon" aria-hidden="true"></i>
                                </a>
                            </div>
                        </div>
                    @endif

                    <button type="submit" class="btn btn-lg btn-block btn-primary" id="signInBtn">
                        <span class="munch-auth-btn-label">{{ translate('sign_in') }}</span>
                    </button>

                    <div class="munch-auth-alt-login">
                        <p class="munch-auth-alt-login__label mb-2">{{ translate('Choose your login') }}</p>
                        <a href="{{ route('admin.auth.login', ['form' => 1]) }}" class="munch-auth-alt-login__btn mb-2 d-block">
                            {{ translate('Admin Login') }}
                        </a>
                        <a href="{{ route('branch.auth.login', ['form' => 1]) }}" class="munch-auth-alt-login__btn d-block">
                            {{ translate('Branch Login') }}
                        </a>
                    </div>
                </form>
                @endunless
            </div>
        </div>
    </div>
</main>

<script src="{{ asset('public/assets/admin') }}/js/vendor.min.js"></script>
<script src="{{ asset('public/assets/admin') }}/js/theme.min.js"></script>
<script src="{{ asset('public/assets/admin') }}/js/toastr.js"></script>
{!! Toastr::message() !!}

<script>
    "use strict";
    function re_captcha() {
        let $url = "{{ URL('/stocks/auth/code/captcha') }}";
        $url = $url + "/" + Math.random();
        document.getElementById('default_recaptcha_id').src = $url;
    }
    document.querySelectorAll('.re-captcha').forEach(function (el) {
        el.addEventListener('click', re_captcha);
    });
</script>

@if(isset($recaptcha) && $recaptcha['status'] == 1)
    <script src="https://www.google.com/recaptcha/api.js?render={{ $recaptcha['site_key'] }}"></script>
    <script>
        "use strict";
        $('#signInBtn').click(function (e) {
            if ($('#set_default_captcha_value').val() == 1) {
                $('#form-id').submit();
                return true;
            }
            e.preventDefault();
            if (typeof grecaptcha === 'undefined') {
                $('#reload-captcha').removeClass('d-none');
                $('#set_default_captcha_value').val('1');
                return;
            }
            grecaptcha.ready(function () {
                grecaptcha.execute('{{ $recaptcha['site_key'] }}', {action: 'submit'}).then(function (token) {
                    document.getElementById('g-recaptcha-response').value = token;
                    document.querySelector('form').submit();
                });
            });
        });
    </script>
@endif
</body>
</html>
