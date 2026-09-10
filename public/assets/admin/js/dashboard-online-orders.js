/**
 * Dashboard homepage: poll Online Orders tile every 30s (Meatco live-cards interval).
 */
(function (window, document) {
    'use strict';

    var POLL_MS = 30000;

    function parseNumeric(value) {
        var num = Number(value);
        return isNaN(num) ? null : num;
    }

    function updateCount(root, key, nextValue) {
        var el = root.querySelector('[data-live-card="' + key + '"]');
        if (!el || nextValue === null || nextValue === undefined) {
            return;
        }
        var prev = parseNumeric(el.textContent);
        el.textContent = String(nextValue);
        if (prev !== null && prev !== nextValue) {
            var tile = el.closest('.meatco-ops-tile');
            if (tile) {
                tile.classList.add('is-live-updated');
                window.setTimeout(function () {
                    tile.classList.remove('is-live-updated');
                }, 450);
            }
        }
    }

    function poll(root, url) {
        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (payload) {
                if (!payload || !payload.operations) {
                    return;
                }
                var ops = payload.operations;
                updateCount(root, 'online', ops.online != null ? ops.online : ops.express);
            })
            .catch(function () { /* keep last rendered count */ });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('munch-dashboard-live-root');
        if (!root) {
            return;
        }
        var url = root.getAttribute('data-live-cards-url');
        if (!url || !root.querySelector('[data-live-card="online"]')) {
            return;
        }
        poll(root, url);
        window.setInterval(function () { poll(root, url); }, POLL_MS);
    });
})(window, document);
