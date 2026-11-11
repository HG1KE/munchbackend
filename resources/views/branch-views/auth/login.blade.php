<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title id="munch-auth-page-title">{{ translate('Branch') }} | {{ translate('Login') }}</title>

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

        <aside class="munch-auth-brand" aria-label="{{ translate('Branch') }}">
            <div class="munch-auth-brand__inner">
                <img class="munch-auth-brand__logo" src="{{ $logo }}" alt="{{ translate('logo') }}">
                <h1 class="munch-auth-brand__title">{{ translate('Manage Branch Operations') }}</h1>
                <p class="munch-auth-brand__text">{{ translate('Orders, kitchen flow and branch performance in one dashboard.') }}</p>
            </div>
        </aside>

        <div class="auth-wrapper-right">
            <div class="auth-wrapper-form">
                @include('admin-views.auth.partials._munch-login-panel', ['pageMode' => 'branch', 'logo' => $logo])
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

@include('admin-views.auth.partials._munch-login-mode-script')

<script>
    "use strict";

    $(document).on('ready', function () {
        $('.js-toggle-password').each(function () {
            new HSTogglePassword(this).init();
        });

        $('.js-validate').each(function () {
            $.HSCore.components.HSValidation.init($(this));
        });

        $(".copy-cred").click(function() {
            if (typeof window.munchLoginCopyCred === 'function') {
                window.munchLoginCopyCred();
            }
            toastr.success('{{\App\CentralLogics\translate("Copied successfully!")}}', 'Success!', {
                CloseButton: true,
                ProgressBar: true
            });
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

<script>
    if (/MSIE \d|Trident.*rv:/.test(navigator.userAgent)) document.write('<script src="{{ asset('public/assets/admin') }}/vendor/babel-polyfill/polyfill.min.js"><\/script>');
</script>
</body>
</html>
