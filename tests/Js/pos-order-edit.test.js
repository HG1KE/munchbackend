'use strict';

var fs = require('fs');
var path = require('path');

var root = path.join(__dirname, '../..');
var rules = require(path.join(root, 'public/assets/admin/js/munch-pos-order-edit.js'));
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

function job(overrides) {
    return Object.assign({
        orderId: 115217,
        kitchenPrinted: false,
        receiptPrinted: false,
        salesChannel: 'takeaway',
        order_status: 'delivered'
    }, overrides || {});
}

test('edit enabled when neither printed', function () {
    assert(rules.canEditPostedOrder(job()), 'expected edit enabled');
    assert(rules.editBlockedReason(job()) === '', 'expected no reason');
});

test('edit disabled when kitchen printed', function () {
    var printed = job({ kitchenPrinted: true });
    assert(!rules.canEditPostedOrder(printed), 'kitchen print should lock edit');
    assert(rules.editBlockedReason(printed) === rules.PRINTED_MESSAGE, 'wrong kitchen reason');
});

test('edit disabled when customer receipt printed', function () {
    var printed = job({ receiptPrinted: true });
    assert(!rules.canEditPostedOrder(printed), 'receipt print should lock edit');
    assert(rules.editBlockedReason(printed) === rules.PRINTED_MESSAGE, 'wrong receipt reason');
});

test('edit disabled when both are printed', function () {
    var printed = job({ kitchenPrinted: true, receiptPrinted: true });
    assert(!rules.canEditPostedOrder(printed), 'both prints should lock edit');
});

test('cancelled orders cannot be edited', function () {
    var cancelled = job({ order_status: 'canceled' });
    assert(!rules.canEditPostedOrder(cancelled), 'cancelled should not edit');
    assert(rules.editBlockedReason(cancelled) === rules.CANCELLED_MESSAGE, 'wrong cancel reason');
});

test('marketplace orders cannot be edited', function () {
    assert(!rules.canEditPostedOrder(job({ salesChannel: 'glovo' })), 'glovo editable');
    assert(!rules.canEditPostedOrder(job({ salesChannel: 'uber' })), 'uber editable');
});

test('cancelled receipt blocked', function () {
    var cancelled = job({ order_status: 'canceled' });
    assert(!rules.canPrintCustomerReceipt(cancelled), 'cancelled receipt should be blocked');
    assert(rules.canPrintCustomerReceipt(job()), 'open order should print receipt');
    assert(!rules.canPrintKitchenTicket(cancelled), 'cancelled kitchen should stay blocked');
    assert(rules.RECEIPT_CANCELLED_MESSAGE.indexOf('customer receipt') !== -1, 'missing receipt message');
});

test('success modal and view orders wire edit and receipt guards', function () {
    assert(page.indexOf('id="pos-success-edit"') !== -1, 'missing Edit Order button');
    assert(page.indexOf('id="pos-success-edit-hint"') !== -1, 'missing edit hint');
    assert(app.indexOf('function startEditPostedOrder') !== -1, 'missing startEditPostedOrder');
    assert(app.indexOf('OrderRules.canEditPostedOrder') !== -1, 'app does not use shared rules');
    assert(app.indexOf('receipt_printed || isCancelledOrder(order)') !== -1, 'view orders receipt not blocked');
    assert(app.indexOf("kind === 'receipt' && (job.receiptPrinted || isCancelledJob(job))") !== -1, 'printOneTicket receipt guard missing');
    assert(app.indexOf('rememberPostedCart') !== -1, 'missing posted cart snapshot');
    assert(app.indexOf('editingOrder.clientUuid') !== -1, 'edit must reuse client uuid');
    assert(app.indexOf("payload.action === 'update'") !== -1, 'missing update action');
    assert(app.indexOf('function dismissPlacedOrder') !== -1, 'done/close path missing');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}

console.log(passed + ' passed');
