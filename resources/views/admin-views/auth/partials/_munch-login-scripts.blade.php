{{-- Lean login JS: jquery.min (~88KB) instead of the full admin vendor bundle. --}}
<script src="{{ asset('public/assets/admin') }}/js/jquery.min.js"></script>
<script src="{{ asset('public/assets/admin') }}/js/toastr.js"></script>
{!! Toastr::message() !!}

@if ($errors->any())
    <script>
        "use strict";
        @foreach($errors->all() as $error)
        toastr.error(@json($error), 'Error', {
            CloseButton: true,
            ProgressBar: true
        });
        @endforeach
    </script>
@endif

@include('admin-views.auth.partials._munch-login-mode-script')

<script>
    "use strict";

    $(function () {
        $('#changePassTarget a').on('click', function (e) {
            e.preventDefault();
            var $input = $('#signupSrPassword');
            var show = $input.attr('type') === 'password';
            $input.attr('type', show ? 'text' : 'password');
            $('#changePassIcon')
                .toggleClass('tio-visible-outlined', !show)
                .toggleClass('tio-hidden-outlined', show);
        });

        $('.copy-cred').on('click', function () {
            if (typeof window.munchLoginCopyCred === 'function') {
                window.munchLoginCopyCred();
            }
            toastr.success(@json(\App\CentralLogics\translate('Copied successfully!')), 'Success!', {
                CloseButton: true,
                ProgressBar: true
            });
        });

        var $form = $('#form-id');
        var $btn = $('#signInBtn');

        $form.on('submit', function () {
            if ($btn.hasClass('is-loading')) {
                return false;
            }
            $btn.addClass('is-loading');
            $btn.prop('disabled', true);
        });
    });
</script>
