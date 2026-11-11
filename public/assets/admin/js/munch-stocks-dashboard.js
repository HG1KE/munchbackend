/**
 * Stocks manager dashboard — live Result, draft persistence, save gate.
 * Result = Opening + Received − Sold − Closing.
 */
(function () {
    'use strict';

    var EPS = 0.0005;
    var DRAFT_PREFIX = 'munch-stocks-draft:';

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

    function draftKey(branchId, date) {
        return DRAFT_PREFIX + String(branchId) + ':' + String(date);
    }

    function readDraft(branchId, date) {
        try {
            var raw = window.localStorage.getItem(draftKey(branchId, date));
            if (!raw) {
                return null;
            }
            var parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object') {
                return null;
            }
            return {
                opening: parsed.opening && typeof parsed.opening === 'object' ? parsed.opening : {},
                received: parsed.received && typeof parsed.received === 'object' ? parsed.received : {},
                closing: parsed.closing && typeof parsed.closing === 'object' ? parsed.closing : {}
            };
        } catch (e) {
            return null;
        }
    }

    function writeDraft(branchId, date, payload) {
        try {
            window.localStorage.setItem(draftKey(branchId, date), JSON.stringify(payload));
        } catch (e) {
            // Ignore quota / private-mode failures.
        }
    }

    function clearDraft(branchId, date) {
        try {
            window.localStorage.removeItem(draftKey(branchId, date));
        } catch (e) {
            // Ignore.
        }
    }

    function itemIdFromInput(input) {
        var name = input.getAttribute('name') || '';
        var match = name.match(/\[(\d+)\]/);
        return match ? match[1] : null;
    }

    function collectDraft(table) {
        var opening = {};
        var received = {};
        var closing = {};
        table.querySelectorAll('[data-stocks-opening]').forEach(function (input) {
            var id = itemIdFromInput(input);
            if (id) {
                opening[id] = String(input.value);
            }
        });
        table.querySelectorAll('[data-stocks-received]').forEach(function (input) {
            var id = itemIdFromInput(input);
            if (id) {
                received[id] = String(input.value);
            }
        });
        table.querySelectorAll('[data-stocks-closing]').forEach(function (input) {
            var id = itemIdFromInput(input);
            if (id) {
                closing[id] = String(input.value);
            }
        });
        return { opening: opening, received: received, closing: closing };
    }

    function applyDraft(table, draft) {
        if (!draft) {
            return;
        }
        table.querySelectorAll('[data-stocks-opening]').forEach(function (input) {
            var id = itemIdFromInput(input);
            if (id && Object.prototype.hasOwnProperty.call(draft.opening, id)) {
                input.value = draft.opening[id];
            }
        });
        table.querySelectorAll('[data-stocks-received]').forEach(function (input) {
            var id = itemIdFromInput(input);
            if (id && Object.prototype.hasOwnProperty.call(draft.received, id)) {
                input.value = draft.received[id];
            }
        });
        table.querySelectorAll('[data-stocks-closing]').forEach(function (input) {
            var id = itemIdFromInput(input);
            if (id && Object.prototype.hasOwnProperty.call(draft.closing, id)) {
                input.value = draft.closing[id];
            }
        });
    }

    function allBoxesFilled(table) {
        var inputs = table.querySelectorAll(
            '[data-stocks-opening], [data-stocks-received], [data-stocks-closing]'
        );
        for (var i = 0; i < inputs.length; i++) {
            if (String(inputs[i].value).trim() === '') {
                return false;
            }
        }
        return inputs.length > 0;
    }

    function showFillError(message) {
        if (window.toastr && typeof window.toastr.error === 'function') {
            window.toastr.error(message);
            return;
        }
        window.alert(message);
    }

    function refreshRow(row) {
        var openingInput = row.querySelector('[data-stocks-opening]');
        var receivedInput = row.querySelector('[data-stocks-received]');
        var closingInput = row.querySelector('[data-stocks-closing]');
        var resultEl = row.querySelector('[data-stocks-result]');
        if (!openingInput || !receivedInput || !closingInput || !resultEl) {
            return;
        }

        var expected = parseQty(row.getAttribute('data-expected'));
        if (expected === null) {
            expected = 0;
        }

        var opening = parseQty(openingInput.value);
        var received = parseQty(receivedInput.value);
        var closing = parseQty(closingInput.value);

        if (opening === null || received === null || closing === null) {
            setChip(resultEl, { text: '—', kind: 'empty' });
            return;
        }

        // Result = Opening + Received − Sold − Closing
        var variance = Math.round((opening + received - expected - closing) * 1000) / 1000;
        setChip(resultEl, formatResult(variance));
    }

    function bind(root) {
        var table = root || document.getElementById('munch-stocks-counts-table');
        if (!table) {
            return;
        }

        var form = table.closest('form') || document.getElementById('munch-stocks-counts-form');
        var branchId = table.getAttribute('data-branch-id') || (form && form.getAttribute('data-branch-id')) || '';
        var date = table.getAttribute('data-date') || (form && form.getAttribute('data-date')) || '';
        var fillMessage = (form && form.getAttribute('data-fill-message')) || 'Fill in all boxes before saving';
        var exportBtn = document.getElementById('munch-stocks-export-report');
        var exportMessage =
            (exportBtn && exportBtn.getAttribute('data-export-message')) ||
            (form && form.getAttribute('data-export-message')) ||
            'Complete all fields for the branch and date selected before exporting the report.';

        if (branchId && date) {
            var params = new URLSearchParams(window.location.search);
            if (params.get('saved') === '1') {
                clearDraft(branchId, date);
            } else {
                applyDraft(table, readDraft(branchId, date));
            }
        }

        function persist() {
            if (!branchId || !date) {
                return;
            }
            writeDraft(branchId, date, collectDraft(table));
        }

        function onChange(ev) {
            var target = ev.target;
            if (
                !target ||
                !target.matches('[data-stocks-opening], [data-stocks-received], [data-stocks-closing]')
            ) {
                return;
            }
            var row = target.closest('[data-stocks-row]');
            if (row) {
                refreshRow(row);
            }
            persist();
        }

        table.addEventListener('input', onChange);
        table.addEventListener('change', onChange);

        if (form) {
            form.addEventListener('submit', function (ev) {
                if (!allBoxesFilled(table)) {
                    ev.preventDefault();
                    showFillError(fillMessage);
                }
            });
        }

        if (exportBtn) {
            exportBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                if (!allBoxesFilled(table)) {
                    showFillError(exportMessage);
                    return;
                }
                if (!form) {
                    return;
                }
                var exportUrl =
                    exportBtn.getAttribute('data-export-url') ||
                    exportBtn.getAttribute('href') ||
                    '';
                if (!exportUrl) {
                    return;
                }
                var originalAction = form.getAttribute('action');
                form.setAttribute('action', exportUrl);
                form.submit();
                if (originalAction) {
                    form.setAttribute('action', originalAction);
                }
            });
        }

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
        refreshRow: refreshRow,
        draftKey: draftKey,
        clearDraft: clearDraft,
        allBoxesFilled: allBoxesFilled
    };
})();
