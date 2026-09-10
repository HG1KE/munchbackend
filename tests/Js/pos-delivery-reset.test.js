'use strict';

var path = require('path');
var delivery = require(path.join(
    __dirname,
    '../../public/assets/admin/js/munch-pos-delivery.js'
));

var failed = 0;
var passed = 0;

function assert(cond, message) {
    if (!cond) throw new Error(message || 'assertion failed');
}

function dirtyCart(overrides) {
    return Object.assign({
        lines: [{ productId: 1, quantity: 1 }],
        orderType: 'delivery',
        note: 'keep-me',
        discount: 10,
        discountType: 'amount',
        payment: 'cash',
        paid: '500',
        deliveryFee: 200,
        address: {
            contact_person_name: 'Jane Doe',
            contact_person_number: '0700000000',
            address: 'Westlands'
        },
        rider: {
            rider_name: 'Alex',
            rider_phone: '0711111111'
        }
    }, overrides || {});
}

function assertBlankDelivery(cart, label) {
    assert(cart.deliveryFee === 0, label + ': fee should be 0, got ' + cart.deliveryFee);
    assert(cart.address.contact_person_name === '', label + ': name leaked');
    assert(cart.address.contact_person_number === '', label + ': phone leaked');
    assert(cart.address.address === '', label + ': address leaked');
    assert(cart.rider.rider_name === '', label + ': rider name leaked');
    assert(cart.rider.rider_phone === '', label + ': rider phone leaked');
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

test('applyEmptyDelivery clears every customer delivery field', function () {
    var cart = dirtyCart();
    var previousAddress = cart.address;
    delivery.applyEmptyDelivery(cart);
    assertBlankDelivery(cart, 'reset');
    assert(cart.address !== previousAddress, 'reset must replace the address object');
    assert(cart.lines.length === 1, 'reset must not clear cart lines');
    assert(cart.note === 'keep-me', 'reset must not clear unrelated cart fields');
    assert(cart.orderType === 'delivery', 'reset must keep the selected order type');
});

test('persistableCart never writes delivery details and does not mutate live state', function () {
    var cart = dirtyCart();
    var stored = delivery.persistableCart(cart);
    assertBlankDelivery(stored, 'idb snapshot');
    assert(stored.lines.length === 1, 'idb snapshot should keep in-progress lines');
    assert(stored.note === 'keep-me', 'idb snapshot should keep unrelated cart fields');
    assert(cart.deliveryFee === 200, 'live cart fee was mutated');
    assert(cart.address.contact_person_name === 'Jane Doe', 'live cart name was mutated');
    assert(cart.rider.rider_name === 'Alex', 'live cart rider was mutated');
});

test('IndexedDB leftover delivery is not restored on a new sale', function () {
    var stored = dirtyCart({ lines: [] });
    var next = delivery.hydrateCart({
        lines: [],
        orderType: 'take_away',
        discount: 0,
        discountType: 'amount',
        payment: 'cash',
        paid: '',
        deliveryFee: 0,
        address: {},
        rider: {}
    }, stored);
    assert(next.lines.length === 0, 'empty cart should stay empty');
    assertBlankDelivery(next, 'new sale hydrate');
});

test('IndexedDB leftover delivery is not restored with in-progress lines', function () {
    var stored = dirtyCart();
    var next = delivery.hydrateCart({ lines: [], deliveryFee: 0, address: {}, rider: {} }, stored);
    assert(next.lines.length === 1, 'in-progress lines should restore');
    assert(next.note === 'keep-me', 'unrelated cart fields should restore');
    assertBlankDelivery(next, 'draft hydrate');
});

test('offline queue payload is independent of the cleared form snapshot', function () {
    var cart = dirtyCart();
    var queued = {
        contact_person_name: cart.address.contact_person_name,
        contact_person_number: cart.address.contact_person_number,
        address: cart.address.address,
        rider_name: cart.rider.rider_name,
        rider_phone: cart.rider.rider_phone,
        delivery_charge: cart.deliveryFee
    };
    delivery.applyEmptyDelivery(cart);
    assertBlankDelivery(cart, 'after queue');
    assert(queued.contact_person_name === 'Jane Doe', 'queued name should stay on the order payload');
    assert(queued.delivery_charge === 200, 'queued fee should stay on the order payload');
    assert(queued.rider_name === 'Alex', 'queued rider should stay on the order payload');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}
console.log(passed + ' passed');
