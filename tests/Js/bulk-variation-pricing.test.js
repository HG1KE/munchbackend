'use strict';

var fs = require('fs');
var path = require('path');

var root = path.join(__dirname, '../..');
var js = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-product-pricing.js'), 'utf8');

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

function makeUi() {
    var CHANNELS = ['pos', 'uber', 'glovo', 'bolt_food'];
    var MARKETPLACE_CHANNELS = ['uber', 'glovo', 'bolt_food'];
    var CHANNEL_LABELS = { pos: 'POS', uber: 'Uber', glovo: 'Glovo', bolt_food: 'Bolt Food', default: 'Default' };
    var CFG = { currency: '' };
    var els = { modalBody: null };
    var bulk = {
        tab: 'price',
        advanced: false,
        perChannel: false,
        action: 'set_exact',
        products: [
            {
                id: 10,
                name: '4 Piece Wings',
                variations: [
                    { id: 'Wings%20Type::BBQ', group: 'Wings Type', label: 'BBQ', option_price: 0, channel_prices: { uber: 250, glovo: 260, bolt_food: 270 } },
                    { id: 'Wings%20Type::Sweet%20Chilli', group: 'Wings Type', label: 'Sweet Chilli', option_price: 0, channel_prices: { uber: 350, glovo: 360, bolt_food: 370 } }
                ]
            },
            { id: 11, name: 'Fries', variations: [] }
        ],
        selectedProducts: { 10: true },
        channels: { pos: false, uber: true, glovo: true, bolt_food: true },
        variationValues: {},
        productValues: {},
        currentRows: []
    };

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }
    function escapeAttr(value) {
        return escapeHtml(value);
    }
    function money(value) {
        var n = Number(value || 0);
        return CFG.currency + n.toLocaleString(undefined, { maximumFractionDigits: 2 });
    }
    var fnNames = [
        'selectedChannels',
        'selectedMarketplaceChannels',
        'variationValueKey',
        'productVariationOptions',
        'variationOptionChannelPrice',
        'variationCurrentAmount',
        'variationCurrentPrice',
        'findVariationOption',
        'renderVariationEditors',
        'renderProductListItem',
        'usesPerProductPrices',
        'productCurrentPriceLabel'
    ];
    var source = fnNames.map(function (name) {
        return extractFn(js, name);
    }).join('\n');
    var api = new Function(
        'bulk',
        'CHANNELS',
        'MARKETPLACE_CHANNELS',
        'CHANNEL_LABELS',
        'CFG',
        'els',
        'escapeHtml',
        'escapeAttr',
        'money',
        source + '\nreturn { renderVariationEditors: renderVariationEditors, renderProductListItem: renderProductListItem, productVariationOptions: productVariationOptions, usesPerProductPrices: usesPerProductPrices };'
    )(bulk, CHANNELS, MARKETPLACE_CHANNELS, CHANNEL_LABELS, CFG, els, escapeHtml, escapeAttr, money);

    return {
        bulk: bulk,
        renderVariationEditors: api.renderVariationEditors,
        renderProductListItem: api.renderProductListItem,
        productVariationOptions: api.productVariationOptions,
        usesPerProductPrices: api.usesPerProductPrices
    };
}

test('variation rows render under the product', function () {
    var ui = makeUi();
    var item = ui.renderProductListItem(ui.bulk.products[0]);
    assert(item.indexOf('data-bulk-product-item="10"') !== -1, 'product item missing');
    assert(item.indexOf('data-bulk-product-variations="10"') !== -1, 'variation mount missing');
    assert(item.indexOf('2 variations') !== -1, 'variation badge missing');

    var table = ui.renderVariationEditors(ui.bulk.products[0]);
    assert(table.indexOf('munch-pricing-variation-table') !== -1, 'variation table missing');
    assert(table.indexOf('BBQ') !== -1, 'BBQ row missing');
    assert(table.indexOf('Sweet Chilli') !== -1, 'Sweet Chilli row missing');
    assert(table.indexOf('Variation') !== -1, 'Variation column missing');
});

test('uber variation price field renders', function () {
    var ui = makeUi();
    var table = ui.renderVariationEditors(ui.bulk.products[0]);
    assert(table.indexOf('>Uber<') !== -1, 'Uber header missing');
    assert(table.indexOf('data-channel="uber"') !== -1, 'Uber input missing');
});

test('glovo variation price field renders', function () {
    var ui = makeUi();
    var table = ui.renderVariationEditors(ui.bulk.products[0]);
    assert(table.indexOf('>Glovo<') !== -1, 'Glovo header missing');
    assert(table.indexOf('data-channel="glovo"') !== -1, 'Glovo input missing');
});

test('bolt food variation price field renders', function () {
    var ui = makeUi();
    var table = ui.renderVariationEditors(ui.bulk.products[0]);
    assert(table.indexOf('>Bolt Food<') !== -1, 'Bolt Food header missing');
    assert(table.indexOf('data-channel="bolt_food"') !== -1, 'Bolt Food input missing');
});

test('channel selection controls marketplace columns', function () {
    var ui = makeUi();
    ui.bulk.channels.glovo = false;
    ui.bulk.channels.bolt_food = false;
    var table = ui.renderVariationEditors(ui.bulk.products[0]);
    assert(table.indexOf('>Uber<') !== -1, 'Uber should remain');
    assert(table.indexOf('>Glovo<') === -1, 'Glovo should be hidden');
    assert(table.indexOf('>Bolt Food<') === -1, 'Bolt Food should be hidden');
    assert(table.indexOf('data-channel="uber"') !== -1, 'Uber input should remain');
    assert(table.indexOf('data-channel="glovo"') === -1, 'Glovo input should be hidden');

    ui.bulk.channels.uber = false;
    ui.bulk.channels.glovo = false;
    ui.bulk.channels.bolt_food = false;
    var hint = ui.renderVariationEditors(ui.bulk.products[0]);
    assert(hint.indexOf('Select Uber, Glovo or Bolt Food') !== -1, 'missing channel hint');
    assert(hint.indexOf('munch-pricing-variation-table') === -1, 'table should hide without marketplace channels');
});

test('products without variations keep the product editor', function () {
    var ui = makeUi();
    assert(ui.productVariationOptions(11).length === 0, 'plain product should have no variations');
    assert(ui.renderVariationEditors(ui.bulk.products[1]) === '', 'plain product should not render a variation table');
    var item = ui.renderProductListItem(ui.bulk.products[1]);
    assert(item.indexOf('variation-badge') === -1, 'plain product should not show a variation badge');
    assert(item.indexOf('data-bulk-product-variations="11"') !== -1, 'plain product still has a mount');
    assert(js.indexOf('data-bulk-product-value') !== -1, 'product-level New Price remains');
    assert(ui.usesPerProductPrices() === true, 'default Set Exact mode keeps per-product prices');
});

test('search payload channel_prices become visible placeholders', function () {
    var ui = makeUi();
    var table = ui.renderVariationEditors(ui.bulk.products[0]);
    assert(table.indexOf('placeholder="250"') !== -1, 'Uber current price should be the placeholder');
    assert(table.indexOf('placeholder="260"') !== -1, 'Glovo current price should be the placeholder');
    assert(table.indexOf('placeholder="270"') !== -1, 'Bolt Food current price should be the placeholder');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}

console.log(passed + ' passed');
