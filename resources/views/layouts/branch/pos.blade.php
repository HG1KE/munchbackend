<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#E7032D">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>@yield('title', translate('POS'))</title>
    @php($icon = \App\Model\BusinessSetting::where(['key' => 'fav_icon'])->first()->value ?? '')
    <link rel="icon" type="image/x-icon" href="{{ asset('storage/app/public/restaurant/' . $icon) }}">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('public/assets/admin/css/munch-pos.css') }}?v=1.9">
    @stack('css_or_js')
</head>
<body class="munch-pos-body">
@yield('content')
@stack('script')
</body>
</html>
