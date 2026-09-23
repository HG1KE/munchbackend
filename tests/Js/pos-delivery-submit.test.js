'use strict';

var fs = require('fs');
var path = require('path');

var root = path.join(__dirname, '../..');
var guard = require(path.join(root, 'public/assets/admin/js/munch-pos-submit-guard.js'));
var delivery = require(path.join(root, 'public/assets/admin/js/munch-pos-delivery.js'));
var app = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-pos-app.js'), 'utf8');
var page = fs.readFileSync(path.join(root, 'resources/views/branch-views/pos/index.blade.php'), 'utf8');

var failed = 0;
var passed = 0;

function assert(cond, message) {
    if (!cond) throw new Error(message || 'assertion failed');
}

function test(name, fn) {
    try {
        fn();
        passed += 1;
        console.log('ok - ' + name);
    } catch (err) {
        failed += 1;
        console.error('not ok - ' + name + ': ' + err.message);
    }
}

function extractFn(source, name) {
    var start = source.indexOf('function ' + name);
    assert(start !== -1, name + ' not found');
    return source.slice(start, start + 6000);
}

test('delivery form validation accepts complete details', function () {
    var addr = {
        contact_person_name: 'Jane Doe',
        contact_person_number: '0712345678',
        address: 'Nyali, Links Road'
    };
    assert(delivery.posDeliveryFormError(addr) === null, 'complete delivery should pass');
});

test('delivery form validation rejects overlong address before POST', function () {
    var addr = {
        contact_person_name: 'Jane',
        contact_person_number: '0712345678',
        address: new Array(260).join('x')
    };
    assert(delivery.posDeliveryFormError(addr) !== null, 'long address must fail client-side');
});

test('classifier treats 422 as validation not recovery', function () {
    var outcome = guard.classifyPosSubmitResponse({ success: 0, _http: 422, message: 'Customer Name' });
    assert(outcome.kind === 'validation', '422 must be validation');
});

test('classifier treats duplicate success as success', function () {
    var outcome = guard.classifyPosSubmitResponse({ success: 1, duplicate: true, _http: 200 });
    assert(outcome.kind === 'success', 'duplicate success');
    outcome = guard.classifyPosSubmitResponse({ success: 0, duplicate: true, idempotent: true, _http: 200 });
    assert(outcome.kind === 'success', 'idempotent duplicate');
});

test('classifier treats 404/500-with-message as client errors', function () {
    assert(guard.classifyPosSubmitResponse({ success: 0, _http: 404, message: 'missing' }).kind === 'client', '404');
    assert(guard.classifyPosSubmitResponse({ success: 0, _http: 500, message: 'failed' }).kind === 'uncertain', '500 stays uncertain');
});

test('submitPlacedOrder reads delivery modal before validating', function () {
    var submit = extractFn(app, 'submitPlacedOrder');
    assert(submit.indexOf('isDeliveryModalOpen()') !== -1, 'must detect open modal');
    assert(submit.indexOf('readDeliveryModal()') !== -1, 'must sync modal fields');
    assert(submit.indexOf('validateDeliveryDetails()') !== -1, 'must validate delivery');
});

test('confirm delivery does not close modal before submit outcome', function () {
    var confirm = extractFn(app, 'confirmDeliveryAndPlace');
    assert(confirm.indexOf('closeDeliveryModal()') === -1, 'must not close early');
    assert(confirm.indexOf('submitPlacedOrder()') !== -1, 'must submit');
});

test('delivery modal buttons are type button and not nested in checkout form', function () {
    assert(page.indexOf('id="pos-delivery-confirm"') !== -1, 'confirm button');
    assert(page.indexOf('type="button" class="munch-pos-place" id="pos-delivery-confirm"') !== -1, 'confirm uses type button');
    assert(page.indexOf('pos-delivery-modal') !== -1, 'delivery modal present');
    assert(page.indexOf("id='order_place'") === -1 && page.indexOf('id="order_place"') === -1, 'legacy checkout form must not be on POS index');
});

test('clear cart clears stale pending attempts', function () {
    var clear = extractFn(app, 'clearCart');
    assert(clear.indexOf('clearPendingAttempt()') !== -1, 'must clear pending attempt');
    assert(clear.indexOf('!state.orderSubmitting') !== -1, 'must not clear during submit');
});

test('handlePostedResult uses response classifier', function () {
    assert(app.indexOf('classifyPosSubmitResponse') !== -1, 'classifier wired');
    assert(app.indexOf("outcome.kind === 'validation' || outcome.kind === 'client'") !== -1, 'client errors skip recovery');
});

test('419 retry still posts after heartbeat failure', function () {
    var post = extractFn(app, 'postOrder');
    assert(post.indexOf('return postOrder(payload, true)') !== -1, 'must retry post');
    assert(app.indexOf('_http: 419') === -1, 'must not synthesize 419 uncertain responses');
});

test('boot ignores stale pending when cart is empty', function () {
    var restore = extractFn(app, 'restorePendingAttempt');
    assert(restore.indexOf('!state.cart.lines.length') !== -1, 'empty cart guard');
    var boot = extractFn(app, 'boot');
    assert(boot.indexOf('cartHasDeliveryDraft') !== -1, 'delivery draft guard');
});

test('delivery modal Enter on last field confirms delivery only', function () {
    assert(app.indexOf("els.deliveryModal.addEventListener('keydown'") !== -1, 'delivery keydown handler');
    assert(app.indexOf('confirmDeliveryAndPlace()') !== -1, 'enter confirms delivery');
    assert(app.indexOf('data-del-field') !== -1, 'scoped to delivery fields');
    assert(app.indexOf('if (els.deliveryModal && !els.deliveryModal.hidden) return;') !== -1, 'document enter ignores open delivery modal');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}
console.log(passed + ' passed');
