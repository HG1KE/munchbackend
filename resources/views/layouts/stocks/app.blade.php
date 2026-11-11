<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>@yield('title')</title>
    @php($icon = \App\CentralLogics\Helpers::get_business_settings('fav_icon') ?: '')
    <link rel="icon" type="image/x-icon" href="{{ asset('storage/app/public/restaurant/' . $icon) }}">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/vendor.min.css">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/vendor/icon-set/style.css">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/theme.minc619.css?v=1.2">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/style.css?v=1.2">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/munch-stocks-shell.css?v=1.0">
    <link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/toastr.css">
    @stack('css_or_js')
</head>
<body class="footer-offset munch-stocks-shell">
@include('layouts.stocks.partials._header')
@include('layouts.stocks.partials._sidebar')

<main id="content" role="main" class="main pointer-event">
    @yield('content')
    @include('layouts.stocks.partials._footer')
</main>

<script src="{{ asset('public/assets/admin') }}/js/vendor.min.js"></script>
<script src="{{ asset('public/assets/admin') }}/js/theme.min.js"></script>
<script src="{{ asset('public/assets/admin') }}/js/toastr.js"></script>
{!! Toastr::message() !!}
@stack('script')
</body>
</html>
