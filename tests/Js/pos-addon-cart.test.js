'use strict';

var fs = require('fs');
var path = require('path');

var root = path.join(__dirname, '../..');
var js = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-pos-app.js'), 'utf8');
var createPage = fs.readFileSync(path.join(root, 'resources/views/admin-views/product/index.blade.php'), 'utf8');
var editPage = fs.readFileSync(path.join(root, 'resources/views/admin-views/product/edit.blade.php'), 'utf8');

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

function makeEngine() {
    var CFG = { labels: { addons: 'Addons', optional: 'optional', required: 'Required', add: 'Add' } };
    var state = {
        cart: { lines: [], orderType: 'take_away', discount: 0, discountType: 'amount', deliveryFee: 0 },
        productMap: {}
    };
    var opened = [];
    function persistCart() {}
    function refreshCartUi() {}
    function openModifiers(product) { opened.push(product.id); }
    function resolvedProductPrice(product) { return Number(product.price || 0); }
    function productDiscountAmount() { return 0; }
    function variationPrice() { return 0; }

    var productNeedsVariation;
    var posAddons;
    var productNeedsModifiers;
    var variationSelectionKey;
    var addonSelectionKey;
    var findMatchingVariationLine;
    var addSelectedVariations;
    var addSimple;
    var adjustProductQty;
    var lineUnit;
    var lineAddonTotal;
    var lineSubtotal;
    var addonLabels;
    var nextAddonQty;
    var selectedAddonsFromQuantities;

    eval('productNeedsVariation = ' + extractFn(js, 'productNeedsVariation'));
    eval('posAddons = ' + extractFn(js, 'posAddons'));
    eval('productNeedsModifiers = ' + extractFn(js, 'productNeedsModifiers'));
    eval('variationSelectionKey = ' + extractFn(js, 'variationSelectionKey'));
    eval('addonSelectionKey = ' + extractFn(js, 'addonSelectionKey'));
    eval('findMatchingVariationLine = ' + extractFn(js, 'findMatchingVariationLine'));
    eval('addSelectedVariations = ' + extractFn(js, 'addSelectedVariations'));
    eval('addSimple = ' + extractFn(js, 'addSimple'));
    eval('adjustProductQty = ' + extractFn(js, 'adjustProductQty'));
    eval('lineUnit = ' + extractFn(js, 'lineUnit'));
    eval('lineAddonTotal = ' + extractFn(js, 'lineAddonTotal'));
    eval('lineSubtotal = ' + extractFn(js, 'lineSubtotal'));
    eval('addonLabels = ' + extractFn(js, 'addonLabels'));
    eval('nextAddonQty = ' + extractFn(js, 'nextAddonQty'));
    eval('selectedAddonsFromQuantities = ' + extractFn(js, 'selectedAddonsFromQuantities'));

    return {
        state: state,
        opened: opened,
        posAddons: posAddons,
        productNeedsModifiers: productNeedsModifiers,
        addSelectedVariations: addSelectedVariations,
        addSimple: addSimple,
        adjustProductQty: adjustProductQty,
        lineAddonTotal: lineAddonTotal,
        lineSubtotal: lineSubtotal,
        addonLabels: addonLabels,
        nextAddonQty: nextAddonQty,
        selectedAddonsFromQuantities: selectedAddonsFromQuantities
    };
}

var cheese = { id: 8, name: 'Extra Cheese', price: 50, tax: 0 };
var sauce = { id: 9, name: 'Extra Sauce', price: 30, tax: 0 };

var burgerOff = {
    id: 41,
    name: 'Chicken Burger',
    price: 450,
    allow_addon_on_pos: false,
    addons: [cheese, sauce],
    variations: []
};

var burgerOn = {
    id: 42,
    name: 'Chicken Burger',
    price: 450,
    allow_addon_on_pos: true,
    addons: [cheese, sauce],
    variations: []
};

var burgerOnEmpty = {
    id: 43,
    name: 'Plain Burger',
    price: 400,
    allow_addon_on_pos: true,
    addons: [],
    variations: []
};

test('toggle off hides addon selector', function () {
    var engine = makeEngine();
    engine.state.productMap[burgerOff.id] = burgerOff;
    assert(engine.posAddons(burgerOff).length === 0, 'disabled products must expose no POS addons');
    assert(engine.productNeedsModifiers(burgerOff) === false, 'disabled products must not need the selector');
    engine.adjustProductQty(burgerOff.id, 1);
    assert(engine.opened.length === 0, 'toggle off must add without opening addons');
    assert(engine.state.cart.lines.length === 1, 'plain add still works');
});

test('toggle on shows addon selector', function () {
    var engine = makeEngine();
    engine.state.productMap[burgerOn.id] = burgerOn;
    assert(engine.posAddons(burgerOn).length === 2, 'enabled products should expose assigned addons');
    engine.adjustProductQty(burgerOn.id, 1);
    assert(engine.opened.length === 1, 'first tap must open the addon selector');
    assert(engine.state.cart.lines.length === 0, 'opening the selector must not add a silent line');
});

test('no addons skips selector even if toggle on', function () {
    var engine = makeEngine();
    engine.state.productMap[burgerOnEmpty.id] = burgerOnEmpty;
    engine.adjustProductQty(burgerOnEmpty.id, 1);
    assert(engine.opened.length === 0, 'no addons means the existing instant-add path');
    assert(engine.state.cart.lines.length === 1, 'empty addon list still adds the product');
});

test('addon combinations stay on separate lines', function () {
    var engine = makeEngine();
    engine.addSelectedVariations(burgerOn, [], 1, [cheese.id], { 8: 1 });
    engine.addSelectedVariations(burgerOn, [], 1, [sauce.id], { 9: 1 });
    assert(engine.state.cart.lines.length === 2, 'different addons must not merge');
    engine.addSelectedVariations(burgerOn, [], 1, [cheese.id], { 8: 1 });
    assert(engine.state.cart.lines.length === 2, 'matching addon combo should merge');
    assert(engine.state.cart.lines[0].quantity === 2, 'matching Extra Cheese qty should increment');
    assert(engine.state.cart.lines[1].quantity === 1, 'Extra Sauce qty must stay 1');
});

test('selected addon price is included', function () {
    var engine = makeEngine();
    engine.state.productMap[burgerOn.id] = burgerOn;
    engine.addSelectedVariations(burgerOn, [], 1, [cheese.id], { 8: 1 });
    var line = engine.state.cart.lines[0];
    assert(engine.lineAddonTotal(line) === 50, 'Extra Cheese should add 50');
    assert(engine.lineSubtotal(line) === 500, 'burger 450 + cheese 50');
});

test('addon quantity starts at 0 and never goes negative', function () {
    var engine = makeEngine();
    assert(engine.nextAddonQty(0, 0) === 0, 'default is 0');
    assert(engine.nextAddonQty(0, 1) === 1, '+ from 0 is 1');
    assert(engine.nextAddonQty(1, 1) === 2, '+ from 1 is 2');
    assert(engine.nextAddonQty(2, 1) === 3, '+ from 2 is 3');
    assert(engine.nextAddonQty(3, -1) === 2, '- from 3 is 2');
    assert(engine.nextAddonQty(1, -1) === 0, '- from 1 is 0');
    assert(engine.nextAddonQty(0, -1) === 0, 'quantity never goes below 0');
});

test('quantity 0 is excluded and extra cheese x3 is 150', function () {
    var engine = makeEngine();
    engine.state.productMap[burgerOn.id] = burgerOn;
    var picked = engine.selectedAddonsFromQuantities({ 8: 3, 9: 0 });
    assert(picked.addon_id.length === 1, 'qty 0 addon must be dropped');
    assert(picked.addon_id[0] === 8, 'Extra Cheese should remain');
    assert(picked.addon_quantities[8] === 3, 'Extra Cheese qty must stay 3');
    engine.addSelectedVariations(burgerOn, [], 1, picked.addon_id, picked.addon_quantities);
    var line = engine.state.cart.lines[0];
    assert(engine.lineAddonTotal(line) === 150, '50 x 3 must be 150');
    assert(engine.lineSubtotal(line) === 600, 'burger 450 + cheese 150');
    assert(engine.addonLabels(line).indexOf('Extra Cheese × 3') !== -1, 'receipt/kitchen must show Extra Cheese × 3');
});

test('multiple addons keep independent quantities', function () {
    var engine = makeEngine();
    engine.state.productMap[burgerOn.id] = burgerOn;
    var picked = engine.selectedAddonsFromQuantities({ 8: 2, 9: 1 });
    assert(picked.addon_id.length === 2, 'both addons with qty > 0 must stay');
    assert(picked.addon_quantities[8] === 2, 'cheese qty 2');
    assert(picked.addon_quantities[9] === 1, 'sauce qty 1');
    engine.addSelectedVariations(burgerOn, [], 1, picked.addon_id, picked.addon_quantities);
    var line = engine.state.cart.lines[0];
    assert(engine.lineAddonTotal(line) === 130, '50x2 + 30x1 must be 130');
    assert(engine.lineSubtotal(line) === 580, 'burger 450 + addons 130');
});

test('different addon quantities stay on separate lines', function () {
    var engine = makeEngine();
    engine.addSelectedVariations(burgerOn, [], 1, [cheese.id], { 8: 1 });
    engine.addSelectedVariations(burgerOn, [], 1, [cheese.id], { 8: 2 });
    assert(engine.state.cart.lines.length === 2, 'cheese x1 and cheese x2 must not merge');
    engine.addSelectedVariations(burgerOn, [], 1, [cheese.id], { 8: 2 });
    assert(engine.state.cart.lines.length === 2, 'matching cheese x2 should stack');
    assert(engine.state.cart.lines[1].quantity === 2, 'matching cheese x2 product qty should increment');
});

test('payload still uses existing addon_id fields', function () {
    assert(js.indexOf('addon_id: line.addon_id || []') !== -1, 'checkout must send selected addon ids');
    assert(js.indexOf('addon_quantities: line.addon_quantities || {}') !== -1, 'checkout must send addon quantities');
    assert(js.indexOf('data-addon-qty') !== -1, 'selector must expose addon quantity');
    assert(js.indexOf('data-addon-delta') !== -1, 'selector must have +/- controls');
    assert(js.indexOf('collectSelectedAddons(card)') !== -1, 'confirm must collect qty > 0 only');
    assert(js.indexOf('name="pos-addon"') === -1, 'checkbox-only addon rows must be gone');
    assert(js.indexOf('window.location.reload()') === -1, 'must not reload');
});

test('admin toggle defaults off on create and persists on edit', function () {
    assert(createPage.indexOf('name="allow_addon_on_pos"') !== -1, 'create form missing toggle');
    assert(!/name="allow_addon_on_pos"[^>]*checked/.test(createPage), 'new products must default OFF');
    assert(editPage.indexOf("(\$product->allow_addon_on_pos ?? false) ? 'checked' : ''") !== -1
        || editPage.indexOf("($product->allow_addon_on_pos ?? false) ? 'checked' : ''") !== -1, 'edit form must restore the saved value');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}

console.log(passed + ' passed');
