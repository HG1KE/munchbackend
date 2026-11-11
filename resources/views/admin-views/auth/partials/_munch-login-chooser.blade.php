{{-- Portal login mode chooser (JS reveals credentials; no navigation). --}}
<div class="auth-header">
    <div class="mb-0">
        <h2 class="title">{{ translate('sign_in') }}</h2>
        <div class="text-capitalize">{{ translate('welcome_back') }}</div>
        <p class="munch-auth-chooser__hint mb-0 mt-2">{{ translate('Choose your login') }}</p>
    </div>
</div>

<nav class="munch-auth-chooser" aria-label="{{ translate('Choose your login') }}">
    <button type="button" class="munch-auth-chooser__btn munch-auth-chooser__btn--admin" data-munch-login-mode="admin">
        {{ translate('Admin Login') }}
    </button>
    <button type="button" class="munch-auth-chooser__btn munch-auth-chooser__btn--branch" data-munch-login-mode="branch">
        {{ translate('Branch Login') }}
    </button>
    <button type="button" class="munch-auth-chooser__btn munch-auth-chooser__btn--stocks" data-munch-login-mode="stocks">
        {{ translate('Stocks Login') }}
    </button>
</nav>
