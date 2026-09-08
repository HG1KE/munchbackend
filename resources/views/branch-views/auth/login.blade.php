<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{{ translate('Branch') }} | {{ translate('Login') }}</title>

    @php($icon = \App\Model\BusinessSetting::where(['key' => 'fav_icon'])->first()?->value ?? '')
    <link rel="shortcut icon" href="">
    <link rel="icon" type="image/x-icon" href="{{ asset('storage/app/public/restaurant/' . $icon ?? '') }}">

    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/vendor.min.css">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/vendor/icon-set/style.css">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/theme.minc619.css?v=1.0">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/style.css">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/toastr.css">

    @include('admin-views.auth.partials._munch-login-styles')
</head>

<body class="munch-auth-login">
<main id="content" role="main" class="main">
    <div class="auth-wrapper" style="display:flex;flex-wrap:wrap;width:100%;">
        <div class="auth-wrapper-left" aria-hidden="true" style="display:none !important;"></div>

        <aside class="munch-auth-brand" aria-label="{{ translate('Branch') }}">
            <div class="munch-auth-brand__inner">
                <img class="munch-auth-brand__logo" src="{{ $logo }}" alt="{{ translate('logo') }}">
                <h1 class="munch-auth-brand__title">{{ translate('Manage Branch Operations') }}</h1>
                <p class="munch-auth-brand__text">{{ translate('Orders, kitchen flow and branch performance in one dashboard.') }}</p>
            </div>
        </aside>

        <div class="auth-wrapper-right">
            <div class="auth-wrapper-form">
                <div class="munch-auth-mobile-logo">
                    <img src="{{ $logo }}" alt="{{ translate('logo') }}">
                </div>

                <form id="form-id" action="{{ route('branch.auth.login') }}" method="post" novalidate>
                    @csrf

                    <div class="auth-header">
                        <div class="mb-0">
                            <h2 class="title">{{ translate('sign_in') }}</h2>
                            <div class="text-capitalize">{{ translate('welcome_back') }}</div>
                            <span class="badge d-inline-block">{{ translate('branch_sign_in') }}</span>
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
                        <label class="input-label" for="signupSrPassword">
                            {{ translate('password') }}
                        </label>
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

                    <div class="form-group mb-3">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="termsCheckbox"
                                name="remember" {{ old('remember') ? 'checked' : '' }}>
                            <label class="custom-control-label" for="termsCheckbox">
                                {{ translate('remember_me') }}
                            </label>
                        </div>
                    </div>

                    @php($recaptcha = \App\CentralLogics\Helpers::get_business_settings('recaptcha'))
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
                                    <img src="{{ URL('/branch/auth/code/captcha/1') }}" class="input-field default-recaptcha" id="default_recaptcha_id" alt="">
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
                                    <img src="{{ URL('/branch/auth/code/captcha/1') }}" class="input-field default-recaptcha" id="default_recaptcha_id" alt="">
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
                        <p class="munch-auth-alt-login__label mb-2">{{ translate('want_to_login_your_admin') }}?</p>
                        <a href="{{ route('admin.auth.login') }}" class="munch-auth-alt-login__btn">
                            {{ translate('admin_login') }}
                        </a>
                    </div>
                </form>

                @if(config('app.mode')=='demo')
                    <div class="border-top mt-4 pt-4" style="border-color:#e2e8f0 !important;">
                        <div class="row align-items-center">
                            <div class="col-10 text-left">
                                <span class="d-block small text-muted">{{ translate('Email : mainb@mainb.com') }}</span>
                                <span class="d-block small text-muted">{{ translate('Password : 12345678') }}</span>
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
    </div>
</main>

<script src="{{ asset('public/assets/admin') }}/js/vendor.min.js"></script>
<script src="{{ asset('public/assets/admin') }}/js/theme.min.js"></script>
<script src="{{ asset('public/assets/admin') }}/js/toastr.js"></script>
{!! Toastr::message() !!}

@if ($errors->any())
    <script>
        "use strict";
        @foreach($errors->all() as $error)
        toastr.error('{{ $error }}', Error, {
            CloseButton: true,
            ProgressBar: true
        });
        @endforeach
    </script>
@endif

<script>
    "use strict";

    $(document).on('ready', function () {
        $('.js-toggle-password').each(function () {
            new HSTogglePassword(this).init();
        });

        $('.js-validate').each(function () {
            $.HSCore.components.HSValidation.init($(this));
        });

        $(".re-captcha").click(function() {
            re_captcha();
        });

        $(".copy-cred").click(function() {
            copy_cred();
        });

        var $form = $('#form-id');
        var $btn = $('#signInBtn');

        $form.on('submit', function () {
            if ($btn.hasClass('is-loading')) {
                return;
            }
            $btn.addClass('is-loading');
            $btn.prop('disabled', true);
        });
    });
</script>

@if(isset($recaptcha) && $recaptcha['status'] == 1)
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
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
                toastr.error('Invalid recaptcha key provided. Please check the recaptcha configuration.');

                $('#reload-captcha').removeClass('d-none');
                $('#set_default_captcha_value').val('1');

                $('#signInBtn').removeClass('is-loading').prop('disabled', false);
                return;
            }

            grecaptcha.ready(function () {
                grecaptcha.execute('{{ $recaptcha['site_key'] }}', {action: 'submit'}).then(function (token) {
                    document.getElementById('g-recaptcha-response').value = token;
                    document.querySelector('form').submit();
                });
            });

            window.onerror = function(message) {
                var errorMessage = 'An unexpected error occurred. Please check the recaptcha configuration';
                if (message.includes('Invalid site key')) {
                    errorMessage = 'Invalid site key provided. Please check the recaptcha configuration.';
                } else if (message.includes('not loaded in api.js')) {
                    errorMessage = 'reCAPTCHA API could not be loaded. Please check the recaptcha API configuration.';
                }

                $('#reload-captcha').removeClass('d-none');
                $('#set_default_captcha_value').val('1');

                $('#signInBtn').removeClass('is-loading').prop('disabled', false);
                toastr.error(errorMessage);
                return true;
            };
        });
    </script>
@endif

<script>
    "use strict";

    function re_captcha() {
        let $url = "{{ URL('/branch/auth/code/captcha') }}";
        $url = $url + "/" + Math.random();
        document.getElementById('default_recaptcha_id').src = $url;
    }
</script>

@if(config('app.mode')=='demo')
    <script>
        "use strict";

        function copy_cred() {
            $('#signinSrEmail').val('mainb@mainb.com');
            $('#signupSrPassword').val('12345678');
            toastr.success('{{\App\CentralLogics\translate("Copied successfully!")}}', 'Success!', {
                CloseButton: true,
                ProgressBar: true
            });
        }
    </script>
@endif

<script>
    if (/MSIE \d|Trident.*rv:/.test(navigator.userAgent)) document.write('<script src="{{ asset('public/assets/admin') }}/vendor/babel-polyfill/polyfill.min.js"><\/script>');
</script>
</body>
</html>
