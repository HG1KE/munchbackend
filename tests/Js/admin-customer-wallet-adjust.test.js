"use strict";

var fs = require('fs');
var path = require('path');

var root = path.join(__dirname, '../..');
var js = fs.readFileSync(path.join(root, 'public/assets/admin/js/customer-view.js'), 'utf8');
var page = fs.readFileSync(path.join(root, 'resources/views/admin-views/customer/customer-view.blade.php'), 'utf8');

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
    var needle = 'function ' + name;
    var start = source.indexOf(needle + '(');
    if (start < 0) start = source.indexOf(needle + ' ');
    if (start < 0) throw new Error(name + ' missing');
    var i = source.indexOf('{', start);
    var depth = 0;
    var quote = '';
    var escape = false;
    var j;
    for (j = i; j < source.length; j++) {
        var ch = source[j];
        if (quote) {
            if (escape) {
                escape = false;
            } else if (ch === '\\') {
                escape = true;
            } else if (ch === quote) {
                quote = '';
            }
            continue;
        }
        if (ch === '"' || ch === "'" || ch === '`') {
            quote = ch;
            continue;
        }
        if (ch === '{') depth += 1;
        if (ch === '}') {
            depth -= 1;
            if (depth === 0) return source.slice(start, j + 1);
        }
    }
    throw new Error(name + ' unclosed');
}

function loadHelpers() {
    var body = [
        extractFn(js, 'parseWalletAdjustAmount'),
        extractFn(js, 'isValidWalletAdjustAmount'),
        extractFn(js, 'resultingWalletAdjustBalance'),
        extractFn(js, 'debitExceedsWalletBalance'),
        extractFn(js, 'newWalletAdjustIdempotencyKey'),
        extractFn(js, 'buildWalletAdjustConfirmation'),
        extractFn(js, 'walletAdjustHistoryRowHtml'),
        'module.exports = { parseWalletAdjustAmount: parseWalletAdjustAmount, isValidWalletAdjustAmount: isValidWalletAdjustAmount, resultingWalletAdjustBalance: resultingWalletAdjustBalance, debitExceedsWalletBalance: debitExceedsWalletBalance, newWalletAdjustIdempotencyKey: newWalletAdjustIdempotencyKey, buildWalletAdjustConfirmation: buildWalletAdjustConfirmation, walletAdjustHistoryRowHtml: walletAdjustHistoryRowHtml };'
    ].join('\n');
    var Module = require('module');
    var m = new Module('wallet-adjust-helpers');
    m._compile(body, 'wallet-adjust-helpers.js');
    return m.exports;
}

var helpers = loadHelpers();

test('customer details page has Adjust Wallet action near wallet balance', function () {
    var balanceIndex = page.indexOf('id="customer-wallet-balance"');
    var buttonIndex = page.indexOf('translate(\'adjust_wallet\')');
    assert(balanceIndex > 0, 'wallet balance id missing');
    assert(buttonIndex > balanceIndex, 'Adjust Wallet must be near the wallet balance');
    assert(page.indexOf('id="adjust-wallet-modal"') > 0, 'adjust wallet modal missing');
    assert(page.indexOf('id="customer-wallet-history-body"') > 0, 'wallet history table missing');
});

test('modal contains type, amount, reason, and current balance', function () {
    assert(page.indexOf('id="adjust-wallet-type"') > 0, 'type field missing');
    assert(page.indexOf('value="credit"') > 0, 'credit option missing');
    assert(page.indexOf('value="debit"') > 0, 'debit option missing');
    assert(page.indexOf('id="adjust-wallet-amount"') > 0, 'amount field missing');
    assert(page.indexOf('id="adjust-wallet-reason"') > 0, 'reason field missing');
    assert(page.indexOf('id="adjust-wallet-current-balance-label"') > 0, 'current balance missing');
});

test('zero, negative, and malformed amounts are rejected client-side', function () {
    assert(helpers.isValidWalletAdjustAmount('0') === false, 'zero must be invalid');
    assert(helpers.isValidWalletAdjustAmount('0.00') === false, '0.00 must be invalid');
    assert(helpers.isValidWalletAdjustAmount('-10') === false, 'negative must be invalid');
    assert(helpers.isValidWalletAdjustAmount('abc') === false, 'malformed must be invalid');
    assert(helpers.isValidWalletAdjustAmount('10.1234') === false, 'excess decimals must be invalid');
    assert(helpers.isValidWalletAdjustAmount('25.50') === true, 'valid amount rejected');
});

test('resulting balance is computed for confirmation display only', function () {
    assert(helpers.resultingWalletAdjustBalance(100, 'credit', '25.5') === 125.5, 'credit math failed');
    assert(helpers.resultingWalletAdjustBalance(100, 'debit', '40') === 60, 'debit math failed');
    var text = helpers.buildWalletAdjustConfirmation({
        typeLabel: 'Type',
        typeValue: 'Credit',
        amountLabel: 'Amount',
        amount: '25',
        reasonLabel: 'Reason',
        reason: 'Goodwill',
        currentLabel: 'Current',
        currentBalance: 100,
        resultingLabel: 'Resulting',
        resultingBalance: 125
    });
    assert(text.indexOf('Credit') >= 0, 'confirmation missing type');
    assert(text.indexOf('25') >= 0, 'confirmation missing amount');
    assert(text.indexOf('Goodwill') >= 0, 'confirmation missing reason');
    assert(text.indexOf('100') >= 0, 'confirmation missing current balance');
    assert(text.indexOf('125') >= 0, 'confirmation missing resulting balance');
});

test('debit greater than balance is rejected client-side', function () {
    assert(helpers.debitExceedsWalletBalance(50, '50.01') === true, 'overdraft must be rejected');
    assert(helpers.debitExceedsWalletBalance(50, '50') === false, 'exact debit must be allowed');
});

test('submit is disabled while processing and retries reuse one idempotency key', function () {
    assert(js.indexOf("submitButton.prop('disabled', true)") >= 0, 'submit must disable while processing');
    assert(js.indexOf('if (submitting)') >= 0, 'duplicate submit guard missing');
    assert(js.indexOf('idempotency_key: idempotencyKey') >= 0, 'idempotency key must be sent');
    assert(js.indexOf('newWalletAdjustIdempotencyKey') >= 0, 'idempotency generator missing');
    var key = helpers.newWalletAdjustIdempotencyKey();
    assert(typeof key === 'string' && key.length >= 32, 'idempotency key too short');
});

test('successful adjustment refreshes balance and history without sending browser math', function () {
    assert(js.indexOf("data: {\n                    type: type,\n                    amount: amountValue,\n                    reason: reason,\n                    idempotency_key: idempotencyKey\n                }") >= 0 || js.indexOf('idempotency_key: idempotencyKey') >= 0, 'payload must not include resulting_balance');
    assert(js.indexOf('resulting_balance') === -1, 'browser resulting balance must not be posted');
    assert(js.indexOf("$('#customer-wallet-balance').text") >= 0, 'must refresh displayed balance');
    assert(js.indexOf('prependHistory') >= 0, 'must show the new wallet transaction');
    assert(js.indexOf("toastr.success") >= 0, 'must use existing success notification');
    assert(js.indexOf("modal.modal('hide')") >= 0, 'must close the modal');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}

console.log(passed + ' passed');
