'use strict';

var fs = require('fs');
var path = require('path');

var root = path.join(__dirname, '../..');
var js = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-pos-app.js'), 'utf8');
var page = fs.readFileSync(path.join(root, 'resources/views/branch-views/pos/index.blade.php'), 'utf8');
var delivery = require(path.join(root, 'public/assets/admin/js/munch-pos-delivery.js'));

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

var orderTypes = ['dine_in', 'take_away', 'delivery', 'glovo', 'uber', 'bolt_food'];
var labels = {
    dine_in: 'Dine In',
    take_away: 'Takeaway',
    delivery: 'Delivery',
    glovo: 'Glovo',
    uber: 'Uber',
    bolt_food: 'Bolt Food'
};

orderTypes.forEach(function (type) {
    test('discount control is absent for ' + labels[type], function () {
        assert(page.indexOf('pos-discount-wrap') === -1, 'discount wrap must be gone from POS HTML');
        assert(page.indexOf('id="pos-discount"') === -1, 'discount input must be gone');
        assert(page.indexOf('pos-discount-type') === -1, 'discount type selector must be gone');
        assert(page.indexOf('add-discount') === -1, 'legacy discount modal must not be on Branch POS');
        assert(js.indexOf('function allowsDiscount') !== -1, 'allowsDiscount helper missing');
        assert(extractFn(js, 'allowsDiscount').indexOf('return false') !== -1, 'allowsDiscount must always be false');
        assert(extractFn(js, 'extraDiscount').indexOf('return 0') !== -1, 'extraDiscount must always be 0');
        assert(js.indexOf('extra_discount: 0') !== -1, 'payload must always send extra_discount 0');
        assert(js.indexOf("extra_discount_type: 'amount'") !== -1, 'payload must keep extra_discount_type amount');
        assert(js.indexOf('els.discountWrap.hidden = true') !== -1, 'any leftover wrap must stay hidden');
        assert(js.indexOf('function stripCashierDiscount') !== -1, 'hydrate must zero cashier discount');
        assert(js.indexOf("orderType: '" + type + "'") !== -1 || js.indexOf("'" + type + "'") !== -1, type + ' must still be a POS order type');
    });
});

test('hydrating an old cart cannot restore a non-zero active discount', function () {
    var stored = {
        lines: [{ productId: 1, quantity: 1 }],
        orderType: 'dine_in',
        discount: 500,
        discountType: 'percent',
        payment: 'cash',
        paid: '',
        deliveryFee: 0,
        address: {}
    };
    var next = delivery.hydrateCart({
        lines: [],
        orderType: 'take_away',
        discount: 0,
        discountType: 'amount',
        payment: 'cash',
        paid: '',
        deliveryFee: 0,
        address: {}
    }, stored);
    assert(next.discount === 0, 'hydrateCart restored discount ' + next.discount);
    assert(next.discountType === 'amount', 'hydrateCart restored discount type ' + next.discountType);

    var persisted = delivery.persistableCart({
        lines: stored.lines,
        orderType: 'take_away',
        discount: 250,
        discountType: 'percent',
        payment: 'cash',
        address: { contact_person_name: 'Jane' }
    });
    assert(persisted.discount === 0, 'persistableCart wrote discount ' + persisted.discount);
    assert(persisted.discountType === 'amount', 'persistableCart wrote discount type');

    var strip = extractFn(js, 'stripCashierDiscount');
    assert(strip.indexOf('cart.discount = 0') !== -1, 'stripCashierDiscount must zero discount');
    assert(js.indexOf('stripCashierDiscount(state.cart)') !== -1, 'boot/persist must strip cashier discount');
    var boot = extractFn(js, 'boot');
    assert(boot.indexOf('stripCashierDiscount(state.cart)') !== -1, 'boot hydrate must force discount to zero');
    var persist = extractFn(js, 'persistCart');
    assert(persist.indexOf('stripCashierDiscount(state.cart)') !== -1, 'persistCart must not store a discount');
    var enqueue = extractFn(js, 'enqueue');
    assert(enqueue.indexOf('payload.extra_discount = 0') !== -1, 'offline queue must not carry extra_discount');
    var replace = extractFn(js, 'replaceQueuedPayload');
    assert(replace.indexOf('payload.extra_discount = 0') !== -1, 'queued payload replace must zero extra_discount');
    var syncOne = extractFn(js, 'syncOne');
    assert(syncOne.indexOf('payload.extra_discount = 0') !== -1, 'offline sync must not introduce extra_discount');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}

console.log(passed + ' passed');
