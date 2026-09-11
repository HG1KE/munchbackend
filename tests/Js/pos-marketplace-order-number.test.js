'use strict';

var fs = require('fs');
var path = require('path');

var root = path.join(__dirname, '../..');
var js = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-pos-app.js'), 'utf8');
var page = fs.readFileSync(path.join(root, 'resources/views/branch-views/pos/index.blade.php'), 'utf8');
var ticket = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-receipt-ticket.js'), 'utf8');

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
                continue;
            }
            if (ch === '\\') {
                escape = true;
                continue;
            }
            if (ch === quote) quote = '';
            continue;
        }
        if (ch === '"' || ch === "'") {
            quote = ch;
            continue;
        }
        if (ch === '{') depth += 1;
        else if (ch === '}') {
            depth -= 1;
            if (depth === 0) return source.slice(start, j + 1);
        }
    }
    throw new Error(name + ' unclosed');
}

var CFG = {
    labels: {
        glovoOrderNumber: 'Enter Glovo Order Number',
        uberOrderNumber: 'Enter Uber Order Number',
        boltFoodOrderNumber: 'Enter Bolt Food Order Number',
        invalidPhone: 'Enter a valid 10-digit phone number.',
        customerName: 'Customer Name',
        customerPhone: 'Customer Phone',
        deliveryAddress: 'Delivery Address'
    }
};
var state = { cart: { orderType: 'uber', address: {} } };

var isMarketplaceOrderType;
var normalizePlatformOrderNumber;
var marketplaceOrderNumberPrompt;
var invalidPhone;
var validateDeliveryDetails;
eval('isMarketplaceOrderType = ' + extractFn(js, 'isMarketplaceOrderType'));
eval('normalizePlatformOrderNumber = ' + extractFn(js, 'normalizePlatformOrderNumber'));
eval('marketplaceOrderNumberPrompt = ' + extractFn(js, 'marketplaceOrderNumberPrompt'));
eval('invalidPhone = ' + extractFn(js, 'invalidPhone'));
eval('validateDeliveryDetails = ' + extractFn(js, 'validateDeliveryDetails'));

test('marketplace place order opens the order-number modal', function () {
    var place = extractFn(js, 'placeOrder');
    assert(place.indexOf('isMarketplaceOrderType()') !== -1, 'Place Order must branch for marketplace');
    assert(place.indexOf('openMarketplaceModal()') !== -1, 'marketplace must open the order-number modal');
    assert(place.indexOf('submitPlacedOrder()') !== -1, 'non-marketplace still submits');
    assert(page.indexOf('id="pos-platform-modal"') !== -1, 'platform modal missing');
    assert(page.indexOf('id="pos-platform-number"') !== -1, 'platform input missing');
    assert(page.indexOf('Enter Glovo Order Number') !== -1, 'Glovo prompt missing');
    assert(page.indexOf('Enter Uber Order Number') !== -1, 'Uber prompt missing');
    assert(page.indexOf('Enter Bolt Food Order Number') !== -1, 'Bolt Food prompt missing');
});

test('walk-in types skip the marketplace modal', function () {
    state.cart.orderType = 'take_away';
    assert(isMarketplaceOrderType() === false, 'take away is not marketplace');
    state.cart.orderType = 'dine_in';
    assert(isMarketplaceOrderType() === false, 'dine in is not marketplace');
    state.cart.orderType = 'delivery';
    assert(isMarketplaceOrderType() === false, 'delivery is not marketplace');
    state.cart.orderType = 'glovo';
    assert(isMarketplaceOrderType() === true, 'glovo is marketplace');
    state.cart.orderType = 'uber';
    assert(isMarketplaceOrderType() === true, 'uber is marketplace');
    state.cart.orderType = 'bolt_food';
    assert(isMarketplaceOrderType() === true, 'bolt food is marketplace');
    var place = extractFn(js, 'placeOrder');
    assert(place.indexOf("orderType === 'delivery'") !== -1, 'delivery still uses its own modal');
    assert(place.indexOf('openDeliveryModal()') !== -1, 'delivery modal still opens');
});

test('empty marketplace number cannot submit', function () {
    var confirm = extractFn(js, 'confirmMarketplaceAndPlace');
    assert(confirm.indexOf('validateMarketplaceOrderNumber()') !== -1, 'confirm must validate');
    assert(confirm.indexOf('showMarketplaceError(error)') !== -1, 'empty number must keep the modal and show an error');
    assert(confirm.indexOf('submitPlacedOrder()') !== -1, 'valid number still submits once');
    var submit = extractFn(js, 'submitPlacedOrder');
    assert(submit.indexOf('validateMarketplaceOrderNumber()') !== -1, 'submit must re-check the platform number');
    assert(js.indexOf('platform_order_number: isMarketplaceOrderType() ? readMarketplaceOrderNumber() : \'\'') !== -1
        || js.indexOf('platform_order_number: isMarketplaceOrderType() ? readMarketplaceOrderNumber() : ""') !== -1, 'payload must send the platform number');
});

test('lowercase marketplace numbers become uppercase', function () {
    assert(normalizePlatformOrderNumber('abc123') === 'ABC123', 'abc123');
    assert(normalizePlatformOrderNumber('aB12c') === 'AB12C', 'mixed case');
    assert(normalizePlatformOrderNumber('abc-123xy') === 'ABC-123XY', 'hyphenated');
    assert(normalizePlatformOrderNumber('123456') === '123456', 'numeric stays numeric');
    state.cart.orderType = 'glovo';
    assert(marketplaceOrderNumberPrompt() === 'Enter Glovo Order Number', 'glovo prompt');
    state.cart.orderType = 'uber';
    assert(marketplaceOrderNumberPrompt() === 'Enter Uber Order Number', 'uber prompt');
    state.cart.orderType = 'bolt_food';
    assert(marketplaceOrderNumberPrompt() === 'Enter Bolt Food Order Number', 'bolt prompt');
    assert(js.indexOf('normalizePlatformOrderNumber(this.value)') !== -1, 'input must uppercase as the cashier types');
});

test('delivery phone must be exactly 10 digits', function () {
    assert(invalidPhone('0712345678') === false, '10 digits valid');
    assert(invalidPhone('0798765432') === false, '10 digits valid');
    assert(invalidPhone('0112345678') === false, '01 numbers valid');
    assert(invalidPhone('071234567') === true, '9 digits invalid');
    assert(invalidPhone('07123456789') === true, '11 digits invalid');
    assert(invalidPhone('07123ABC78') === true, 'letters invalid');
    assert(invalidPhone('+254712345678') === true, '+254 invalid');
    assert(invalidPhone('') === true, 'empty invalid');
    state.cart.orderType = 'delivery';
    state.cart.address = { contact_person_name: 'Jane', contact_person_number: '071234567', address: 'Nyali' };
    assert(validateDeliveryDetails() === 'Enter a valid 10-digit phone number.', '9 digits rejected before submit');
    state.cart.address.contact_person_number = '0712345678';
    assert(validateDeliveryDetails() === null, '10 digits accepted');
    assert(page.indexOf('maxlength="10"') !== -1, 'phone field should advertise 10 digits');
    assert(page.indexOf('inputmode="numeric"') !== -1, 'numeric keypad');
    assert(js.indexOf('window.location.reload()') === -1, 'must not reload');
});

test('receipts and offline payload keep the platform number', function () {
    assert(ticket.indexOf('job.platform_order_number') !== -1, 'receipt must print the platform number');
    assert(js.indexOf('platform_order_number: (payload && payload.platform_order_number) || \'\'') !== -1
        || js.indexOf('platform_order_number: (payload && payload.platform_order_number) || ""') !== -1, 'offline snapshot must keep the number');
    assert(js.indexOf('platform_order_number: order.platform_order_number || \'\'') !== -1
        || js.indexOf('platform_order_number: order.platform_order_number || ""') !== -1, 'reprint must keep the number');
    assert(js.indexOf('bindSubmitControl(els.platformConfirm, confirmMarketplaceAndPlace)') !== -1, 'confirm uses the existing submit lock');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}

console.log(passed + ' passed');
