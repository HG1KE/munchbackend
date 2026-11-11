<script>
    "use strict";

    window.MUNCH_LOGIN_MODES = {
        admin: {
            action: @json(route('admin.auth.login')),
            badge: @json(translate('admin_or_employee_sign_in')),
            brandTitle: @json(translate('Manage Munch Operations')),
            brandText: @json(translate('Orders, branches, marketing and analytics in one dashboard.')),
            brandLabel: @json(translate('Admin')),
            pageTitle: @json(translate('Admin') . ' | ' . translate('Login')),
            demoEmail: @json(translate('Email : admin@admin.com')),
            demoPassword: @json(translate('Password : 12345678')),
            demoUser: 'admin@admin.com',
            demoPass: '12345678'
        },
        branch: {
            action: @json(route('branch.auth.login')),
            badge: @json(translate('branch_sign_in')),
            brandTitle: @json(translate('Manage Branch Operations')),
            brandText: @json(translate('Orders, kitchen flow and branch performance in one dashboard.')),
            brandLabel: @json(translate('Branch')),
            pageTitle: @json(translate('Branch') . ' | ' . translate('Login')),
            demoEmail: @json(translate('Email : mainb@mainb.com')),
            demoPassword: @json(translate('Password : 12345678')),
            demoUser: 'mainb@mainb.com',
            demoPass: '12345678'
        },
        stocks: {
            action: @json(route('stocks.auth.login')),
            badge: @json(translate('stocks_manager_sign_in')),
            brandTitle: @json(translate('Stock Control')),
            brandText: @json(translate('Daily opening and closing counts for your branch.')),
            brandLabel: @json(translate('Stocks')),
            pageTitle: @json(translate('Stocks') . ' | ' . translate('Login')),
            demoEmail: @json(translate('Email : admin@admin.com')),
            demoPassword: @json(translate('Password : 12345678')),
            demoUser: 'admin@admin.com',
            demoPass: '12345678'
        }
    };

    (function () {
        var root = document.querySelector('[data-munch-login-root]');
        if (!root) {
            return;
        }

        var modes = window.MUNCH_LOGIN_MODES || {};
        var chooser = document.getElementById('munch-auth-chooser-wrap');
        var formWrap = document.getElementById('munch-auth-form-wrap');
        var form = document.getElementById('form-id');
        var panelInput = document.getElementById('login_panel');
        var badge = document.getElementById('munch-auth-mode-badge');
        var brandTitle = document.querySelector('.munch-auth-brand__title');
        var brandText = document.querySelector('.munch-auth-brand__text');
        var brandAside = document.querySelector('.munch-auth-brand');
        var demoEmail = document.getElementById('munch-auth-demo-email');
        var demoPassword = document.getElementById('munch-auth-demo-password');
        var pageTitle = document.getElementById('munch-auth-page-title');
        var initialMode = root.getAttribute('data-munch-login-initial') || 'admin';
        var showFormInitially = root.getAttribute('data-munch-login-show-form') === '1';

        function setShown(el, shown) {
            if (!el) {
                return;
            }
            el.hidden = !shown;
            el.style.display = shown ? '' : 'none';
        }

        function applyMode(mode, options, sourceBtn) {
            options = options || {};
            var cfg = modes[mode] || {};
            if (sourceBtn) {
                cfg.action = sourceBtn.getAttribute('data-munch-login-action') || cfg.action;
                cfg.badge = sourceBtn.getAttribute('data-munch-login-badge') || cfg.badge;
            }

            if (form && cfg.action) {
                form.setAttribute('action', cfg.action);
            }
            if (panelInput) {
                panelInput.value = mode || '';
            }
            if (badge && cfg.badge) {
                badge.textContent = cfg.badge;
            }
            if (brandTitle && cfg.brandTitle) {
                brandTitle.textContent = cfg.brandTitle;
            }
            if (brandText && cfg.brandText) {
                brandText.textContent = cfg.brandText;
            }
            if (brandAside && cfg.brandLabel) {
                brandAside.setAttribute('aria-label', cfg.brandLabel);
            }
            if (pageTitle && cfg.pageTitle) {
                pageTitle.textContent = cfg.pageTitle;
            }
            if (demoEmail && cfg.demoEmail) {
                demoEmail.textContent = cfg.demoEmail;
            }
            if (demoPassword && cfg.demoPassword) {
                demoPassword.textContent = cfg.demoPassword;
            }

            var modeButtons = document.querySelectorAll('[data-munch-login-mode]');
            for (var i = 0; i < modeButtons.length; i++) {
                var isActive = modeButtons[i].getAttribute('data-munch-login-mode') === mode;
                modeButtons[i].classList.toggle('is-selected', isActive);
                modeButtons[i].setAttribute('aria-pressed', isActive ? 'true' : 'false');
            }

            if (options.reveal) {
                setShown(chooser, false);
                setShown(formWrap, true);
                var email = document.getElementById('signinSrEmail');
                if (email && options.focus !== false) {
                    try {
                        email.focus();
                    } catch (err) {}
                }
            }
        }

        function showChooser() {
            setShown(chooser, true);
            setShown(formWrap, false);
            if (panelInput) {
                panelInput.value = '';
            }
            var password = document.getElementById('signupSrPassword');
            if (password) {
                password.value = '';
            }
            var modeButtons = document.querySelectorAll('[data-munch-login-mode]');
            for (var i = 0; i < modeButtons.length; i++) {
                modeButtons[i].classList.remove('is-selected');
                modeButtons[i].setAttribute('aria-pressed', 'false');
            }
        }

        window.munchLoginCopyCred = function () {
            var mode = panelInput && panelInput.value ? panelInput.value : initialMode;
            var cfg = modes[mode] || modes.admin || {};
            var email = document.getElementById('signinSrEmail');
            var password = document.getElementById('signupSrPassword');
            if (email && cfg.demoUser) {
                email.value = cfg.demoUser;
            }
            if (password && cfg.demoPass) {
                password.value = cfg.demoPass;
            }
        };

        document.addEventListener('click', function (e) {
            var modeBtn = e.target.closest ? e.target.closest('[data-munch-login-mode]') : null;
            if (modeBtn) {
                e.preventDefault();
                applyMode(modeBtn.getAttribute('data-munch-login-mode'), { reveal: true }, modeBtn);
                return;
            }

            var changeBtn = e.target.closest ? e.target.closest('[data-munch-login-change]') : null;
            if (changeBtn) {
                e.preventDefault();
                showChooser();
            }
        });

        if (showFormInitially) {
            applyMode(initialMode, { reveal: true, focus: false });
        }
    })();
</script>
