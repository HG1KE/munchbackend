{{-- Dark/light theme toggle — shared by admin and branch headers --}}
<li class="nav-item">
    <button type="button"
            class="munch-theme-toggle"
            data-munch-theme-toggle
            aria-pressed="false"
            title="{{ translate('Switch theme') }}"
            aria-label="{{ translate('Switch theme') }}">
        <i class="tio-brightness-2" data-munch-theme-icon="sun" hidden aria-hidden="true"></i>
        <i class="tio-moon" data-munch-theme-icon="moon" aria-hidden="true"></i>
    </button>
</li>
