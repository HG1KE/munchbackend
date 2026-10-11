/**
 * Dashboard homepage: poll Online Orders tile every 30s (Meatco live-cards interval).
 * Pauses while the tab is hidden (Meatco visibilityState hot-path).
 */
(function (window, document) {
    'use strict';

    var POLL_MS = 30000;
    var timer = null;
    var root = null;
    var url = null;

    function parseNumeric(value) {
        var num = Number(value);
        return isNaN(num) ? null : num;
    }

    function updateCount(liveRoot, key, nextValue) {
        var el = liveRoot.querySelector('[data-live-card="' + key + '"]');
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

    function poll() {
        if (!root || !url || document.visibilityState === 'hidden') {
            return;
        }
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

    function start() {
        if (timer !== null) {
            return;
        }
        poll();
        timer = window.setInterval(poll, POLL_MS);
    }

    function stop() {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        root = document.getElementById('munch-dashboard-live-root');
        if (!root) {
            return;
        }
        url = root.getAttribute('data-live-cards-url');
        if (!url || !root.querySelector('[data-live-card="online"]')) {
            return;
        }
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                stop();
            } else {
                start();
            }
        });
        start();
    });
})(window, document);
