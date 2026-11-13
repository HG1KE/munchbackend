<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title id="munch-auth-page-title">{{ translate('Stocks') }} | {{ translate('Login') }}</title>

    @include('admin-views.auth.partials._munch-login-head-assets')
</head>

<body class="munch-auth-login">
<main id="content" role="main" class="main">
    <div class="auth-wrapper">
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
                @include('admin-views.auth.partials._munch-login-panel', ['pageMode' => 'stocks', 'logo' => $logo])
            </div>
        </div>
    </div>
</main>

@include('admin-views.auth.partials._munch-login-scripts')
</body>
</html>
