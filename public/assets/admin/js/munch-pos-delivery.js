/* Shared Branch POS delivery-form helpers (browser + Node tests). */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.MunchPosDelivery = factory();
    }
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    function emptyDeliveryState() {
        return {
            deliveryFee: 0,
            address: {
                contact_person_name: '',
                contact_person_number: '',
                address: ''
            },
            rider: {
                rider_name: '',
                rider_phone: ''
            }
        };
    }

    function applyEmptyDelivery(cart) {
        var next = cart || {};
        var empty = emptyDeliveryState();
        next.deliveryFee = empty.deliveryFee;
        next.address = empty.address;
        next.rider = empty.rider;
        return next;
    }

    function persistableCart(cart) {
        return applyEmptyDelivery(Object.assign({}, cart || {}));
    }

    function hydrateCart(base, stored) {
        var next = Object.assign({}, base || {}, stored || {});
        return applyEmptyDelivery(next);
    }

    return {
        emptyDeliveryState: emptyDeliveryState,
        applyEmptyDelivery: applyEmptyDelivery,
        persistableCart: persistableCart,
        hydrateCart: hydrateCart
    };
}));
