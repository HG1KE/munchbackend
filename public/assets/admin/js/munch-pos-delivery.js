/* Shared Branch POS delivery-form helpers (browser + Node tests). */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.MunchPosDelivery = factory();
    }
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    var PHONE_ERROR = 'Enter a valid 10-digit phone number.';
    var PHONE_REQUIRED = 'Customer Phone';

    function emptyDeliveryState() {
        return {
            deliveryFee: 0,
            address: {
                contact_person_name: '',
                contact_person_number: '',
                address: ''
            }
        };
    }

    function normalizePosDeliveryPhone(value) {
        return String(value == null ? '' : value).replace(/\D+/g, '');
    }

    function canonicalPosDeliveryPhone(value) {
        var digits = normalizePosDeliveryPhone(value);
        return digits.length === 10 ? digits : '';
    }

    function posDeliveryPhoneError(value) {
        var raw = String(value == null ? '' : value).trim();
        var digits = normalizePosDeliveryPhone(value);
        if (raw === '' && digits === '') return PHONE_REQUIRED;
        if (digits.length !== 10) return PHONE_ERROR;
        return null;
    }

    function isValidPosDeliveryPhone(value) {
        return posDeliveryPhoneError(value) === null;
    }

    function applyEmptyDelivery(cart) {
        var next = cart || {};
        var empty = emptyDeliveryState();
        next.deliveryFee = empty.deliveryFee;
        next.address = empty.address;
        if (Object.prototype.hasOwnProperty.call(next, 'rider')) delete next.rider;
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
        PHONE_ERROR: PHONE_ERROR,
        PHONE_REQUIRED: PHONE_REQUIRED,
        emptyDeliveryState: emptyDeliveryState,
        applyEmptyDelivery: applyEmptyDelivery,
        persistableCart: persistableCart,
        hydrateCart: hydrateCart,
        normalizePosDeliveryPhone: normalizePosDeliveryPhone,
        canonicalPosDeliveryPhone: canonicalPosDeliveryPhone,
        posDeliveryPhoneError: posDeliveryPhoneError,
        isValidPosDeliveryPhone: isValidPosDeliveryPhone
    };
}));
