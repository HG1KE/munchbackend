<script type="application/json" id="munch-login-modes-json">{
{
    "admin": {
        "action": @json(route('admin.auth.login')),
        "captchaBase": @json(url('/admin/auth/code/captcha')),
        "badge": @json(translate('admin_or_employee_sign_in')),
        "brandTitle": @json(translate('Manage Munch Operations')),
        "brandText": @json(translate('Orders, branches, marketing and analytics in one dashboard.')),
        "brandLabel": @json(translate('Admin')),
        "pageTitle": @json(translate('Admin') . ' | ' . translate('Login')),
        "demoEmail": @json(translate('Email : admin@admin.com')),
        "demoPassword": @json(translate('Password : 12345678')),
        "demoUser": "admin@admin.com",
        "demoPass": "12345678"
    },
    "branch": {
        "action": @json(route('branch.auth.login')),
        "captchaBase": @json(url('/branch/auth/code/captcha')),
        "badge": @json(translate('branch_sign_in')),
        "brandTitle": @json(translate('Manage Branch Operations')),
        "brandText": @json(translate('Orders, kitchen flow and branch performance in one dashboard.')),
        "brandLabel": @json(translate('Branch')),
        "pageTitle": @json(translate('Branch') . ' | ' . translate('Login')),
        "demoEmail": @json(translate('Email : mainb@mainb.com')),
        "demoPassword": @json(translate('Password : 12345678')),
        "demoUser": "mainb@mainb.com",
        "demoPass": "12345678"
    },
    "stocks": {
        "action": @json(route('stocks.auth.login')),
        "captchaBase": @json(url('/stocks/auth/code/captcha')),
        "badge": @json(translate('stocks_manager_sign_in')),
        "brandTitle": @json(translate('Stock Control')),
        "brandText": @json(translate('Daily opening and closing counts for your branch.')),
        "brandLabel": @json(translate('Stocks')),
        "pageTitle": @json(translate('Stocks') . ' | ' . translate('Login')),
        "demoEmail": @json(translate('Email : admin@admin.com')),
        "demoPassword": @json(translate('Password : 12345678')),
        "demoUser": "admin@admin.com",
        "demoPass": "12345678"
    }
}
</script>
<script>
    "use strict";

    (function () {
        var root = document.querySelector('[data-munch-login-root]');
        if (!root) {
            return;
        }

        var modes = {};
        var modesNode = document.getElementById('munch-login-modes-json');
        if (modesNode) {
            try {
                modes = JSON.parse(modesNode.textContent);
            } catch (e) {
                modes = {};
            }
        }

        var chooser = document.getElementById('munch-auth-chooser-wrap');
        var formWrap = document.getElementById('munch-auth-form-wrap');
        var form = document.getElementById('form-id');
        var panelInput = document.getElementById('login_panel');
        var badge = document.getElementById('munch-auth-mode-badge');
        var captchaImg = document.getElementById('default_recaptcha_id');
        var brandTitle = document.querySelector('.munch-auth-brand__title');
        var brandText = document.querySelector('.munch-auth-brand__text');
        var brandAside = document.querySelector('.munch-auth-brand');
        var demoEmail = document.getElementById('munch-auth-demo-email');
        var demoPassword = document.getElementById('munch-auth-demo-password');
        var pageTitle = document.getElementById('munch-auth-page-title');
        var initialMode = root.getAttribute('data-munch-login-initial') || 'admin';
        var showFormInitially = root.getAttribute('data-munch-login-show-form') === '1';

        function applyMode(mode, options) {
            var cfg = modes[mode];
            if (!cfg || !form) {
                return;
            }

            options = options || {};
            form.setAttribute('action', cfg.action);
            if (panelInput) {
                panelInput.value = mode;
            }
            if (badge) {
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
            if (captchaImg && cfg.captchaBase) {
                captchaImg.src = cfg.captchaBase + '/' + Math.random();
            }

            document.querySelectorAll('[data-munch-login-mode]').forEach(function (btn) {
                var isActive = btn.getAttribute('data-munch-login-mode') === mode;
                btn.classList.toggle('is-selected', isActive);
                btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });

            if (options.reveal) {
                if (chooser) {
                    chooser.hidden = true;
                }
                if (formWrap) {
                    formWrap.hidden = false;
                }
                var email = document.getElementById('signinSrEmail');
                if (email && options.focus !== false) {
                    email.focus();
                }
            }
        }

        function showChooser() {
            if (chooser) {
                chooser.hidden = false;
            }
            if (formWrap) {
                formWrap.hidden = true;
            }
            if (panelInput) {
                panelInput.value = '';
            }
            var password = document.getElementById('signupSrPassword');
            if (password) {
                password.value = '';
            }
            document.querySelectorAll('[data-munch-login-mode]').forEach(function (btn) {
                btn.classList.remove('is-selected');
                btn.setAttribute('aria-pressed', 'false');
            });
        }

        window.munchLoginRefreshCaptcha = function () {
            var mode = panelInput && panelInput.value ? panelInput.value : initialMode;
            var cfg = modes[mode] || modes.admin;
            if (captchaImg && cfg && cfg.captchaBase) {
                captchaImg.src = cfg.captchaBase + '/' + Math.random();
            }
        };

        window.munchLoginCopyCred = function () {
            var mode = panelInput && panelInput.value ? panelInput.value : initialMode;
            var cfg = modes[mode] || modes.admin;
            if (!cfg) {
                return;
            }
            var email = document.getElementById('signinSrEmail');
            var password = document.getElementById('signupSrPassword');
            if (email) {
                email.value = cfg.demoUser;
            }
            if (password) {
                password.value = cfg.demoPass;
            }
        };

        document.querySelectorAll('[data-munch-login-mode]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                applyMode(btn.getAttribute('data-munch-login-mode'), { reveal: true });
            });
        });

        document.querySelectorAll('[data-munch-login-change]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                showChooser();
            });
        });

        if (showFormInitially && modes[initialMode]) {
            applyMode(initialMode, { reveal: true, focus: false });
        } else if (modes[initialMode]) {
            applyMode(initialMode, { reveal: false });
        }
    })();
</script>
