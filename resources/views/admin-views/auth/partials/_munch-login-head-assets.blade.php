{{-- Lean login CSS: skip theme.minc619 (~620KB) and Google Fonts (extra RTT). --}}
@php($icon = \App\CentralLogics\Helpers::get_business_settings('fav_icon') ?: '')
<link rel="shortcut icon" href="">
<link rel="icon" type="image/x-icon" href="{{ asset('storage/app/public/restaurant/' . $icon) }}">

<link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/vendor.min.css">
<link rel="stylesheet" href="{{ asset('public/assets/admin') }}/vendor/icon-set/style.css">
<link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/style.css?v=1.2">
<link rel="stylesheet" href="{{ asset('public/assets/admin') }}/css/toastr.css">

@include('admin-views.auth.partials._munch-login-styles')
