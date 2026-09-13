(function (root) {
    'use strict';

    var PRINTED_MESSAGE = 'Order cannot be edited because a kitchen order or receipt has already been printed.';
    var CANCELLED_MESSAGE = 'Cancelled orders cannot be edited.';
    var MARKETPLACE_MESSAGE = 'Marketplace orders cannot be edited.';
    var RECEIPT_CANCELLED_MESSAGE = 'Cancelled orders cannot print a customer receipt.';

    function statusOf(order) {
        return String((order && (order.order_status || order.orderStatus)) || '');
    }

    function channelOf(order) {
        return String((order && (order.salesChannel || order.sales_channel)) || '');
    }

    function isCancelled(order) {
        var status = statusOf(order);
        return status === 'canceled' || status === 'cancelled' || !!(order && order.cancel_queued);
    }

    function isMarketplace(order) {
        var channel = channelOf(order);
        return channel === 'glovo' || channel === 'uber' || channel === 'bolt_food';
    }

    function kitchenPrinted(order) {
        return !!(order && (order.kitchenPrinted || order.kitchen_printed));
    }

    function receiptPrinted(order) {
        return !!(order && (order.receiptPrinted || order.receipt_printed));
    }

    function isPrinted(order) {
        return kitchenPrinted(order) || receiptPrinted(order);
    }

    function canPrintCustomerReceipt(order) {
        return !!(order && !isCancelled(order) && !receiptPrinted(order));
    }

    function canPrintKitchenTicket(order) {
        return !!(order && !isCancelled(order) && !kitchenPrinted(order));
    }

    function canEditPostedOrder(order) {
        return !!(order && !isCancelled(order) && !isMarketplace(order) && !isPrinted(order));
    }

    function editBlockedReason(order) {
        if (canEditPostedOrder(order)) return '';
        if (isCancelled(order)) return CANCELLED_MESSAGE;
        if (isMarketplace(order)) return MARKETPLACE_MESSAGE;
        if (isPrinted(order)) return PRINTED_MESSAGE;
        return PRINTED_MESSAGE;
    }

    root.MunchPosOrderRules = {
        PRINTED_MESSAGE: PRINTED_MESSAGE,
        CANCELLED_MESSAGE: CANCELLED_MESSAGE,
        MARKETPLACE_MESSAGE: MARKETPLACE_MESSAGE,
        RECEIPT_CANCELLED_MESSAGE: RECEIPT_CANCELLED_MESSAGE,
        isCancelled: isCancelled,
        isMarketplace: isMarketplace,
        isPrinted: isPrinted,
        kitchenPrinted: kitchenPrinted,
        receiptPrinted: receiptPrinted,
        canPrintCustomerReceipt: canPrintCustomerReceipt,
        canPrintKitchenTicket: canPrintKitchenTicket,
        canEditPostedOrder: canEditPostedOrder,
        editBlockedReason: editBlockedReason
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = root.MunchPosOrderRules;
    }
})(typeof window !== 'undefined' ? window : (typeof global !== 'undefined' ? global : this));
