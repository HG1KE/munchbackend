/**
 * Stocks manager dashboard — live Result, debounced DB autosave, save gate.
 * Result = Closing − (Opening + Received − Sold − Spoiled).
 * Server is source of truth; localStorage drafts are no longer restored.
 */
(function () {
    'use strict';

    var EPS = 0.0005;
    var AUTOSAVE_MS = 550;
    var FIELD_SELECTOR =
        '[data-stocks-opening], [data-stocks-received], [data-stocks-spoiled], [data-stocks-closing]';

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
        return { text: abs + ' missing', kind: 'missing' };
    }

    function setChip(el, result) {
        el.textContent = result.text;
        el.className = 'munch-stocks-chip munch-stocks-chip--' + result.kind;
    }

    function itemIdFromInput(input) {
        var name = input.getAttribute('name') || '';
        var match = name.match(/\[(\d+)\]/);
        return match ? match[1] : null;
    }

    function csrfToken(form) {
        if (form) {
            var input = form.querySelector('input[name="_token"]');
            if (input && input.value) {
                return input.value;
            }
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    function allBoxesFilled(table) {
        var inputs = table.querySelectorAll(FIELD_SELECTOR);
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
        var spoiledInput = row.querySelector('[data-stocks-spoiled]');
        var closingInput = row.querySelector('[data-stocks-closing]');
        var resultEl = row.querySelector('[data-stocks-result]');
        if (!openingInput || !receivedInput || !spoiledInput || !closingInput || !resultEl) {
            return;
        }

        var expected = parseQty(row.getAttribute('data-expected'));
        if (expected === null) {
            expected = 0;
        }

        var opening = parseQty(openingInput.value);
        var received = parseQty(receivedInput.value);
        var spoiled = parseQty(spoiledInput.value);
        var closing = parseQty(closingInput.value);

        if (opening === null || received === null || spoiled === null || closing === null) {
            setChip(resultEl, { text: '—', kind: 'empty' });
            return;
        }

        // Result = Closing − (Opening + Received − Sold − Spoiled)
        var variance = Math.round((closing - (opening + received - expected - spoiled)) * 1000) / 1000;
        setChip(resultEl, formatResult(variance));
    }

    function collectRowPayload(row) {
        var openingInput = row.querySelector('[data-stocks-opening]');
        var receivedInput = row.querySelector('[data-stocks-received]');
        var spoiledInput = row.querySelector('[data-stocks-spoiled]');
        var closingInput = row.querySelector('[data-stocks-closing]');
        if (!openingInput || !receivedInput || !spoiledInput || !closingInput) {
            return null;
        }
        var id = itemIdFromInput(openingInput);
        if (!id) {
            return null;
        }
        return {
            id: id,
            opening: String(openingInput.value),
            received: String(receivedInput.value),
            spoiled: String(spoiledInput.value),
            closing: String(closingInput.value)
        };
    }

    function setSaveStatus(el, state, detail) {
        if (!el) {
            return;
        }
        var text = '';
        var kind = state || 'idle';
        if (state === 'saving') {
            text = 'Saving…';
        } else if (state === 'saved') {
            text = 'Saved';
        } else if (state === 'error') {
            text = detail ? 'Error: ' + detail : 'Error';
        } else {
            text = '';
            kind = 'idle';
        }
        el.textContent = text;
        el.setAttribute('data-status', kind);
        el.className = 'munch-stocks-save-status munch-stocks-save-status--' + kind;
    }

    function createAutosave(options) {
        var form = options.form;
        var statusEl = options.statusEl;
        var debounceMs = options.debounceMs != null ? options.debounceMs : AUTOSAVE_MS;
        var fetchImpl = options.fetchImpl || window.fetch.bind(window);
        var timers = Object.create(null);
        var inflight = 0;
        var lastError = null;
        var generation = Object.create(null);

        function markSaving() {
            setSaveStatus(statusEl, 'saving');
        }

        function markDone() {
            if (inflight > 0) {
                return;
            }
            if (lastError) {
                setSaveStatus(statusEl, 'error', lastError);
                return;
            }
            setSaveStatus(statusEl, 'saved');
        }

        function postRow(payload) {
            if (!form || !payload || !payload.id) {
                return Promise.resolve();
            }
            var action = form.getAttribute('action');
            if (!action) {
                return Promise.resolve();
            }

            var body = new URLSearchParams();
            body.set('_token', csrfToken(form));
            body.set('partial', '1');
            body.set('date', form.querySelector('input[name="date"]').value);
            body.set('branch_id', form.querySelector('input[name="branch_id"]').value);
            body.set('opening[' + payload.id + ']', payload.opening);
            body.set('received[' + payload.id + ']', payload.received);
            body.set('spoiled[' + payload.id + ']', payload.spoiled);
            body.set('closing[' + payload.id + ']', payload.closing);

            var gen = (generation[payload.id] || 0) + 1;
            generation[payload.id] = gen;
            inflight += 1;
            lastError = null;
            markSaving();

            return fetchImpl(action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(form)
                },
                body: body
            })
                .then(function (res) {
                    return res
                        .json()
                        .catch(function () {
                            return { ok: false, error: 'Save failed' };
                        })
                        .then(function (data) {
                            return { res: res, data: data };
                        });
                })
                .then(function (pack) {
                    if (generation[payload.id] !== gen) {
                        return;
                    }
                    if (!pack.res.ok || !pack.data || pack.data.ok === false) {
                        lastError =
                            (pack.data && (pack.data.error || pack.data.message)) ||
                            'Save failed';
                        return;
                    }
                    lastError = null;
                })
                .catch(function () {
                    if (generation[payload.id] !== gen) {
                        return;
                    }
                    lastError = 'Network error';
                })
                .then(function () {
                    inflight = Math.max(0, inflight - 1);
                    markDone();
                });
        }

        function schedule(row) {
            var payload = collectRowPayload(row);
            if (!payload) {
                return;
            }
            if (timers[payload.id]) {
                clearTimeout(timers[payload.id]);
            }
            timers[payload.id] = setTimeout(function () {
                timers[payload.id] = null;
                postRow(collectRowPayload(row) || payload);
            }, debounceMs);
        }

        function flush(row) {
            var payload = collectRowPayload(row);
            if (!payload) {
                return;
            }
            if (timers[payload.id]) {
                clearTimeout(timers[payload.id]);
                timers[payload.id] = null;
            }
            return postRow(payload);
        }

        function flushAll(table) {
            var rows = table ? table.querySelectorAll('[data-stocks-row]') : [];
            var chain = Promise.resolve();
            rows.forEach(function (row) {
                chain = chain.then(function () {
                    return flush(row);
                });
            });
            return chain;
        }

        return {
            schedule: schedule,
            flush: flush,
            flushAll: flushAll,
            postRow: postRow,
            AUTOSAVE_MS: debounceMs
        };
    }

    function bind(root) {
        var table = root || document.getElementById('munch-stocks-counts-table');
        if (!table) {
            return null;
        }

        var form = table.closest('form') || document.getElementById('munch-stocks-counts-form');
        var fillMessage = (form && form.getAttribute('data-fill-message')) || 'Fill in all boxes before saving';
        var exportBtn = document.getElementById('munch-stocks-export-report');
        var exportMessage =
            (exportBtn && exportBtn.getAttribute('data-export-message')) ||
            (form && form.getAttribute('data-export-message')) ||
            'Complete all fields for the branch and date selected before exporting the report.';
        var statusEl = document.getElementById('munch-stocks-save-status');
        var autosave = createAutosave({
            form: form,
            statusEl: statusEl,
            debounceMs: AUTOSAVE_MS
        });

        function onInput(ev) {
            var target = ev.target;
            if (!target || !target.matches(FIELD_SELECTOR)) {
                return;
            }
            var row = target.closest('[data-stocks-row]');
            if (row) {
                refreshRow(row);
                autosave.schedule(row);
            }
        }

        function onBlur(ev) {
            var target = ev.target;
            if (!target || !target.matches(FIELD_SELECTOR)) {
                return;
            }
            var row = target.closest('[data-stocks-row]');
            if (row) {
                autosave.flush(row);
            }
        }

        table.addEventListener('input', onInput);
        table.addEventListener('change', onInput);
        table.addEventListener('focusout', onBlur);

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
                if (exportBtn.getAttribute('data-requires-opening-ack') === '1') {
                    var openingAck = form.querySelector('[name="acknowledge_opening_gap"]');
                    if (!openingAck || !openingAck.checked) {
                        showFillError(
                            exportBtn.getAttribute('data-opening-gap-message') ||
                                'Acknowledge the opening continuity gap before exporting.'
                        );
                        return;
                    }
                }
                if (exportBtn.getAttribute('data-requires-hidden-ack') === '1') {
                    var hiddenAck = form.querySelector('[name="acknowledge_hidden_items"]');
                    if (!hiddenAck || !hiddenAck.checked) {
                        showFillError(
                            exportBtn.getAttribute('data-hidden-ack-message') ||
                                'Acknowledge hidden PDF items before exporting.'
                        );
                        return;
                    }
                }
                var exportUrl =
                    exportBtn.getAttribute('data-export-url') ||
                    exportBtn.getAttribute('href') ||
                    '';
                if (!exportUrl) {
                    return;
                }
                // Flush pending autosaves before export POST so DB matches the form.
                autosave.flushAll(table).then(function () {
                    var originalAction = form.getAttribute('action');
                    form.setAttribute('action', exportUrl);
                    form.submit();
                    if (originalAction) {
                        form.setAttribute('action', originalAction);
                    }
                });
            });
        }

        table.querySelectorAll('[data-stocks-row]').forEach(refreshRow);
        return autosave;
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
        allBoxesFilled: allBoxesFilled,
        createAutosave: createAutosave,
        collectRowPayload: collectRowPayload,
        AUTOSAVE_MS: AUTOSAVE_MS
    };
})();
