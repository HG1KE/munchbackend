/**
 * Debounced live search for admin/branch list pages (Meatco live-search port).
 * Auto-submits forms that contain an input[data-munch-live-search] (or name="search"
 * inside .munch-live-search-form) after the user pauses typing.
 */
(function (window, document) {
    'use strict';

    var DEBOUNCE_MS = 350;
    var timers = new WeakMap();

    function findForm(input) {
        return input.form || input.closest('form');
    }

    function submitLive(form) {
        if (!form) {
            return;
        }
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return;
        }
        form.submit();
    }

    function bindInput(input) {
        if (input.getAttribute('data-munch-live-bound') === '1') {
            return;
        }
        input.setAttribute('data-munch-live-bound', '1');
        input.addEventListener('input', function () {
            var form = findForm(input);
            var existing = timers.get(input);
            if (existing) {
                window.clearTimeout(existing);
            }
            timers.set(input, window.setTimeout(function () {
                submitLive(form);
            }, DEBOUNCE_MS));
        });
    }

    function boot() {
        document.querySelectorAll('input[data-munch-live-search]').forEach(bindInput);
        document.querySelectorAll('form.munch-live-search-form input[name="search"]').forEach(bindInput);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window, document);
