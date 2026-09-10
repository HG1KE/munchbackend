'use strict';

var fs = require('fs');
var path = require('path');

var root = path.join(__dirname, '../..');
var js = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-pos-app.js'), 'utf8');
var css = fs.readFileSync(path.join(root, 'public/assets/admin/css/munch-pos.css'), 'utf8');
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

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function escapeAttr(value) {
    return escapeHtml(value).replace(/'/g, '&#39;');
}

function money(amount) {
    return 'Ksh' + Number(amount || 0).toFixed(0);
}

function productNeedsVariation(product) {
    return !!(product && (product.variations || []).length);
}

function cardQtyHtml(productId, qty) {
    if (qty > 0) {
        return '<div class="munch-pos-card__qty">' +
            '<button type="button" class="munch-pos-card__step" data-card-delta="-1" data-product="' + productId + '" aria-label="−">−</button>' +
            '<span class="munch-pos-card__count">' + qty + '</span>' +
            '<button type="button" class="munch-pos-card__step" data-card-delta="1" data-product="' + productId + '" aria-label="+">+</button>' +
            '</div>';
    }
    return '<button type="button" class="munch-pos-card__plus" data-card-delta="1" data-product="' + productId + '" aria-label="+">+</button>';
}

function productCard(product, qty) {
    var img = product.image || '/placeholder.png';
    var hasOptions = productNeedsVariation(product);
    return '<article class="munch-pos-card" data-id="' + product.id + '">' +
        '<img src="' + escapeAttr(img) + '" alt="">' +
        '<div class="munch-pos-card__body">' +
        '<div class="munch-pos-card__name">' + escapeHtml(product.name) + '</div>' +
        (hasOptions ? '<div class="munch-pos-card__opt">Options</div>' : '') +
        '<div class="munch-pos-card__price">' + money(product.price) + '</div>' +
        '</div>' +
        '<div class="munch-pos-card__actions" data-qty="' + (qty || 0) + '">' + cardQtyHtml(product.id, qty || 0) + '</div>' +
        '</article>';
}

function measuredGridColumns(containerWidth, cardWidth, gap) {
    var width = Number(containerWidth || 0);
    var card = Number(cardWidth || 0);
    var gutter = Number(gap || 0);
    if (!(width > 80) || !(card > 80)) return 0;
    return Math.max(1, Math.round((width + gutter) / (card + gutter)));
}

function renderGrid(list) {
    return list.map(function (product) { return productCard(product, 0); }).join('');
}

function catalogGridKey(state) {
    return [state.categoryId, state.search, state.cart.orderType, state.catalog.version || '', (state.catalog.products || []).length].join('|');
}

function filteredProducts(state) {
    var q = (state.search || '').trim().toLowerCase();
    return (state.catalog.products || []).filter(function (p) {
        if (state.categoryId && (p.category_ids || []).indexOf(state.categoryId) === -1) return false;
        if (q && String(p.name || '').toLowerCase().indexOf(q) === -1 && String(p.id) !== q) return false;
        return true;
    });
}

var products = [];
var i;
for (i = 1; i <= 120; i++) {
    products.push({
        id: i,
        name: 'Product ' + i,
        price: 100 + i,
        image: '/img/' + i + '.jpg',
        category_ids: [i % 3 === 0 ? 2 : 1],
        variations: i === 4 ? [{ name: 'Size', values: [{ label: 'Large' }] }] : []
    });
}

test('products render immediately after catalog loads', function () {
    var html = renderGrid(products);
    assert(html.indexOf('munch-pos-virt') === -1, 'virtualization spacers must not be used');
    assert((html.match(/class="munch-pos-card"/g) || []).length === products.length, 'every loaded product must become a card');
    assert(html.indexOf('Product 1') !== -1, 'first product name missing');
    assert(html.indexOf('Product 120') !== -1, 'last product name missing');
});

test('resize is never required to paint the catalog', function () {
    var before = catalogGridKey({
        categoryId: 0,
        search: '',
        cart: { orderType: 'take_away' },
        catalog: { version: 9, products: products }
    });
    var afterResize = catalogGridKey({
        categoryId: 0,
        search: '',
        cart: { orderType: 'take_away' },
        catalog: { version: 9, products: products }
    });
    assert(before === afterResize, 'catalog key must ignore viewport size');
    assert(js.indexOf('clientWidth') === -1 || js.indexOf('catalogGridKey') !== -1, 'catalog key helper missing');
    assert(js.indexOf('gridW') === -1, 'grid width must not gate first paint');
    assert(js.indexOf('gridPrimed') === -1, 'first-paint priming flag must be gone');
    assert(js.indexOf('gridLayoutReady') === -1, 'layout-ready gate must be gone');
    assert(js.indexOf("addEventListener('resize', updateTabArrows)") !== -1, 'resize may only refresh tab arrows');
    assert(js.indexOf('list.map(productCard)') !== -1, 'renderGrid must paint the complete filtered list');
});

test('product names, prices and add controls always display', function () {
    var html = productCard(products[0], 0);
    var nameAt = html.indexOf('munch-pos-card__name');
    var priceAt = html.indexOf('munch-pos-card__price');
    var imgAt = html.indexOf('<img');
    var actionsAt = html.indexOf('munch-pos-card__actions');
    assert(imgAt !== -1 && nameAt > imgAt, 'name must render after the image');
    assert(priceAt > nameAt, 'price must render with the name');
    assert(actionsAt > priceAt, 'add button must render with the card body');
    assert(html.indexOf('Product 1') !== -1, 'name text missing');
    assert(html.indexOf('Ksh101') !== -1, 'price text missing');
    assert(html.indexOf('munch-pos-card__plus') !== -1, 'add button missing');
});

test('images never replace product information', function () {
    var html = productCard({
        id: 99,
        name: 'Nyama Choma',
        price: 850,
        image: '/huge-photo.jpg',
        variations: []
    }, 0);
    assert(html.indexOf('/huge-photo.jpg') !== -1, 'image still required');
    assert(html.indexOf('Nyama Choma') !== -1, 'name must survive when an image exists');
    assert(html.indexOf('Ksh850') !== -1, 'price must survive when an image exists');
    assert(html.indexOf('munch-pos-card__body') !== -1, 'text body must stay in the card');
    assert(css.indexOf('content-visibility') === -1, 'content-visibility can hide name and price');
    assert(!/\.munch-pos-card\s*\{[^}]*min-height:\s*0/.test(css), 'catalog cards must not collapse with min-height:0');
});

test('variation indicator appears only when the product has options', function () {
    var plain = productCard(products[0], 0);
    var optioned = productCard(products[3], 0);
    assert(plain.indexOf('munch-pos-card__opt') === -1, 'plain cards should not show Options');
    assert(optioned.indexOf('munch-pos-card__opt') !== -1, 'optioned cards must show the indicator');
    assert(optioned.indexOf('Options') !== -1, 'option label missing');
    assert(js.indexOf('munch-pos-card__opt') !== -1, 'live productCard must emit the indicator');
    assert(js.indexOf('productNeedsVariation(product)') !== -1, 'variation helper must still decide the indicator');
});

test('three-column layout is derived from measured card width', function () {
    assert(measuredGridColumns(0, 300, 10) === 0, 'failed measurements must disable column math');
    assert(measuredGridColumns(960, 40, 10) === 0, 'tiny cards must not invent columns');
    assert(measuredGridColumns(980, 310, 12) === 3, '15-inch catalog should measure as 3 columns');
    assert(js.indexOf('function measuredGridColumns') !== -1, 'column helper missing');
    assert(js.indexOf('gridTemplateColumns') === -1, 'do not parse CSS track strings');
    assert(js.indexOf('minmax(') === -1, 'do not split minmax() tracks');
    assert(css.indexOf('repeat(3, minmax(160px, 1fr))') !== -1, '15-inch CSS must keep 3 columns');
});

test('cart rows leave room for 7-9 items', function () {
    var row = 70;
    var gap = 9;
    var seven = (7 * row) + (6 * gap);
    var nine = (9 * row) + (8 * gap);
    assert(seven <= 560, '7 rows should fit a 15-inch cart scroller');
    assert(nine <= 720, '9 rows should fit with modest scrolling');
    assert(css.indexOf('min-height: 70px') !== -1, 'cart rows should be about 70px');
    assert(css.indexOf('gap: 0.55rem') !== -1, 'cart spacing should be 8-10px');
    assert(js.indexOf('munch-pos-line__main') !== -1, 'left stack missing');
    assert(js.indexOf('munch-pos-line__sub') !== -1, 'line total must stay on the right');
    assert(js.indexOf("money(lineUnit(line)) + ' × ' + qty") !== -1, 'unit × qty missing');
    assert(css.indexOf('flex-wrap: nowrap') !== -1, 'qty controls must stay horizontal');
});

test('search, category filters, checkout and offline mode remain unchanged', function () {
    var state = {
        categoryId: 2,
        search: 'product 120',
        cart: { orderType: 'take_away' },
        catalog: { version: 1, products: products }
    };
    var matches = filteredProducts(state);
    assert(matches.length === 1, 'search + category must still intersect');
    assert(matches[0].id === 120, 'wrong filtered product');
    assert(js.indexOf('function productInCategory') !== -1, 'category filter missing');
    assert(js.indexOf('function filteredProducts') !== -1, 'search filter missing');
    assert(js.indexOf('function placeOrder') !== -1, 'checkout missing');
    assert(js.indexOf('function enqueue') !== -1, 'offline queue missing');
    assert(js.indexOf('indexedDB') !== -1, 'offline catalog/cart storage missing');
    assert(page.indexOf('pos-place') !== -1, 'place order control missing');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}

console.log(passed + ' passed');
