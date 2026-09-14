'use strict';

var path = require('path');
var guard = require(path.join(
    __dirname,
    '../../public/assets/admin/js/munch-pos-submit-guard.js'
));

var failed = 0;
var passed = 0;

function assert(cond, message) {
    if (!cond) throw new Error(message || 'assertion failed');
}

function test(name, fn) {
    return Promise.resolve()
        .then(fn)
        .then(function () {
            passed += 1;
            console.log('ok - ' + name);
        })
        .catch(function (err) {
            failed += 1;
            console.error('not ok - ' + name + ': ' + err.message);
        });
}

function run() {
    return test('double mouse click queues one order', function () {
        var flow = guard.createSubmitFlow();
        return flow.spam(2, 'click', { offline: true, uuid: 'click-1' }).then(function () {
            var stats = flow.stats();
            assert(stats.queueLength === 1, 'expected 1 queued row, got ' + stats.queueLength);
            assert(stats.modals === 1, 'expected 1 success modal, got ' + stats.modals);
            assert(stats.submitting === false, 'lock should release after queue');
        });
    })
        .then(function () {
            return test('triple mouse click queues one order', function () {
                var flow = guard.createSubmitFlow();
                return flow.spam(3, 'click', { offline: true, uuid: 'click-3' }).then(function () {
                    assert(flow.stats().queueLength === 1, 'triple click created extra queue rows');
                    assert(flow.stats().modals === 1, 'triple click opened extra modals');
                });
            });
        })
        .then(function () {
            return test('Enter spam submits once', function () {
                var flow = guard.createSubmitFlow();
                return flow.spam(8, 'keydown-Enter', { offline: true, uuid: 'enter-1' }).then(function () {
                    assert(flow.stats().queueLength === 1, 'Enter spam queued extras');
                    assert(flow.stats().modals === 1, 'Enter spam opened extras');
                });
            });
        })
        .then(function () {
            return test('touch spam submits once', function () {
                var flow = guard.createSubmitFlow();
                return flow.spam(6, 'touchstart', { offline: true, uuid: 'touch-1' }).then(function () {
                    assert(flow.stats().queueLength === 1, 'touch spam queued extras');
                    assert(flow.stats().modals === 1, 'touch spam opened extras');
                });
            });
        })
        .then(function () {
            return test('offline queue contains one row', function () {
                var flow = guard.createSubmitFlow();
                return Promise.resolve(flow.submit({ offline: true, uuid: 'off-1' })).then(function () {
                    assert(flow.queue.length === 1, 'offline queue should have one row');
                    assert(flow.queue[0].id === 'off-1', 'queued id mismatch');
                    assert(flow.queue[0].status === 'queued', 'row should be queued');
                });
            });
        })
        .then(function () {
            return test('online submission one POST only', function () {
                var flow = guard.createSubmitFlow();
                return flow.spam(4, 'click', { onlineSuccess: true, uuid: 'on-1' }).then(function () {
                    var stats = flow.stats();
                    assert(stats.posts === 1, 'expected 1 POST, got ' + stats.posts);
                    assert(stats.modals === 1, 'expected 1 modal after online success');
                    assert(stats.queueLength === 0, 'online success should not queue');
                });
            });
        })
        .then(function () {
            return test('validation unlock', function () {
                var flow = guard.createSubmitFlow();
                return Promise.resolve(flow.submit({ validationError: true })).then(function (result) {
                    assert(result.validation === true, 'validation path skipped');
                    assert(flow.state.orderSubmitting === false, 'lock lingered after validation');
                    assert(flow.stats().unlockedAfter.indexOf('validation') !== -1, 'missing validation unlock');
                    return Promise.resolve(flow.submit({ offline: true, uuid: 'after-validation' }));
                }).then(function () {
                    assert(flow.stats().queueLength === 1, 'retry after validation should queue');
                });
            });
        })
        .then(function () {
            return test('queue failure unlock', function () {
                var flow = guard.createSubmitFlow();
                return Promise.resolve(flow.submit({
                    offline: true,
                    queueWriteFails: true,
                    uuid: 'fail-1'
                })).then(function (result) {
                    assert(result.error === true, 'queue failure should report error');
                    assert(flow.state.orderSubmitting === false, 'lock lingered after queue failure');
                    assert(flow.stats().queueLength === 0, 'failed write should not insert');
                    return Promise.resolve(flow.submit({ offline: true, uuid: 'retry-1' }));
                }).then(function () {
                    assert(flow.stats().queueLength === 1, 'retry after queue failure should insert');
                });
            });
        })
        .then(function () {
            return test('queued payload keeps the original placed_at', function () {
                var payload = { client_uuid: 'offline-1', placed_at: '2026-09-12T10:20:00.000Z' };
                var first = guard.enqueueUnique([], payload);
                assert(first.inserted === true, 'first insert should succeed');
                assert(first.row.payload.placed_at === '2026-09-12T10:20:00.000Z', 'queued placed_at changed');
                assert(first.row.createdAt === '2026-09-12T10:20:00.000Z', 'queue createdAt should be the sale time');
                var retry = guard.enqueueUnique([first.row], {
                    client_uuid: 'offline-1',
                    placed_at: '2026-09-12T21:05:00.000Z'
                });
                assert(retry.inserted === false, 'retry must stay idempotent');
                assert(retry.row.payload.placed_at === '2026-09-12T10:20:00.000Z', 'retry overwrote placed_at');
            });
        })
        .then(function () {
            return test('duplicate UUID ignored', function () {
                var payload = { client_uuid: 'same-uuid', placed_at: 't1' };
                var first = guard.enqueueUnique([], payload);
                assert(first.inserted === true, 'first insert should succeed');
                var second = guard.enqueueUnique([first.row], payload);
                assert(second.inserted === false, 'duplicate UUID was inserted');
                assert(second.duplicate === true, 'duplicate flag missing');
                assert(second.row === first.row, 'should return existing queued record');
            });
        })
        .then(function () {
            return test('success modal shown once', function () {
                var flow = guard.createSubmitFlow();
                assert(flow.showModalOnce() === true, 'first modal should show');
                assert(flow.showModalOnce() === false, 'second modal should be ignored');
                assert(flow.stats().modals === 1, 'modal count drifted');
            });
        })
        .then(function () {
            return test('sync sends one order', function () {
                var sync = guard.createSyncOnce();
                var registers = 0;
                var register = function () {
                    registers += 1;
                };
                return Promise.all([
                    sync(register),
                    sync(register),
                    sync(register)
                ]).then(function (results) {
                    var accepted = results.filter(function (row) { return row.registered; }).length;
                    var dupes = results.filter(function (row) { return row.duplicate; }).length;
                    assert(registers === 1, 'expected 1 sync register, got ' + registers);
                    assert(accepted === 1, 'expected 1 accepted sync');
                    assert(dupes === 2, 'expected 2 duplicate syncs');
                });
            });
        })
        .then(function () {
            return test('422 unlocks and is not queued', function () {
                var flow = guard.createSubmitFlow();
                return Promise.resolve(flow.submit({ http422: true, uuid: 'v422' })).then(function (result) {
                    assert(result.rejected === true, '422 should reject');
                    assert(result.queued === false, '422 must not queue');
                    assert(result.modalKeptOpen === true, '422 should keep the modal open');
                    assert(flow.state.orderSubmitting === false, 'lock lingered after 422');
                    return Promise.resolve(flow.submit({ offline: true, uuid: 'after-422' }));
                }).then(function () {
                    assert(flow.stats().queueLength === 1, 'retry after 422 should queue');
                });
            });
        })
        .then(function () {
            return test('timeout unlocks and reuses client_uuid', function () {
                var flow = guard.createSubmitFlow();
                return flow.submit({ timeout: true, uuid: 'to-1' }).then(function (result) {
                    assert(result.timedOut === true, 'timeout flag missing');
                    assert(result.queued === false, 'timeout must not auto-queue');
                    assert(result.reuseUuid === true, 'timeout must reuse uuid');
                    assert(result.failedToast === false, 'timeout must not look like a failed sale');
                    assert(result.checking === true, 'timeout must check order status');
                    assert(flow.state.orderSubmitting === false, 'lock lingered after timeout');
                    var keys = guard.nextAttemptKeys({ client_uuid: 'to-1', placed_at: 't1' }, 'new', 't2');
                    assert(keys.reused === true, 'retry keys should reuse');
                    assert(keys.client_uuid === 'to-1', 'retry changed uuid');
                    assert(keys.placed_at === 't1', 'retry changed placed_at');
                });
            });
        })
        .then(function () {
            return test('sync throw unlocks without queueing', function () {
                var flow = guard.createSubmitFlow();
                return Promise.resolve(flow.submit({ syncThrow: true })).then(function (result) {
                    assert(result.error === true, 'sync throw should error');
                    assert(result.queued === false, 'sync throw must not queue');
                    assert(flow.state.orderSubmitting === false, 'lock lingered after throw');
                });
            });
        })
        .then(function () {
            return test('uncertain failures reuse uuid and 422 clears', function () {
                assert(guard.shouldReuseClientUuid('timeout') === true, 'timeout reuse');
                assert(guard.shouldReuseClientUuid('network') === true, 'network reuse');
                assert(guard.shouldReuseClientUuid('http_5xx') === true, '5xx reuse');
                assert(guard.shouldClearAttempt('http_422') === true, '422 clear');
                assert(guard.shouldClearAttempt('success') === true, 'success clear');
                assert(guard.POST_TIMEOUT_MS === 15000, 'timeout should be 15s');
                var fresh = guard.nextAttemptKeys(null, 'n1', 'p1');
                assert(fresh.reused === false, 'first attempt is new');
                assert(fresh.client_uuid === 'n1', 'first uuid');
            });
        })
        .then(function () {
            return test('queued payload is branch-scoped', function () {
                var payload = { client_uuid: 'br-1', placed_at: 't1', branch_id: 14 };
                var first = guard.enqueueUnique([], payload);
                assert(first.inserted === true, 'first insert should succeed');
                assert(first.row.branch_id === 14, 'queued branch missing');
                assert(first.row.payload.branch_id === 14, 'payload branch missing');
                assert(guard.queueBranchMismatch(payload, { branchId: 14 }) === false, 'same branch should sync');
                assert(guard.queueBranchMismatch(payload, { branchId: 3 }) === true, 'other branch must reject');
                assert(guard.queueBranchMismatch({ client_uuid: 'legacy' }, { branchId: 3 }) === false, 'legacy rows without branch_id must still replay');
            });
        })
        .then(function () {
            return test('refresh recovery reuses the same uuid', function () {
                var stored = { client_uuid: 'persist-1', placed_at: 't-persist' };
                var keys = guard.nextAttemptKeys(stored, 'fresh-uuid', 'fresh-time');
                assert(keys.reused === true, 'stored attempt should reuse');
                assert(keys.client_uuid === 'persist-1', 'refresh minted a new uuid');
                assert(keys.placed_at === 't-persist', 'refresh changed placed_at');
            });
        })
        .then(function () {
            return test('idempotent retry is distinct from a fresh post', function () {
                assert(guard.shouldReuseClientUuid('timeout') === true, 'timeout reuse');
                assert(guard.shouldReuseClientUuid('network') === true, 'network reuse');
                assert(guard.shouldClearAttempt('success') === true, 'success clear');
                assert(guard.shouldClearAttempt('queued') === true, 'queued clear');
            });
        })
        .then(function () {
            console.log(passed + ' passed, ' + failed + ' failed');
            process.exit(failed ? 1 : 0);
        });
}

run();
