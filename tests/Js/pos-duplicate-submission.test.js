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
            console.log(passed + ' passed, ' + failed + ' failed');
            process.exit(failed ? 1 : 0);
        });
}

run();
