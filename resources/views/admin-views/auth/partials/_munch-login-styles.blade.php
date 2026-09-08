<style>
    /* Shared admin/branch login — scoped to body.munch-auth-login only */
    body.munch-auth-login {
        margin: 0;
        background: #f8fafc;
        min-height: 100vh;
    }

    body.munch-auth-login .main {
        min-height: 100vh;
        padding: 0;
    }

    body.munch-auth-login .auth-wrapper {
        min-height: 100vh;
        align-items: stretch;
    }

    body.munch-auth-login .munch-auth-brand {
        flex: 1 1 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2.5rem 2rem;
        background: linear-gradient(145deg, #fff5f5 0%, #f8fafc 45%, #f1f5f9 100%);
        border-right: 1px solid #e2e8f0;
        position: relative;
        overflow: hidden;
    }

    body.munch-auth-login .munch-auth-brand::before {
        content: "";
        position: absolute;
        inset: 0;
        background: radial-gradient(circle at 20% 30%, rgba(255, 103, 103, 0.08), transparent 55%),
                    radial-gradient(circle at 80% 70%, rgba(252, 106, 87, 0.06), transparent 50%);
        pointer-events: none;
    }

    body.munch-auth-login .munch-auth-brand__inner {
        position: relative;
        max-width: 420px;
        width: 100%;
        z-index: 1;
    }

    body.munch-auth-login .munch-auth-brand__logo {
        max-width: 200px;
        max-height: 64px;
        width: auto;
        height: auto;
        object-fit: contain;
        object-position: left center;
        margin-bottom: 1.75rem;
        display: block;
    }

    body.munch-auth-login .munch-auth-brand__title {
        font-size: 1.75rem;
        font-weight: 700;
        line-height: 1.25;
        color: #0f172a;
        margin: 0 0 0.75rem;
        letter-spacing: -0.02em;
    }

    body.munch-auth-login .munch-auth-brand__text {
        font-size: 0.9375rem;
        line-height: 1.6;
        color: #64748b;
        margin: 0;
        max-width: 34ch;
    }

    body.munch-auth-login .auth-wrapper-right {
        flex: 1 1 50%;
        max-width: none;
        width: auto;
        min-height: 100vh;
        background: #f8fafc;
        padding: 1.5rem;
    }

    body.munch-auth-login .auth-wrapper-right .auth-wrapper-form {
        max-width: 420px;
        width: 100%;
        margin: 0 auto;
        transform: none;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06);
        padding: 2rem 1.75rem 1.75rem;
    }

    body.munch-auth-login .auth-wrapper-right .auth-header {
        margin-bottom: 1.5rem;
        font-size: 0.9375rem;
        color: #64748b;
        font-weight: 400;
        line-height: 1.5;
    }

    body.munch-auth-login .auth-wrapper-right .auth-header .title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 0.35rem;
        letter-spacing: -0.02em;
    }

    body.munch-auth-login .auth-wrapper-right .auth-header .badge {
        font-size: 0.75rem;
        font-weight: 500;
        color: var(--c1);
        background: rgba(var(--c1-rgb), 0.1);
        border-radius: 999px;
        padding: 0.35rem 0.75rem;
        margin-top: 0.5rem;
    }

    body.munch-auth-login .munch-auth-mobile-logo {
        display: none;
        text-align: center;
        margin-bottom: 1.25rem;
    }

    body.munch-auth-login .munch-auth-mobile-logo img {
        max-width: 160px;
        max-height: 52px;
        object-fit: contain;
    }

    body.munch-auth-login .munch-auth-alt-login {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid #e2e8f0;
    }

    body.munch-auth-login .munch-auth-alt-login__label {
        font-size: 0.8125rem;
        color: #94a3b8;
        margin-bottom: 0.5rem;
    }

    body.munch-auth-login .munch-auth-alt-login__btn {
        display: block;
        width: 100%;
        text-align: center;
        font-weight: 600;
        font-size: 0.875rem;
        padding: 0.55rem 1rem;
        border-radius: 10px;
        border: 1px solid #cbd5e1;
        color: #334155;
        background: #fff;
        text-decoration: none;
        transition: border-color 0.15s ease, color 0.15s ease, background 0.15s ease;
    }

    body.munch-auth-login .munch-auth-alt-login__btn:hover {
        border-color: var(--c1);
        color: var(--c1);
        background: rgba(var(--c1-rgb), 0.04);
        text-decoration: none;
    }

    body.munch-auth-login .form-group {
        margin-bottom: 1.15rem;
    }

    body.munch-auth-login .input-label {
        font-size: 0.8125rem;
        font-weight: 600;
        color: #334155;
        margin-bottom: 0.4rem;
    }

    body.munch-auth-login .form-control-lg {
        min-height: 46px;
        height: 46px;
        font-size: 0.9375rem;
        border-radius: 10px;
        border-color: #cbd5e1;
        padding-left: 0.875rem;
        padding-right: 0.875rem;
    }

    body.munch-auth-login .form-control-lg:focus {
        border-color: var(--c1);
        box-shadow: 0 0 0 3px rgba(var(--c1-rgb), 0.15);
    }

    body.munch-auth-login .input-group-merge .input-group-text {
        border-radius: 0 10px 10px 0;
        border-color: #cbd5e1;
        background: #f8fafc;
    }

    body.munch-auth-login .input-group-merge .form-control {
        border-radius: 10px 0 0 10px;
    }

    body.munch-auth-login .custom-control-label {
        font-size: 0.875rem;
        color: #64748b;
        padding-top: 0.1rem;
    }

    body.munch-auth-login .munch-auth-errors {
        font-size: 0.875rem;
        border-radius: 10px;
        margin-bottom: 1rem;
        padding: 0.75rem 1rem;
    }

    body.munch-auth-login #signInBtn {
        min-height: 46px;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.9375rem;
        margin-top: 0.25rem;
    }

    body.munch-auth-login #signInBtn.is-loading {
        pointer-events: none;
        opacity: 0.85;
    }

    body.munch-auth-login #signInBtn .munch-auth-spinner {
        display: none;
        width: 1rem;
        height: 1rem;
        border: 2px solid rgba(255, 255, 255, 0.35);
        border-top-color: #fff;
        border-radius: 50%;
        animation: munch-auth-spin 0.65s linear infinite;
        vertical-align: -0.15em;
        margin-right: 0.35rem;
    }

    body.munch-auth-login #signInBtn.is-loading .munch-auth-spinner {
        display: inline-block;
    }

    @keyframes munch-auth-spin {
        to { transform: rotate(360deg); }
    }

    body.munch-auth-login .auth-wrapper-right .btn--primary {
        background: var(--c1);
    }

    @media (max-width: 991px) {
        body.munch-auth-login .munch-auth-brand {
            display: none;
        }

        body.munch-auth-login .auth-wrapper-left {
            display: none;
        }

        body.munch-auth-login .munch-auth-mobile-logo {
            display: block;
        }

        body.munch-auth-login .auth-wrapper-right {
            max-width: 100%;
            padding: 1.25rem;
            align-items: center;
        }

        body.munch-auth-login .auth-wrapper-right .auth-wrapper-form {
            padding: 1.5rem 1.25rem;
        }
    }

    @media (min-width: 992px) {
        body.munch-auth-login .auth-wrapper-left {
            display: none;
        }
    }
</style>
