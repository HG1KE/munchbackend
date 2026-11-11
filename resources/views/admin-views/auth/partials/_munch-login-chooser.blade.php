{{--
  Portal login mode chooser.
  $currentMode: admin|branch|stocks
--}}
@php
    $currentMode = $currentMode ?? 'admin';
@endphp

<div class="auth-header">
    <div class="mb-0">
        <h2 class="title">{{ translate('sign_in') }}</h2>
        <div class="text-capitalize">{{ translate('welcome_back') }}</div>
        <p class="munch-auth-chooser__hint mb-0 mt-2">{{ translate('Choose your login') }}</p>
    </div>
</div>

<nav class="munch-auth-chooser" aria-label="{{ translate('Choose your login') }}">
    <a
        href="{{ route('admin.auth.login', ['form' => 1]) }}"
        class="munch-auth-chooser__btn{{ $currentMode === 'admin' ? ' is-current' : '' }}"
    >
        {{ translate('Admin Login') }}
    </a>
    <a
        href="{{ route('branch.auth.login', ['form' => 1]) }}"
        class="munch-auth-chooser__btn{{ $currentMode === 'branch' ? ' is-current' : '' }}"
    >
        {{ translate('Branch Login') }}
    </a>
    <a
        href="{{ route('stocks.auth.login', ['form' => 1]) }}"
        class="munch-auth-chooser__btn{{ $currentMode === 'stocks' ? ' is-current' : '' }}"
    >
        {{ translate('Stocks Login') }}
    </a>
</nav>
