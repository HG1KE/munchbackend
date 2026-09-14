'use strict';

var fs = require('fs');
var path = require('path');

var root = path.join(__dirname, '../..');
var js = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-pos-app.js'), 'utf8');
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

var persistCalls = 0;
var renderCalls = 0;
var lastGridKey = 'stale';
var state = {
    productMap: { 1: {}, 2: {} },
    cart: {
        orderType: 'glovo',
        lines: [{ productId: 1 }, { productId: 2 }]
    }
};

function persistCart() { persistCalls += 1; }
function scheduleRender() { renderCalls += 1; }
function productChannelAvailable(product) { return !!product; }

var shouldConfirmMunchWalkInType;
var applyOrderType;
var confirmMunchWalkInType;
var rejectMunchWalkInType;
eval('shouldConfirmMunchWalkInType = ' + extractFn(js, 'shouldConfirmMunchWalkInType'));
eval('applyOrderType = ' + extractFn(js, 'applyOrderType'));
eval('confirmMunchWalkInType = ' + extractFn(js, 'confirmMunchWalkInType'));
eval('rejectMunchWalkInType = ' + extractFn(js, 'rejectMunchWalkInType'));

test('dine in and takeaway require the Munch X confirmation', function () {
    assert(shouldConfirmMunchWalkInType('dine_in') === true, 'dine in must confirm');
    assert(shouldConfirmMunchWalkInType('take_away') === true, 'takeaway must confirm');
});

test('delivery and marketplace types skip the confirmation', function () {
    assert(shouldConfirmMunchWalkInType('delivery') === false, 'delivery must not confirm');
    assert(shouldConfirmMunchWalkInType('glovo') === false, 'glovo must not confirm');
    assert(shouldConfirmMunchWalkInType('uber') === false, 'uber must not confirm');
    assert(shouldConfirmMunchWalkInType('bolt_food') === false, 'bolt food must not confirm');
});

test('type chips open the existing POS confirm modal for dine in and takeaway', function () {
    var bind = extractFn(js, 'bind');
    assert(bind.indexOf('shouldConfirmMunchWalkInType(type)') !== -1, 'type click must gate on Munch walk-in types');
    assert(bind.indexOf('openMunchTypeConfirm(type)') !== -1, 'dine in/takeaway must open the confirm modal');
    assert(bind.indexOf('applyOrderType(type)') !== -1, 'other types still apply immediately');
    assert(bind.indexOf("if (type === state.cart.orderType) return") !== -1, 're-tapping the active type must stay put');
    assert(page.indexOf('id="pos-munch-type-modal"') !== -1, 'confirm modal missing');
    assert(page.indexOf('id="pos-munch-type-yes"') !== -1, 'Yes button missing');
    assert(page.indexOf('id="pos-munch-type-no"') !== -1, 'No button missing');
    assert(page.indexOf("translate('Are you sure this is a Munch X order?')") !== -1, 'confirm copy missing');
    assert(page.indexOf("translate('Yes')") !== -1, 'Yes label missing');
    assert(page.indexOf("translate('No')") !== -1, 'No label missing');
    assert(page.indexOf('munch-pos-modal__card munch-pos-delivery-modal') !== -1, 'must reuse existing modal card styling');
    assert(page.indexOf('class="munch-pos-place" id="pos-munch-type-yes"') !== -1, 'Yes must use the existing primary button');
    assert(page.indexOf('class="munch-pos-clear" id="pos-munch-type-no"') !== -1, 'No must use the existing secondary button');
    assert(page.indexOf('munch-pos-dialog__actions') !== -1, 'must reuse existing dialog actions');
});

test('Yes continues into the selected dine in or takeaway flow', function () {
    var pendingMunchOrderType = 'dine_in';
    var closed = false;
    var applied = [];
    function closeMunchTypeConfirm() {
        closed = true;
        pendingMunchOrderType = '';
    }
    function applyOrderType(type) { applied.push(type); }
    var confirm;
    eval('confirm = ' + extractFn(js, 'confirmMunchWalkInType'));
    confirm();
    assert(closed === true, 'Yes must close the confirm modal');
    assert(applied.join() === 'dine_in', 'Yes must apply dine in');
});

test('No cancels the selection and leaves the previous order type', function () {
    persistCalls = 0;
    renderCalls = 0;
    state.cart.orderType = 'glovo';
    var pendingMunchOrderType = 'take_away';
    var closed = false;
    var applied = [];
    function closeMunchTypeConfirm() {
        closed = true;
        pendingMunchOrderType = '';
    }
    function applyOrderType(type) { applied.push(type); }
    var reject;
    eval('reject = ' + extractFn(js, 'rejectMunchWalkInType'));
    reject();
    assert(closed === true, 'No must close the confirm modal');
    assert(applied.length === 0, 'No must not apply the selected type');
    assert(state.cart.orderType === 'glovo', 'previous type must remain selected');
    assert(persistCalls === 0, 'No must not persist a type change');
});

test('applying a confirmed type updates the cart like a normal type change', function () {
    persistCalls = 0;
    renderCalls = 0;
    lastGridKey = 'stale';
    state.cart.orderType = 'uber';
    state.cart.lines = [{ productId: 1 }, { productId: 2 }];
    applyOrderType('take_away');
    assert(state.cart.orderType === 'take_away', 'confirmed type must stick');
    assert(lastGridKey === '', 'catalog must refresh after type change');
    assert(persistCalls === 1, 'confirmed type must persist');
    assert(renderCalls === 1, 'confirmed type must re-render');
});

test('marketplace order-number validation is unchanged', function () {
    var place = extractFn(js, 'placeOrder');
    var confirm = extractFn(js, 'confirmMarketplaceAndPlace');
    assert(place.indexOf('isMarketplaceOrderType()') !== -1, 'Place Order still branches for marketplace');
    assert(place.indexOf('openMarketplaceModal()') !== -1, 'marketplace still opens the order-number modal');
    assert(confirm.indexOf('validateMarketplaceOrderNumber()') !== -1, 'confirm still validates the platform number');
    assert(js.indexOf('function normalizePlatformOrderNumber') !== -1, 'uppercase helper must remain');
    assert(page.indexOf('id="pos-platform-modal"') !== -1, 'platform modal must remain');
    assert(page.indexOf('id="pos-platform-number"') !== -1, 'platform input must remain');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}

console.log(passed + ' passed');
