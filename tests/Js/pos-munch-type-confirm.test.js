'use strict';

var fs = require('fs');
var path = require('path');

var root = path.join(__dirname, '../..');
var js = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-pos-app.js'), 'utf8');
var page = fs.readFileSync(path.join(root, 'resources/views/branch-views/pos/index.blade.php'), 'utf8');
var css = fs.readFileSync(path.join(root, 'public/assets/admin/css/munch-pos.css'), 'utf8');

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

function modalMarkup() {
    var start = page.indexOf('id="pos-munch-type-modal"');
    assert(start !== -1, 'confirm modal missing');
    return page.slice(start, start + 900);
}

var persistCalls = 0;
var renderCalls = 0;
var lastGridKey = 'stale';
var CFG = {
    labels: {
        munchDineInConfirm: 'Are you sure this is a Munch Dine In order?',
        munchTakeawayConfirm: 'Are you sure this is a Munch Takeaway order?'
    }
};
function L(key, fallback) {
    return (CFG.labels && CFG.labels[key]) || fallback || key;
}
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
var munchWalkInConfirmMessage;
var applyOrderType;
eval('shouldConfirmMunchWalkInType = ' + extractFn(js, 'shouldConfirmMunchWalkInType'));
eval('munchWalkInConfirmMessage = ' + extractFn(js, 'munchWalkInConfirmMessage'));
eval('applyOrderType = ' + extractFn(js, 'applyOrderType'));

test('dine in and takeaway require confirmation', function () {
    assert(shouldConfirmMunchWalkInType('dine_in') === true, 'dine in must confirm');
    assert(shouldConfirmMunchWalkInType('take_away') === true, 'takeaway must confirm');
});

test('dine in confirmation uses the Dine In sentence', function () {
    assert(
        munchWalkInConfirmMessage('dine_in') === 'Are you sure this is a Munch Dine In order?',
        'wrong dine in copy: ' + munchWalkInConfirmMessage('dine_in')
    );
    assert(page.indexOf("translate('Are you sure this is a Munch Dine In order?')") !== -1, 'dine in label missing');
    var open = extractFn(js, 'openMunchTypeConfirm');
    assert(open.indexOf('munchWalkInConfirmMessage(type)') !== -1, 'open must set the type-specific title');
    assert(open.indexOf('els.munchTypeTitle.textContent') !== -1, 'title node must be updated');
});

test('takeaway confirmation uses the Takeaway sentence', function () {
    assert(
        munchWalkInConfirmMessage('take_away') === 'Are you sure this is a Munch Takeaway order?',
        'wrong takeaway copy: ' + munchWalkInConfirmMessage('take_away')
    );
    assert(page.indexOf("translate('Are you sure this is a Munch Takeaway order?')") !== -1, 'takeaway label missing');
});

test('confirmation copy never shows X', function () {
    var modal = modalMarkup();
    assert(modal.indexOf('Munch X') === -1, 'modal still contains Munch X');
    assert(page.indexOf('Munch X') === -1, 'page still contains Munch X');
    assert(js.indexOf('Munch X') === -1, 'js still contains Munch X');
    assert(munchWalkInConfirmMessage('dine_in').indexOf(' X ') === -1, 'dine in copy contains X');
    assert(munchWalkInConfirmMessage('take_away').indexOf(' X ') === -1, 'takeaway copy contains X');
});

test('No and Yes sit side by side with No left and Yes right', function () {
    var modal = modalMarkup();
    var actionsStart = modal.indexOf('munch-pos-dialog__actions');
    var noBtn = modal.indexOf('id="pos-munch-type-no"');
    var yesBtn = modal.indexOf('id="pos-munch-type-yes"');
    var typeCssStart = css.indexOf('.munch-pos-munch-type-modal .munch-pos-dialog__actions');
    var typeCss = typeCssStart === -1 ? '' : css.slice(typeCssStart, typeCssStart + 900);
    assert(actionsStart !== -1, 'dialog actions missing');
    assert(noBtn !== -1 && yesBtn !== -1, 'Yes/No buttons missing');
    assert(noBtn < yesBtn, 'No must be the left button');
    assert(modal.indexOf('class="munch-pos-clear" id="pos-munch-type-no"') !== -1, 'No must be the secondary button');
    assert(modal.indexOf('class="munch-pos-place" id="pos-munch-type-yes"') !== -1, 'Yes must be the primary button');
    assert(modal.indexOf('munch-pos-modal') !== -1, 'must reuse existing munch-pos-modal');
    assert(modal.indexOf('munch-pos-modal__card munch-pos-delivery-modal munch-pos-munch-type-modal') !== -1, 'must reuse existing modal card styling');
    assert(css.indexOf('.munch-pos-dialog__actions') !== -1, 'must reuse existing dialog action layout');
    assert(typeCss.indexOf('grid-template-columns: 1fr 1fr') !== -1, 'No and Yes must share one equal-width row');
    assert(typeCss.indexOf('grid-column: auto') !== -1, 'Yes must not span the full row');
    assert(typeCss.indexOf('background: #fff') !== -1, 'No must have a visible button background');
    assert(typeCss.indexOf('background: var(--munch-pos-red)') !== -1, 'Yes must stay the primary red button');
    assert(typeCss.indexOf('min-height: 52px') !== -1, 'both buttons must be touch-friendly');
    assert(typeCss.indexOf('border-radius: 14px') !== -1, 'both buttons must share the POS radius');
    assert(typeCss.indexOf('font-weight: 800') !== -1, 'both buttons must share the POS font treatment');
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
    assert(page.indexOf('id="pos-munch-type-modal"') !== -1, 'confirm modal missing');
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

    pendingMunchOrderType = 'take_away';
    closed = false;
    applied = [];
    confirm();
    assert(applied.join() === 'take_away', 'Yes must apply takeaway');
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
