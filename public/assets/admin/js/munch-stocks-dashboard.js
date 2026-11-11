/**
 * Stocks manager dashboard — live STOCK SOLD + Result as opening/closing change.
 */
(function () {
    'use strict';

    var EPS = 0.0005;

    function parseQty(raw) {
        if (raw === null || raw === undefined) {
            return null;
        }
        var text = String(raw).trim();
        if (text === '') {
            return null;
        }
        var n = Number(text);
        if (!isFinite(n)) {
            return null;
        }
        return n;
    }

    function formatQty(n) {
        var fixed = Math.abs(n).toFixed(3).replace(/\.?0+$/, '');
        return fixed === '' ? '0' : fixed;
    }

    function formatResult(variance) {
        if (variance === null) {
            return { text: '—', kind: 'empty' };
        }
        if (Math.abs(variance) < EPS) {
            return { text: 'balanced', kind: 'balanced' };
        }
        var abs = formatQty(variance);
        if (variance > 0) {
            return { text: abs + ' excess', kind: 'excess' };
        }
        return { text: abs + ' short', kind: 'short' };
    }

    function setChip(el, result) {
        el.textContent = result.text;
        el.className = 'munch-stocks-chip munch-stocks-chip--' + result.kind;
    }

    function refreshRow(row) {
        var openingInput = row.querySelector('[data-stocks-opening]');
        var closingInput = row.querySelector('[data-stocks-closing]');
        var soldEl = row.querySelector('[data-stocks-sold]');
        var resultEl = row.querySelector('[data-stocks-result]');
        if (!openingInput || !closingInput || !soldEl || !resultEl) {
            return;
        }

        var expected = parseQty(row.getAttribute('data-expected'));
        if (expected === null) {
            expected = 0;
        }

        var opening = parseQty(openingInput.value);
        var closing = parseQty(closingInput.value);

        var physical = null;
        if (opening !== null && closing !== null) {
            physical = Math.round((opening - closing) * 1000) / 1000;
        }

        if (physical === null) {
            soldEl.textContent = '—';
            soldEl.classList.add('munch-stocks-qty--muted');
            setChip(resultEl, { text: '—', kind: 'empty' });
            return;
        }

        soldEl.textContent = formatQty(physical);
        soldEl.classList.remove('munch-stocks-qty--muted');

        var variance = Math.round((physical - expected) * 1000) / 1000;
        setChip(resultEl, formatResult(variance));
    }

    function bind(root) {
        var table = root || document.getElementById('munch-stocks-counts-table');
        if (!table) {
            return;
        }

        function onChange(ev) {
            var target = ev.target;
            if (!target || !target.matches('[data-stocks-opening], [data-stocks-closing]')) {
                return;
            }
            var row = target.closest('[data-stocks-row]');
            if (row) {
                refreshRow(row);
            }
        }

        table.addEventListener('input', onChange);
        table.addEventListener('change', onChange);

        table.querySelectorAll('[data-stocks-row]').forEach(refreshRow);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            bind();
        });
    } else {
        bind();
    }

    window.MunchStocksDashboard = {
        bind: bind,
        formatResult: formatResult,
        refreshRow: refreshRow
    };
})();
