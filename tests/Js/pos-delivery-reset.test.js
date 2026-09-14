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
        }
    }, overrides || {});
}

function assertBlankDelivery(cart, label) {
    assert(cart.deliveryFee === 0, label + ': fee should be 0, got ' + cart.deliveryFee);
    assert(cart.address.contact_person_name === '', label + ': name leaked');
    assert(cart.address.contact_person_number === '', label + ': phone leaked');
    assert(cart.address.address === '', label + ': address leaked');
    assert(!cart.rider, label + ': rider state should be removed');
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
    var cart = dirtyCart({ rider: { rider_name: 'Alex', rider_phone: '0711111111' } });
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
    assert(stored.discount === 0, 'idb snapshot must not restore a cashier discount');
    assert(stored.discountType === 'amount', 'idb snapshot discount type must stay amount');
    assert(cart.deliveryFee === 200, 'live cart fee was mutated');
    assert(cart.address.contact_person_name === 'Jane Doe', 'live cart name was mutated');
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
        address: {}
    }, stored);
    assert(next.lines.length === 0, 'empty cart should stay empty');
    assertBlankDelivery(next, 'new sale hydrate');
});

test('IndexedDB leftover delivery is not restored with in-progress lines', function () {
    var stored = dirtyCart();
    var next = delivery.hydrateCart({ lines: [], deliveryFee: 0, address: {} }, stored);
    assert(next.lines.length === 1, 'in-progress lines should restore');
    assert(next.note === 'keep-me', 'unrelated cart fields should restore');
    assertBlankDelivery(next, 'draft hydrate');
});

test('delivery phone normalizes spaces and rejects +254', function () {
    assert(delivery.normalizePosDeliveryPhone('0712 345 678') === '0712345678', 'spaces');
    assert(delivery.canonicalPosDeliveryPhone('0712-345-678') === '0712345678', 'dashes');
    assert(delivery.isValidPosDeliveryPhone('0712345678') === true, 'plain 10');
    assert(delivery.isValidPosDeliveryPhone('0712 345 678') === true, 'spaced 10');
    assert(delivery.isValidPosDeliveryPhone('071234567') === false, '9 digits');
    assert(delivery.isValidPosDeliveryPhone('07123456789') === false, '11 digits');
    assert(delivery.isValidPosDeliveryPhone('+254712345678') === false, '+254');
    assert(delivery.canonicalPosDeliveryPhone('+254712345678') === '', 'must not coerce country format');
    assert(delivery.posDeliveryPhoneError('') === delivery.PHONE_REQUIRED, 'empty required');
    assert(delivery.posDeliveryPhoneError('071234567') === delivery.PHONE_ERROR, 'invalid message');
});

test('offline queue payload is independent of the cleared form snapshot', function () {
    var cart = dirtyCart();
    var queued = {
        contact_person_name: cart.address.contact_person_name,
        contact_person_number: cart.address.contact_person_number,
        address: cart.address.address,
        delivery_charge: cart.deliveryFee
    };
    delivery.applyEmptyDelivery(cart);
    assertBlankDelivery(cart, 'after queue');
    assert(queued.contact_person_name === 'Jane Doe', 'queued name should stay on the order payload');
    assert(queued.contact_person_number === '0700000000', 'queued phone should stay on the order payload');
    assert(queued.address === 'Westlands', 'queued address should stay on the order payload');
    assert(queued.delivery_charge === 200, 'queued fee should stay on the order payload');
    assert(!Object.prototype.hasOwnProperty.call(queued, 'rider_name'), 'queued payload must not include rider_name');
    assert(!Object.prototype.hasOwnProperty.call(queued, 'rider_phone'), 'queued payload must not include rider_phone');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}
console.log(passed + ' passed');
