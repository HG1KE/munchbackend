'use strict';

var fs = require('fs');
var path = require('path');

var root = path.join(__dirname, '../..');
var guard = require(path.join(root, 'public/assets/admin/js/munch-pos-submit-guard.js'));
var app = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-pos-app.js'), 'utf8');
var page = fs.readFileSync(path.join(root, 'resources/views/branch-views/pos/index.blade.php'), 'utf8');

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
    return test('normal 200 opens the success modal', function () {
        var flow = guard.createSubmitFlow();
        return flow.spam(3, 'click', { onlineSuccess: true, uuid: 'ok-1' }).then(function () {
            var stats = flow.stats();
            assert(stats.posts === 1, 'expected 1 POST, got ' + stats.posts);
            assert(stats.modals === 1, 'expected 1 success modal');
            assert(stats.queueLength === 0, 'online success should not queue');
        });
    })
        .then(function () {
            return test('timeout but order exists opens the success modal', function () {
                var flow = guard.createSubmitFlow();
                return flow.submit({
                    timeout: true,
                    recoverSuccess: true,
                    uuid: 'to-exists'
                }).then(function (result) {
                    assert(result.checking === true, 'must show checking status');
                    assert(result.recovered === true, 'must recover the posted order');
                    assert(result.posted === true, 'recovered order must count as posted');
                    assert(result.failedToast === false, 'must not say the order failed');
                    assert(result.queued === false, 'must not queue a second sale');
                    assert(result.client_uuid === 'to-exists', 'recovery must keep the same uuid');
                    assert(result.createSecondSale === false, 'timeout recovery must not mint a second sale');
                    assert(flow.stats().modals === 1, 'success modal should open after recovery');
                    assert(flow.state.orderSubmitting === false, 'lock should release after recovery');
                });
            });
        })
        .then(function () {
            return test('network failure but order exists opens the success modal', function () {
                var flow = guard.createSubmitFlow();
                return flow.submit({
                    network: true,
                    orderExists: true,
                    uuid: 'net-exists'
                }).then(function (result) {
                    assert(result.checking === true, 'must show checking status');
                    assert(result.recovered === true, 'network recovery missing');
                    assert(result.failedToast === false, 'must not toast a failure');
                    assert(result.sameUuid === true, 'must reuse the in-flight uuid');
                    assert(flow.stats().modals === 1, 'success modal should open');
                });
            });
        })
        .then(function () {
            return test('timeout and order does not exist retries the same uuid', function () {
                var flow = guard.createSubmitFlow();
                return flow.submit({ timeout: true, uuid: 'to-missing' }).then(function (result) {
                    assert(result.timedOut === true, 'timeout flag missing');
                    assert(result.checking === true, 'must show checking status');
                    assert(result.queued === false, 'online timeout must not auto-queue');
                    assert(result.failedToast === false, 'must not tell the cashier the order failed');
                    assert(result.retrySameUuid === true, 'retry must stay on the same uuid');
                    assert(result.client_uuid === 'to-missing', 'retry uuid drifted');
                    var keys = guard.nextAttemptKeys(
                        { client_uuid: 'to-missing', placed_at: 't1' },
                        'fresh-uuid',
                        't2'
                    );
                    assert(keys.reused === true, 'pending attempt must be reused');
                    assert(keys.client_uuid === 'to-missing', 'retry minted a new uuid');
                    assert(keys.placed_at === 't1', 'retry changed placed_at');
                });
            });
        })
        .then(function () {
            return test('retry after timeout cannot create a duplicate order', function () {
                var first = guard.enqueueUnique([], { client_uuid: 'dup-1', placed_at: 't1' });
                assert(first.inserted === true, 'first row should insert');
                var retry = guard.enqueueUnique([first.row], {
                    client_uuid: 'dup-1',
                    placed_at: 't2'
                });
                assert(retry.inserted === false, 'same uuid must not insert a second sale');
                assert(retry.duplicate === true, 'duplicate flag missing');
                var outcome = guard.recoverPlaceOutcome({
                    client_uuid: 'dup-1',
                    orderExists: true
                });
                assert(outcome.createSecondSale === false, 'recovery must never create a second sale');
                assert(outcome.showSuccessModal === true, 'existing order should open the success modal');
                var keys = guard.nextAttemptKeys(
                    { client_uuid: 'dup-1', placed_at: 't1' },
                    'other',
                    'later'
                );
                assert(keys.client_uuid === 'dup-1', 'post-timeout retry changed uuid');
            });
        })
        .then(function () {
            return test('backdrop click does not dismiss an unprinted success modal', function () {
                assert(app.indexOf("ev.target.id === 'pos-success-modal'") === -1, 'backdrop still dismisses the success modal');
                assert(page.indexOf('id="pos-success-close"') !== -1, 'Close action missing');
                assert(page.indexOf('id="pos-success-done"') !== -1, 'Done action missing');
            });
        })
        .then(function () {
            return test('explicit Done/Close still dismisses the modal', function () {
                assert(app.indexOf("els.successClose.addEventListener('click', dismissPlacedOrder)") !== -1, 'Close no longer dismisses');
                assert(app.indexOf("els.successDone.addEventListener('click', dismissPlacedOrder)") !== -1, 'Done no longer dismisses');
                assert(app.indexOf('function dismissPlacedOrder') !== -1, 'dismissPlacedOrder missing');
            });
        })
        .then(function () {
            return test('existing print buttons continue working', function () {
                assert(page.indexOf('id="pos-success-kitchen"') !== -1, 'kitchen print button missing');
                assert(page.indexOf('id="pos-success-receipt"') !== -1, 'receipt print button missing');
                assert(app.indexOf("printPlacedTicket('kitchen')") !== -1, 'kitchen print handler missing');
                assert(app.indexOf("printPlacedTicket('receipt')") !== -1, 'receipt print handler missing');
                assert(app.indexOf('function printOneTicket') !== -1, 'printOneTicket missing');
            });
        })
        .then(function () {
            return test('existing offline/queued flow remains intact', function () {
                var flow = guard.createSubmitFlow();
                return Promise.resolve(flow.submit({ offline: true, uuid: 'off-keep' })).then(function () {
                    assert(flow.queue.length === 1, 'offline queue should still accept a row');
                    assert(flow.queue[0].id === 'off-keep', 'queued id mismatch');
                    assert(flow.stats().modals === 1, 'offline success modal should still open');
                    assert(app.indexOf('function finishQueuedOrder') !== -1, 'finishQueuedOrder missing');
                    assert(app.indexOf('Order saved offline') !== -1, 'offline copy missing');
                    var offlineRecover = guard.recoverPlaceOutcome({
                        client_uuid: 'off-keep',
                        offline: true
                    });
                    assert(offlineRecover.queued === true, 'offline recovery should queue');
                    assert(offlineRecover.failedToast === false, 'offline recovery must not look like a failed sale');
                });
            });
        })
        .then(function () {
            return test('uncertain failures keep checking copy in the live POS app', function () {
                assert(app.indexOf('Checking order status...') !== -1, 'checking status copy missing');
                assert(app.indexOf('function recoverUncertainSubmit') !== -1, 'recoverUncertainSubmit missing');
                assert(app.indexOf('function leaveSafeRetry') !== -1, 'leaveSafeRetry missing');
                assert(app.indexOf('function acceptPostedOrder') !== -1, 'acceptPostedOrder missing');
                assert(guard.isUncertainPlaceFailure('timeout') === true, 'timeout should recover');
                assert(guard.isUncertainPlaceFailure('network') === true, 'network should recover');
                assert(guard.shouldReuseClientUuid('timeout') === true, 'timeout must reuse uuid');
            });
        })
        .then(function () {
            console.log(passed + ' passed, ' + failed + ' failed');
            process.exit(failed ? 1 : 0);
        });
}

run();
