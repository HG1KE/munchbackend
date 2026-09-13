/* Shared Branch POS submit lock + queue UUID helpers (browser + Node tests). */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.MunchPosSubmitGuard = factory();
    }
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    var POST_TIMEOUT_MS = 15000;

    function shouldReuseClientUuid(reason) {
        return reason === 'timeout' || reason === 'network' || reason === 'http_5xx' || reason === 'malformed';
    }

    function shouldClearAttempt(reason) {
        return reason === 'success' || reason === 'queued' || reason === 'validation' || reason === 'http_422' || reason === 'sync-error-before-request';
    }

    function nextAttemptKeys(existing, freshUuid, freshPlacedAt) {
        if (existing && existing.client_uuid && existing.placed_at) {
            return {
                client_uuid: String(existing.client_uuid),
                placed_at: String(existing.placed_at),
                reused: true
            };
        }
        return {
            client_uuid: String(freshUuid || ''),
            placed_at: String(freshPlacedAt || ''),
            reused: false
        };
    }

    function currentBranchId(cfg) {
        var n = Number(cfg && cfg.branchId);
        return n > 0 ? n : 0;
    }

    function payloadBranchId(payload) {
        if (!payload || payload.branch_id == null || payload.branch_id === '') return null;
        var n = Number(payload.branch_id);
        return n > 0 ? n : null;
    }

    function queueBranchMismatch(payload, cfg) {
        var queued = payloadBranchId(payload);
        var current = currentBranchId(cfg);
        return queued !== null && current > 0 && queued !== current;
    }

    function tryAcquire(state) {
        if (!state || state.orderSubmitting || state.successOpen) return false;
        state.orderSubmitting = true;
        return true;
    }

    function release(state) {
        if (state) state.orderSubmitting = false;
    }

    function enqueueUnique(existingRows, payload) {
        var id = payload && payload.client_uuid != null ? String(payload.client_uuid) : '';
        if (!id) {
            return { inserted: false, duplicate: false, row: null, reason: 'missing_uuid' };
        }
        var rows = existingRows || [];
        var i;
        for (i = 0; i < rows.length; i++) {
            var row = rows[i];
            var rowId = row && (row.id != null ? String(row.id) : '');
            var payloadId = row && row.payload && row.payload.client_uuid != null
                ? String(row.payload.client_uuid)
                : '';
            if (rowId === id || payloadId === id) {
                return { inserted: false, duplicate: true, row: row };
            }
        }
        return {
                inserted: true,
                duplicate: false,
                row: {
                    id: id,
                    createdAt: payload.placed_at,
                    payload: payload,
                    branch_id: payload.branch_id != null ? payload.branch_id : null,
                    status: 'queued',
                    attempts: 0,
                    lastError: null
                }
            };
    }

    function createSyncOnce() {
        var pending = false;
        return function request(registerFn) {
            if (pending) {
                return Promise.resolve({ registered: false, duplicate: true });
            }
            pending = true;
            return Promise.resolve()
                .then(function () { return registerFn ? registerFn() : null; })
                .then(function () {
                    pending = false;
                    return { registered: true, duplicate: false };
                })
                .catch(function (err) {
                    pending = false;
                    throw err;
                });
        };
    }

    function createSubmitFlow(deps) {
        var state = { orderSubmitting: false, successOpen: false };
        var queue = [];
        var posts = 0;
        var modals = 0;
        var unlockedAfter = [];

        function begin() {
            return tryAcquire(state);
        }

        function end(reason) {
            release(state);
            unlockedAfter.push(reason || 'unlock');
        }

        function showModalOnce() {
            if (state.successOpen) return false;
            state.successOpen = true;
            modals += 1;
            return true;
        }

        function submit(options) {
            options = options || {};
            if (!begin()) return { ignored: true };
            if (options.validationError) {
                end('validation');
                return { ignored: false, validation: true };
            }
            if (options.http422) {
                end('http_422');
                return { ignored: false, rejected: true, queued: false, modalKeptOpen: true };
            }
            if (options.syncThrow) {
                end('sync-error-before-request');
                return { ignored: false, error: true, queued: false };
            }
            var payload = options.payload || { client_uuid: options.uuid || 'u1', placed_at: 't' };
            if (options.offline || options.forceQueue) {
                return writeQueue(payload, options.queueWriteFails).then(function (result) {
                    return result;
                });
            }
            posts += 1;
            if (options.timeout) {
                end('timeout');
                return Promise.resolve({
                    timedOut: true,
                    reuseUuid: shouldReuseClientUuid('timeout'),
                    queued: false,
                    unlocked: true
                });
            }
            if (options.onlineSuccess) {
                showModalOnce();
                end('online-success');
                return Promise.resolve({ posted: true, modals: modals });
            }
            if (options.postFailsBeforeQueue) {
                return writeQueue(payload, options.queueWriteFails);
            }
            showModalOnce();
            end('online-success');
            return Promise.resolve({ posted: true, modals: modals });
        }

        function writeQueue(payload, failWrite) {
            if (failWrite) {
                end('queue-failed');
                return Promise.resolve({ queued: false, unlocked: true, error: true });
            }
            var decision = enqueueUnique(queue, payload);
            if (decision.inserted) queue.push(decision.row);
            showModalOnce();
            end('queued');
            return Promise.resolve({
                queued: true,
                duplicate: !!decision.duplicate,
                queueLength: queue.length,
                modals: modals
            });
        }

        function spam(times, event, options) {
            var results = [];
            var i;
            var chain = Promise.resolve();
            for (i = 0; i < times; i++) {
                chain = chain.then(function () {
                    return Promise.resolve(submit(options)).then(function (result) {
                        results.push({ event: event, result: result });
                    });
                });
            }
            return chain.then(function () { return results; });
        }

        return {
            state: state,
            queue: queue,
            submit: submit,
            spam: spam,
            showModalOnce: showModalOnce,
            stats: function () {
                return {
                    posts: posts,
                    modals: modals,
                    queueLength: queue.length,
                    submitting: state.orderSubmitting,
                    unlockedAfter: unlockedAfter.slice()
                };
            }
        };
    }

    return {
        POST_TIMEOUT_MS: POST_TIMEOUT_MS,
        tryAcquire: tryAcquire,
        release: release,
        enqueueUnique: enqueueUnique,
        createSyncOnce: createSyncOnce,
        createSubmitFlow: createSubmitFlow,
        shouldReuseClientUuid: shouldReuseClientUuid,
        shouldClearAttempt: shouldClearAttempt,
        nextAttemptKeys: nextAttemptKeys,
        currentBranchId: currentBranchId,
        payloadBranchId: payloadBranchId,
        queueBranchMismatch: queueBranchMismatch
    };
}));
