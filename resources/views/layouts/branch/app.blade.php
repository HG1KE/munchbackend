<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    @include('layouts.partials._munch-theme-boot')
    <title>@yield('title')</title>
    @php($icon = \App\CentralLogics\Helpers::get_business_settings('fav_icon') ?: '')
    <link rel="shortcut icon" href="">
    <link rel="icon" type="image/x-icon" href="{{ asset('storage/app/public/restaurant/' . $icon ?? '') }}">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{asset('public/assets/admin')}}/css/vendor.min.css">
    <link rel="stylesheet" href="{{asset('public/assets/admin')}}/vendor/icon-set/style.css">
    <link rel="stylesheet" href="{{asset('public/assets/admin')}}/css/theme.minc619.css?v=1.2">
    <link rel="stylesheet" href="{{asset('public/assets/admin')}}/css/style.css?v=1.2">
    <link rel="stylesheet" href="{{asset('public/assets/admin')}}/css/munch-admin-shell.css?v=1.8">
    <link rel="stylesheet" href="{{asset('public/assets/admin')}}/css/munch-admin-theme.css?v=1.0">
    <link rel="stylesheet" href="{{asset('public/assets/admin')}}/css/munch-admin-dark.css?v=1.0">
    @stack('css_or_js')
    <link rel="stylesheet" href="{{asset('public/assets/admin')}}/css/munch-new-order-modal.css?v=1.0">

    <script
        src="{{asset('public/assets/admin')}}/vendor/hs-navbar-vertical-aside/hs-navbar-vertical-aside-mini-cache.js"></script>
    <link rel="stylesheet" href="{{asset('public/assets/admin')}}/css/toastr.css">
</head>

<body class="footer-offset munch-admin-shell">
<script>document.body.setAttribute('data-munch-theme', document.documentElement.getAttribute('data-munch-theme') || 'light');</script>
    <div class="direction-toggle">
        <i class="tio-settings"></i>
        <span></span>
    </div>

{{-- Global AJAX loader — Munch shell spinner --}}
<div id="loading" class="munch-admin-loading" style="display: none;" aria-hidden="true">
    <div class="munch-admin-loading__backdrop" aria-hidden="true"></div>
    <div class="munch-admin-loading__panel" role="status" aria-live="polite">
        <span class="munch-admin-loading__spinner" aria-hidden="true"></span>
    </div>
</div>

@include('layouts.branch.partials._front-settings')

@include('layouts.branch.partials._header')
@include('layouts.branch.partials._sidebar')

<main id="content" role="main" class="main pointer-event">
@yield('content')

@include('layouts.branch.partials._footer')

    @include('layouts.partials._new-order-ring-modal', ['ctaMode' => 'branch'])

</main>

<script src="{{asset('public/assets/admin')}}/js/custom.js"></script>
<script src="{{asset('public/assets/admin/js/munch-live-search.js')}}"></script>

@stack('script')

<script src="{{asset('public/assets/admin')}}/js/vendor.min.js"></script>
<script src="{{asset('public/assets/admin')}}/js/theme.min.js"></script>
<script src="{{asset('public/assets/admin')}}/js/sweet_alert.js"></script>
<script src="{{asset('public/assets/admin')}}/js/toastr.js"></script>
<script src="{{asset('public/assets/admin')}}/js/munch-admin-theme.js?v=1.0"></script>
<script src="{{asset('public/assets/admin')}}/js/munch-theme.js?v=1.0"></script>
@php($munchBranchOrderNotificationType = \App\CentralLogics\Helpers::get_business_settings('admin_order_notification_type'))
@if($munchBranchOrderNotificationType == 'firebase')
<script src="{{asset('public/assets/admin/js/firebase.min.js')}}"></script>
@endif

{!! Toastr::message() !!}

@if ($errors->any())
    <script>
        @foreach($errors->all() as $error)
        toastr.error('{{$error}}', Error, {
            CloseButton: true,
            ProgressBar: true
        });
        @endforeach
    </script>
@endif
<script>
    $(document).on('ready', function(){

        $(".direction-toggle").on("click", function () {
            setDirection(localStorage.getItem("direction"));
        });

        function setDirection(direction) {
            if (direction == "rtl") {
                localStorage.setItem("direction", "ltr");
                $("html").attr('dir', 'ltr');
            $(".direction-toggle").find('span').text('Toggle RTL')
            } else {
                localStorage.setItem("direction", "rtl");
                $("html").attr('dir', 'rtl');
            $(".direction-toggle").find('span').text('Toggle LTR')
            }
        }

        if (localStorage.getItem("direction") == "rtl") {
            $("html").attr('dir', "rtl");
            $(".direction-toggle").find('span').text('Toggle LTR')
        } else {
            $("html").attr('dir', "ltr");
            $(".direction-toggle").find('span').text('Toggle RTL')
        }

    })

    $(document).on('ready', function () {

        $('.js-navbar-vertical-aside-toggle-invoker').click(function () {
            $('.js-navbar-vertical-aside-toggle-invoker i').tooltip('hide');
        });

        $('.js-hs-unfold-invoker').each(function () {
            var unfold = new HSUnfold($(this)).init();
        });

        var sidebar = $('.js-navbar-vertical-aside').hsSideNav();

    });
</script>

@stack('script_2')
<audio id="myAudio">
    <source src="{{asset('public/assets/admin/sound/notification.mp3')}}" type="audio/mpeg">
</audio>

<script>
    var audio = document.getElementById("myAudio");

    function playAudio() {
        audio.play();
    }

    function pauseAudio() {
        audio.pause();
    }

    $('.check-order').on('click', function (){
        location.href = '{{route('branch.orders.online')}}';
    })

    $('.route-alert').on('click', function (){
        let route = $(this).data('route');
        let message = $(this).data('message');
        route_alert(route, message)
    });

    function route_alert(route, message) {
        Swal.fire({
            title: '{{ translate('Are you sure?') }}',
            text: message,
            type: 'warning',
            showCancelButton: true,
            cancelButtonColor: 'default',
            confirmButtonColor: '#E7032D',
            cancelButtonText: '{{ translate('No') }}',
            confirmButtonText: '{{ translate('Yes') }}',
            reverseButtons: true
        }).then((result) => {
            if (result.value) {
                location.href = route;
            }
        })
    }

    $('.form-alert').on('click', function (){
        let id = $(this).data('id');
        let message = $(this).data('message');
        form_alert(id, message)
    });

    function form_alert(id, message) {
        Swal.fire({
            title: '{{ translate('Are you sure?') }}',
            text: message,
            type: 'warning',
            showCancelButton: true,
            cancelButtonColor: 'default',
            confirmButtonColor: '#E7032D',
            cancelButtonText: '{{ translate('No') }}',
            confirmButtonText: '{{ translate('Yes') }}',
            reverseButtons: true
        }).then((result) => {
            if (result.value) {
                $('#'+id).submit()
            }
        })
    }


    function call_demo(){
        toastr.info('{{ translate('Update option is disabled for demo!') }}', {
            CloseButton: true,
            ProgressBar: true
        });
    }

    $('.call-demo').click(function() {
        if ('{{ config('app.mode') }}' === 'demo') {
            call_demo();
        }
    });

        $(document).on('ready', function () {

            $('.js-toggle-password').each(function () {
                new HSTogglePassword(this).init()
            });

            $('.js-validate').each(function () {
                $.HSCore.components.HSValidation.init($(this));
            });
        });
    </script>

    <script>
        @php($admin_order_notification = \App\CentralLogics\Helpers::get_business_settings('admin_order_notification'))
        @php($admin_order_notification_type = \App\CentralLogics\Helpers::get_business_settings('admin_order_notification_type'))
        @php($branchAcceptsOnlineOrders = \App\Support\BranchOnlineOrdering::isEnabled(auth('branch')->user()))

        @if($branchAcceptsOnlineOrders && $admin_order_notification)
            var munchPendingAlertUrl = '{{ route('branch.get-restaurant-data') }}';

            function munchPlayPendingOrderAlert(data) {
                var count = Number(data && data.new_order ? data.new_order : 0);
                if (count > 0) {
                    playAudio();
                    $('#popup-modal').appendTo("body").modal('show');
                }
            }

            function munchFetchPendingOrderAlert() {
                $.get({
                    url: munchPendingAlertUrl,
                    dataType: 'json',
                    success: function (response) {
                        munchPlayPendingOrderAlert((response && response.data) ? response.data : {});
                    },
                });
            }

            @if($admin_order_notification_type == 'manual')
                var munchPendingAlertTimer = null;
                function munchStartPendingOrderAlertPoll() {
                    if (munchPendingAlertTimer !== null) {
                        return;
                    }
                    munchPendingAlertTimer = setInterval(function () {
                        if (document.visibilityState === 'hidden') {
                            return;
                        }
                        munchFetchPendingOrderAlert();
                    }, 10000);
                }
                function munchStopPendingOrderAlertPoll() {
                    if (munchPendingAlertTimer !== null) {
                        clearInterval(munchPendingAlertTimer);
                        munchPendingAlertTimer = null;
                    }
                }
                document.addEventListener('visibilitychange', function () {
                    if (document.visibilityState === 'hidden') {
                        munchStopPendingOrderAlertPoll();
                    } else {
                        munchFetchPendingOrderAlert();
                        munchStartPendingOrderAlertPoll();
                    }
                });
                munchStartPendingOrderAlertPoll();
            @endif

            @if($admin_order_notification_type == 'firebase')
                @php($fcm_credentials = \App\CentralLogics\Helpers::get_business_settings('fcm_credentials'))
                var firebaseConfig = {
                    apiKey: "{{isset($fcm_credentials['apiKey']) ? $fcm_credentials['apiKey'] : ''}}",
                    authDomain: "{{isset($fcm_credentials['authDomain']) ? $fcm_credentials['authDomain'] : ''}}",
                    projectId: "{{isset($fcm_credentials['projectId']) ? $fcm_credentials['projectId'] : ''}}",
                    storageBucket: "{{isset($fcm_credentials['storageBucket']) ? $fcm_credentials['storageBucket'] : ''}}",
                    messagingSenderId: "{{isset($fcm_credentials['messagingSenderId']) ? $fcm_credentials['messagingSenderId'] : ''}}",
                    appId: "{{isset($fcm_credentials['appId']) ? $fcm_credentials['appId'] : ''}}",
                    measurementId: "{{isset($fcm_credentials['measurementId']) ? $fcm_credentials['measurementId'] : ''}}"
                };


                firebase.initializeApp(firebaseConfig);
                const messaging = firebase.messaging();

                function startFCM() {
                    messaging
                        .requestPermission()
                        .then(function() {
                            return messaging.getToken();
                        })
                        .then(function(token) {
                            subscribeTokenToBackend(token, 'branch-order-{{ auth('branch')->id() }}-message');
                        }).catch(function(error) {
                        console.error('Error getting permission or token:', error);
                    });
                }

                function subscribeTokenToBackend(token, topic) {
                    fetch('{{url('/')}}/subscribeToTopic', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({ token: token, topic: topic })
                    }).then(response => {
                        if (response.status < 200 || response.status >= 400) {
                            return response.text().then(text => {
                                throw new Error(`Error subscribing to topic: ${response.status} - ${text}`);
                            });
                        }
                        console.log(`Subscribed to "${topic}"`);
                    }).catch(error => {
                        console.error('Subscription error:', error);
                    });
                }

                messaging.onMessage(function(payload) {
                    if (payload.data && payload.data.order_id && payload.data.type == "order_request") {
                        munchFetchPendingOrderAlert();
                    }
                });

                startFCM();
            @endif
        @endif

    </script>


<script>
    if (/MSIE \d|Trident.*rv:/.test(navigator.userAgent)) document.write('<script src="{{asset('public/assets/admin')}}/vendor/babel-polyfill/polyfill.min.js"><\/script>');
</script>
</body>
</html>
