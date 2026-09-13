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

function flavourLine(label) {
    return [{
        name: 'Flavour',
        required: 'on',
        values: { label: [label] }
    }];
}

function makeEngine() {
    var CFG = {
        labels: {
            choose: 'Choose',
            chooseFlavour: 'Choose Flavour',
            variations: 'Variations',
            options: 'Options'
        }
    };
    var state = {
        cart: { lines: [], orderType: 'take_away', discount: 0, discountType: 'amount', deliveryFee: 0 },
        productMap: {}
    };
    var opened = [];
    function persistCart() {}
    function refreshCartUi() {}
    function openModifiers(product) { opened.push(product.id); }

    var productNeedsVariation;
    var posAddons;
    var productNeedsModifiers;
    var variationBadge;
    var variationSelectionKey;
    var addonSelectionKey;
    var findMatchingVariationLine;
    var addSelectedVariations;
    var lastLineIndex;
    var productQty;
    var addSimple;
    var adjustProductQty;

    eval('productNeedsVariation = ' + extractFn(js, 'productNeedsVariation'));
    eval('posAddons = ' + extractFn(js, 'posAddons'));
    eval('productNeedsModifiers = ' + extractFn(js, 'productNeedsModifiers'));
    eval('variationBadge = ' + extractFn(js, 'variationBadge'));
    eval('variationSelectionKey = ' + extractFn(js, 'variationSelectionKey'));
    eval('addonSelectionKey = ' + extractFn(js, 'addonSelectionKey'));
    eval('findMatchingVariationLine = ' + extractFn(js, 'findMatchingVariationLine'));
    eval('addSelectedVariations = ' + extractFn(js, 'addSelectedVariations'));
    eval('lastLineIndex = ' + extractFn(js, 'lastLineIndex'));
    eval('productQty = ' + extractFn(js, 'productQty'));
    eval('addSimple = ' + extractFn(js, 'addSimple'));
    eval('adjustProductQty = ' + extractFn(js, 'adjustProductQty'));

    return {
        CFG: CFG,
        state: state,
        opened: opened,
        productNeedsVariation: productNeedsVariation,
        variationBadge: variationBadge,
        addSelectedVariations: addSelectedVariations,
        addSimple: addSimple,
        adjustProductQty: adjustProductQty,
        productQty: productQty
    };
}

var drumstick = {
    id: 11,
    name: '1 Drumstick',
    price: 180,
    variations: [{
        name: 'Flavour',
        required: 'on',
        type: 'single',
        values: [
            { label: 'BBQ', optionPrice: 0 },
            { label: 'Sweet Chilli', optionPrice: 0 }
        ]
    }]
};

var chips = {
    id: 22,
    name: 'Chips',
    price: 150,
    variations: []
};

test('product with variations always opens selector', function () {
    var engine = makeEngine();
    engine.state.productMap[drumstick.id] = drumstick;

    engine.adjustProductQty(drumstick.id, 1);
    assert(engine.opened.length === 1, 'first tap must open the selector');
    assert(engine.state.cart.lines.length === 0, 'first tap must not add a silent cart line');

    engine.addSelectedVariations(drumstick, flavourLine('BBQ'), 1);
    engine.adjustProductQty(drumstick.id, 1);
    assert(engine.opened.length === 2, 'second tap must open the selector again');
    assert(engine.state.cart.lines.length === 1, 'second tap must not auto-add another BBQ');
    assert(engine.state.cart.lines[0].quantity === 1, 'previous BBQ qty must stay unchanged until a flavour is chosen');
});

test('previous variation is never auto-selected', function () {
    var modal = extractFn(js, 'openModifiers');
    assert(!/\schecked/.test(modal) && !/checked=/.test(modal), 'selector inputs must start unchecked');
    assert(modal.indexOf(':checked') !== -1, 'confirm still reads the cashier\'s selection');
    assert(modal.indexOf('addSelectedVariations(product, variations, qty, selectedAddons.addon_id, selectedAddons.addon_quantities)') !== -1, 'modal confirm must go through merge helper');
    assert(js.indexOf('lastLineIndex(productId)') !== -1, 'last-line helper still used for decrement');

    var plus = extractFn(js, 'adjustProductQty');
    var addPath = plus.slice(plus.indexOf('if (delta > 0)'), plus.indexOf('var idx = lastLineIndex'));
    assert(addPath.indexOf('openModifiers(product)') !== -1, 'add path must open the selector');
    assert(addPath.indexOf('quantity +=') === -1, 'add path must never increment the last flavour');
    assert(addPath.indexOf('lastLineIndex') === -1, 'add path must not reuse the last cart line');
});

test('BBQ then Sweet Chilli produces two separate cart lines', function () {
    var engine = makeEngine();
    engine.addSelectedVariations(drumstick, flavourLine('BBQ'), 1);
    engine.addSelectedVariations(drumstick, flavourLine('Sweet Chilli'), 1);

    assert(engine.state.cart.lines.length === 2, 'different flavours must stay on separate lines');
    assert(engine.state.cart.lines[0].variations[0].values.label[0] === 'BBQ', 'first line should stay BBQ');
    assert(engine.state.cart.lines[0].quantity === 1, 'BBQ qty should stay 1');
    assert(engine.state.cart.lines[1].variations[0].values.label[0] === 'Sweet Chilli', 'second line should be Sweet Chilli');
    assert(engine.state.cart.lines[1].quantity === 1, 'Sweet Chilli qty should stay 1');
});

test('BBQ selected twice increments only the BBQ line', function () {
    var engine = makeEngine();
    engine.addSelectedVariations(drumstick, flavourLine('BBQ'), 1);
    engine.addSelectedVariations(drumstick, flavourLine('Sweet Chilli'), 1);
    engine.addSelectedVariations(drumstick, flavourLine('BBQ'), 1);

    assert(engine.state.cart.lines.length === 2, 'matching BBQ must merge instead of duplicating');
    assert(engine.state.cart.lines[0].variations[0].values.label[0] === 'BBQ', 'BBQ should remain the first line');
    assert(engine.state.cart.lines[0].quantity === 2, 'BBQ qty should become 2');
    assert(engine.state.cart.lines[1].variations[0].values.label[0] === 'Sweet Chilli', 'Sweet Chilli must stay separate');
    assert(engine.state.cart.lines[1].quantity === 1, 'Sweet Chilli qty must stay 1');
});

test('Sweet Chilli selected twice increments only the Sweet Chilli line', function () {
    var engine = makeEngine();
    engine.addSelectedVariations(drumstick, flavourLine('BBQ'), 1);
    engine.addSelectedVariations(drumstick, flavourLine('Sweet Chilli'), 1);
    engine.addSelectedVariations(drumstick, flavourLine('Sweet Chilli'), 2);

    assert(engine.state.cart.lines.length === 2, 'matching Sweet Chilli must merge instead of duplicating');
    assert(engine.state.cart.lines[0].quantity === 1, 'BBQ qty must stay 1');
    assert(engine.state.cart.lines[1].quantity === 3, 'Sweet Chilli qty should become 3');
});

test('products without variations still add instantly', function () {
    var engine = makeEngine();
    engine.state.productMap[chips.id] = chips;

    engine.adjustProductQty(chips.id, 1);
    engine.adjustProductQty(chips.id, 1);

    assert(engine.opened.length === 0, 'plain products must not open the selector');
    assert(engine.state.cart.lines.length === 1, 'plain products must merge into one line');
    assert(engine.state.cart.lines[0].productId === chips.id, 'wrong product added');
    assert(engine.state.cart.lines[0].quantity === 2, 'plain product qty should increment immediately');
    assert(engine.state.cart.lines[0].has_modifiers === false, 'plain lines stay modifier-free');
});

test('cards show a flavour badge only when variations exist', function () {
    var engine = makeEngine();
    assert(engine.variationBadge(chips) === '', 'plain products must not show a badge');
    assert(engine.variationBadge(drumstick) === 'Choose Flavour', 'single flavour group should say Choose Flavour');
    assert(engine.variationBadge({
        variations: [{ name: 'Wings Type' }, { name: 'Heat' }]
    }) === '2 Variations', 'multiple groups should show the count');
    assert(js.indexOf('function modifierBadge') !== -1, 'live productCard must use modifierBadge');
    assert(js.indexOf('escapeHtml(modifierBadge(product))') !== -1, 'badge must render on the card');
});

test('marketplace variation prices can replace option extras', function () {
    assert(js.indexOf('function variationOptionExtra') !== -1, 'marketplace extra helper missing');
    assert(js.indexOf('function variationOptionDisplayPrice') !== -1, 'marketplace display helper missing');
    assert(js.indexOf('opt.channel_prices || opt.channelPrices') !== -1, 'catalog channel prices unused');
});

test('checkout, pricing, discounts, reports and kitchen printing remain unchanged', function () {
    assert(js.indexOf('function placeOrder') !== -1, 'checkout missing');
    assert(js.indexOf('function buildPayload') !== -1, 'order payload missing');
    assert(js.indexOf('paid_amount: grandTotal()') !== -1, 'checkout total missing');
    assert(js.indexOf('extra_discount: allowsDiscount() ? Number(state.cart.discount || 0) : 0') !== -1, 'discount payload missing');
    assert(js.indexOf('function extraDiscount') !== -1, 'discount helper missing');
    assert(js.indexOf('function lineUnit') !== -1, 'line pricing missing');
    assert(js.indexOf('function variationPrice') !== -1, 'variation pricing missing');
    assert(js.indexOf('function kitchenTicketHtml') !== -1, 'kitchen ticket missing');
    assert(js.indexOf('function receiptTicketHtml') !== -1, 'receipt ticket missing');
    assert(js.indexOf('function printOneTicket') !== -1, 'print helper missing');
    assert(js.indexOf('function enqueue') !== -1, 'offline queue missing');
    assert(page.indexOf('pos-place') !== -1, 'place order control missing');
    assert(page.indexOf('pos-success-kitchen') !== -1, 'kitchen print control missing');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}

console.log(passed + ' passed');
