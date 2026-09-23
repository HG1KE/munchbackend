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
    var NAME_MAX = 100;
    var ADDRESS_MAX = 250;

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

    function posDeliveryFormError(address, labels) {
        labels = labels || {};
        var name = String((address && address.contact_person_name) || '').trim();
        if (name === '') {
            return labels.customerName || 'Customer Name';
        }
        if (name.length > NAME_MAX) {
            return labels.customerNameTooLong || ('Customer name is too long (max ' + NAME_MAX + ' characters).');
        }
        var phoneErr = posDeliveryPhoneError((address && address.contact_person_number) || '');
        if (phoneErr === PHONE_REQUIRED) {
            return labels.customerPhone || PHONE_REQUIRED;
        }
        if (phoneErr) {
            return labels.invalidPhone || phoneErr;
        }
        var addr = String((address && address.address) || '').trim();
        if (addr === '') {
            return labels.deliveryAddress || labels.address || 'Delivery Address';
        }
        if (addr.length > ADDRESS_MAX) {
            return labels.deliveryAddressTooLong || ('Delivery address is too long (max ' + ADDRESS_MAX + ' characters).');
        }
        return null;
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
        var next = applyEmptyDelivery(Object.assign({}, cart || {}));
        next.discount = 0;
        next.discountType = 'amount';
        return next;
    }

    function hydrateCart(base, stored) {
        var next = Object.assign({}, base || {}, stored || {});
        next = applyEmptyDelivery(next);
        next.discount = 0;
        next.discountType = 'amount';
        return next;
    }

    return {
        PHONE_ERROR: PHONE_ERROR,
        PHONE_REQUIRED: PHONE_REQUIRED,
        NAME_MAX: NAME_MAX,
        ADDRESS_MAX: ADDRESS_MAX,
        posDeliveryFormError: posDeliveryFormError,
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
