/* Shared Branch POS dine-in table helpers (browser + Node tests). */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.MunchPosDineIn = factory();
    }
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    function selectedTableId(cart) {
        return String((cart && cart.tableId) || '').trim();
    }

    function selectedPeople(cart) {
        return String((cart && cart.people) || '').trim();
    }

    function isDineIn(cart) {
        return !!(cart && cart.orderType === 'dine_in');
    }

    function validate(cart, labels) {
        labels = labels || {};
        if (!isDineIn(cart)) return null;
        if (!selectedTableId(cart)) return labels.table || 'please select a table number';
        if (!selectedPeople(cart)) return labels.people || 'please enter people number';
        var people = Number(selectedPeople(cart));
        if (!isFinite(people) || people < 1) return labels.people || 'please enter people number';
        return null;
    }

    function payloadFields(cart) {
        if (!isDineIn(cart)) {
            return { table_id: null, people_number: null };
        }
        var tableId = selectedTableId(cart);
        var people = selectedPeople(cart);
        return {
            table_id: tableId ? tableId : null,
            people_number: people ? people : null
        };
    }

    function applyPatch(cart, patch) {
        var next = Object.assign({}, cart || {}, patch || {});
        if (!patch || !Object.prototype.hasOwnProperty.call(patch, 'tableId')) {
            next.tableId = cart && cart.tableId != null ? cart.tableId : '';
        }
        if (!patch || !Object.prototype.hasOwnProperty.call(patch, 'people')) {
            next.people = cart && cart.people != null ? cart.people : '';
        }
        return next;
    }

    return {
        selectedTableId: selectedTableId,
        selectedPeople: selectedPeople,
        isDineIn: isDineIn,
        validate: validate,
        payloadFields: payloadFields,
        applyPatch: applyPatch
    };
}));
