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
                name: 'Chicken Burger',
                variations: [
                    { id: 'Size::Small', group: 'Size', label: 'Small', option_price: 0, channel_prices: { uber: 550, glovo: 560, bolt_food: 570 } },
                    { id: 'Size::Large', group: 'Size', label: 'Large', option_price: 200, channel_prices: { uber: 750, glovo: 760, bolt_food: 770 } }
                ],
                addons: [
                    { id: 8, name: 'Extra Cheese', price: 100, channel_prices: { uber: 150, glovo: 160, bolt_food: 170 } },
                    { id: 9, name: 'Extra Sauce', price: 40, channel_prices: { uber: 50, glovo: 55, bolt_food: 60 } }
                ]
            },
            { id: 11, name: 'Fries', variations: [], addons: [] }
        ],
        selectedProducts: { 10: true },
        channels: { pos: false, uber: true, glovo: true, bolt_food: true },
        variationValues: {},
        addonValues: {},
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
        'addonValueKey',
        'productVariationOptions',
        'productAddonOptions',
        'variationOptionChannelPrice',
        'variationCurrentAmount',
        'variationCurrentPrice',
        'findVariationOption',
        'addonOptionChannelPrice',
        'addonCurrentAmount',
        'findAddonOption',
        'renderVariationEditors',
        'renderAddonEditors',
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
        source + '\nreturn { renderVariationEditors: renderVariationEditors, renderAddonEditors: renderAddonEditors, renderProductListItem: renderProductListItem, productAddonOptions: productAddonOptions, usesPerProductPrices: usesPerProductPrices };'
    )(bulk, CHANNELS, MARKETPLACE_CHANNELS, CHANNEL_LABELS, CFG, els, escapeHtml, escapeAttr, money);

    return {
        bulk: bulk,
        renderVariationEditors: api.renderVariationEditors,
        renderAddonEditors: api.renderAddonEditors,
        renderProductListItem: api.renderProductListItem,
        productAddonOptions: api.productAddonOptions,
        usesPerProductPrices: api.usesPerProductPrices
    };
}

test('addon rows render in a separate ADDON PRICES section', function () {
    var ui = makeUi();
    var item = ui.renderProductListItem(ui.bulk.products[0]);
    assert(item.indexOf('2 addons') !== -1, 'addon badge missing');
    assert(item.indexOf('2 variations') !== -1, 'variation badge should remain');

    var table = ui.renderAddonEditors(ui.bulk.products[0]);
    assert(table.indexOf('Addon prices') !== -1, 'Addon prices label missing');
    assert(table.indexOf('Extra Cheese') !== -1, 'Extra Cheese row missing');
    assert(table.indexOf('Extra Sauce') !== -1, 'Extra Sauce row missing');
    assert(table.indexOf('munch-pricing-addon-table') !== -1, 'addon table missing');
    assert(table.indexOf('Variation level') === -1, 'addon table must not mix variation rows');
});

test('variations and addons stay in separate sections', function () {
    var ui = makeUi();
    var variations = ui.renderVariationEditors(ui.bulk.products[0]);
    var addons = ui.renderAddonEditors(ui.bulk.products[0]);
    assert(variations.indexOf('Small') !== -1, 'Small variation missing');
    assert(variations.indexOf('Extra Cheese') === -1, 'addon must not appear in variation table');
    assert(addons.indexOf('Small') === -1, 'variation must not appear in addon table');
});

test('uber addon price field renders', function () {
    var ui = makeUi();
    var table = ui.renderAddonEditors(ui.bulk.products[0]);
    assert(table.indexOf('>Uber<') !== -1, 'Uber header missing');
    assert(table.indexOf('data-channel="uber"') !== -1, 'Uber input missing');
    assert(table.indexOf('data-bulk-addon-value') !== -1, 'addon input missing');
});

test('glovo addon price field renders', function () {
    var ui = makeUi();
    var table = ui.renderAddonEditors(ui.bulk.products[0]);
    assert(table.indexOf('>Glovo<') !== -1, 'Glovo header missing');
    assert(table.indexOf('data-channel="glovo"') !== -1, 'Glovo input missing');
});

test('bolt food addon price field renders', function () {
    var ui = makeUi();
    var table = ui.renderAddonEditors(ui.bulk.products[0]);
    assert(table.indexOf('>Bolt Food<') !== -1, 'Bolt Food header missing');
    assert(table.indexOf('data-channel="bolt_food"') !== -1, 'Bolt Food input missing');
});

test('channel selection controls addon marketplace columns', function () {
    var ui = makeUi();
    ui.bulk.channels.glovo = false;
    ui.bulk.channels.bolt_food = false;
    var table = ui.renderAddonEditors(ui.bulk.products[0]);
    assert(table.indexOf('>Uber<') !== -1, 'Uber should remain');
    assert(table.indexOf('>Glovo<') === -1, 'Glovo should be hidden');
    assert(table.indexOf('>Bolt Food<') === -1, 'Bolt Food should be hidden');
    assert(table.indexOf('data-channel="uber"') !== -1, 'Uber input should remain');
    assert(table.indexOf('data-channel="glovo"') === -1, 'Glovo input should be hidden');

    ui.bulk.channels.uber = false;
    var hint = ui.renderAddonEditors(ui.bulk.products[0]);
    assert(hint.indexOf('Select Uber, Glovo or Bolt Food') !== -1, 'missing channel hint');
    assert(hint.indexOf('munch-pricing-addon-table') === -1, 'table should hide without marketplace channels');
});

test('products without addons do not show an empty addon section', function () {
    var ui = makeUi();
    assert(ui.productAddonOptions(11).length === 0, 'plain product should have no addons');
    assert(ui.renderAddonEditors(ui.bulk.products[1]) === '', 'plain product should not render an addon table');
    var item = ui.renderProductListItem(ui.bulk.products[1]);
    assert(item.indexOf('addon-badge') === -1, 'plain product should not show an addon badge');
});

test('search payload channel_prices become visible addon placeholders', function () {
    var ui = makeUi();
    var table = ui.renderAddonEditors(ui.bulk.products[0]);
    assert(table.indexOf('placeholder="150"') !== -1, 'Uber current price should be the placeholder');
    assert(table.indexOf('placeholder="160"') !== -1, 'Glovo current price should be the placeholder');
    assert(table.indexOf('placeholder="170"') !== -1, 'Bolt Food current price should be the placeholder');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}

console.log(passed + ' passed');
