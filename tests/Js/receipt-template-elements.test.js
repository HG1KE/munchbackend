'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');

var root = path.join(__dirname, '../..');
var ticketSrc = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-receipt-ticket.js'), 'utf8');
var editorSrc = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-receipt-templates.js'), 'utf8');
var editorPartial = fs.readFileSync(path.join(root, 'resources/views/admin-views/business-settings/partials/_receipt-template-editor.blade.php'), 'utf8');

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

function renderer() {
    var sandbox = { window: {}, console: console };
    sandbox.window = sandbox;
    vm.runInNewContext(ticketSrc, sandbox);
    return sandbox.window.MunchReceiptTicket;
}

function job(overrides) {
    return Object.assign({
        number: '#M-1042',
        branch: 'Nyali',
        orderType: 'Delivery',
        salesChannel: 'delivery',
        isDelivery: true,
        items: [{ name: 'Burger', quantity: 1, options: [], unit_price: 500, line_total: 500 }],
        customer: 'John Doe',
        phone: '0712345678',
        address: 'Nyali, Mombasa',
        subtotal: 500,
        delivery_fee: 100,
        grand_total: 600,
        payment_method: 'cash',
        payment_status: 'paid'
    }, overrides || {});
}

function render(kind, template, overrides) {
    var T = renderer();
    return T.renderDocument(kind, template || T.defaults(kind), job(overrides));
}

test('payment status renders when the template toggle is enabled', function () {
    var methods = {
        cash: 'Cash',
        card: 'Card',
        mpesa: 'M-PESA',
        glovo: 'glovo',
        uber: 'uber',
        bolt_food: 'bolt food'
    };
    Object.keys(methods).forEach(function (method) {
        var html = render('customer', null, {
            payment_method: method,
            payment_status: 'paid',
            salesChannel: method === 'cash' || method === 'card' || method === 'mpesa' ? 'delivery' : method,
            isDelivery: method !== 'dine_in'
        });
        assert(html.indexOf('Payment Status') !== -1, method + ' missing Payment Status');
        assert(html.indexOf('PAID') !== -1, method + ' missing PAID');
        assert(html.indexOf(methods[method]) !== -1, method + ' missing payment method label');
    });
});

test('payment status is omitted when the template toggle is disabled', function () {
    var T = renderer();
    var template = T.defaults('customer');
    template.sections.payment.payment_status = false;
    ['cash', 'card', 'mpesa', 'glovo', 'uber', 'bolt_food'].forEach(function (method) {
        var html = T.renderDocument('customer', template, job({
            payment_method: method,
            payment_status: 'paid',
            salesChannel: method === 'cash' || method === 'card' || method === 'mpesa' ? 'dine_in' : method
        }));
        assert(html.indexOf('Payment Status') === -1, method + ' still showed Payment Status');
        assert(html.indexOf('Payment Method') !== -1, method + ' lost Payment Method');
    });
});

test('unpaid and pending statuses stay hidden even when payment status is enabled', function () {
    ['unpaid', 'pending', 'due', 'remaining'].forEach(function (status) {
        var html = render('customer', null, { payment_method: 'cash_on_delivery', payment_status: status });
        assert(html.indexOf('Payment Status') === -1, status + ' leaked onto the receipt');
        assert(html.indexOf('UNPAID') === -1, status + ' printed UNPAID');
    });
});

test('delivery customer information renders from saved order fields', function () {
    var html = render('customer');
    assert(html.indexOf('<p>CUSTOMER</p>') !== -1, 'missing CUSTOMER label');
    assert(html.indexOf('John Doe') !== -1, 'missing customer name');
    assert(html.indexOf('<p>PHONE</p>') !== -1, 'missing PHONE label');
    assert(html.indexOf('0712345678') !== -1, 'missing phone');
    assert(html.indexOf('<p>ADDRESS</p>') !== -1, 'missing ADDRESS label');
    assert(html.indexOf('Nyali, Mombasa') !== -1, 'missing address');
    assert(html.indexOf('<p>DELIVERY FEE</p>') !== -1, 'missing DELIVERY FEE label');
    assert(html.indexOf('100') !== -1, 'missing delivery fee amount');
});

test('delivery customer information disappears when the block is disabled', function () {
    var T = renderer();
    var template = T.defaults('customer');
    template.sections.delivery_customer.customer_name = false;
    template.sections.delivery_customer.customer_phone = false;
    template.sections.delivery_customer.delivery_address = false;
    template.sections.delivery_customer.delivery_fee = false;
    var html = T.renderDocument('customer', template, job());
    assert(html.indexOf('John Doe') === -1, 'name still printed');
    assert(html.indexOf('Nyali, Mombasa') === -1, 'address still printed');
    assert(html.indexOf('<p>DELIVERY FEE</p>') === -1, 'grouped fee still printed');
    assert(html.indexOf('Delivery Fee') !== -1, 'totals delivery fee should remain available');
});

test('individual delivery customer fields can be toggled', function () {
    var T = renderer();
    var template = T.defaults('customer');
    template.sections.delivery_customer.customer_phone = false;
    template.sections.delivery_customer.delivery_fee = false;
    var html = T.renderDocument('customer', template, job());
    assert(html.indexOf('John Doe') !== -1, 'name should remain');
    assert(html.indexOf('Nyali, Mombasa') !== -1, 'address should remain');
    assert(html.indexOf('0712345678') === -1, 'phone should be hidden');
    assert(html.indexOf('<p>PHONE</p>') === -1, 'phone label should be hidden');
    assert(html.indexOf('<p>DELIVERY FEE</p>') === -1, 'grouped fee should be hidden');
});

test('delivery customer information does not appear on other order types', function () {
    ['dine_in', 'takeaway', 'glovo', 'uber', 'bolt_food', 'pos'].forEach(function (channel) {
        var html = render('customer', null, {
            salesChannel: channel,
            orderType: channel,
            isDelivery: channel === 'glovo' || channel === 'uber' || channel === 'bolt_food'
        });
        assert(html.indexOf('<p>CUSTOMER</p>') === -1, channel + ' showed grouped CUSTOMER');
        assert(html.indexOf('<p>ADDRESS</p>') === -1, channel + ' showed grouped ADDRESS');
        assert(html.indexOf('<p>DELIVERY FEE</p>') === -1, channel + ' showed grouped DELIVERY FEE');
    });
});

test('kitchen tickets stay independent of delivery customer and payment status', function () {
    var html = render('kitchen');
    assert(html.indexOf('<p>CUSTOMER</p>') === -1, 'kitchen used grouped CUSTOMER label');
    assert(html.indexOf('DELIVERY FEE') === -1, 'kitchen printed delivery fee');
    assert(html.indexOf('Payment Status') === -1, 'kitchen printed payment status');
    assert(html.indexOf('PAID') === -1, 'kitchen printed PAID');
    assert(html.indexOf('John Doe') !== -1, 'kitchen should still be able to show the customer name');
});

test('legacy templates without delivery_customer still merge and render it', function () {
    var T = renderer();
    var legacy = T.normalizeTemplate('customer', {
        sections: {
            header: { branch_name: true },
            order: { customer_name: true, customer_phone: true, delivery_address: true },
            summary: { delivery_fee: true },
            payment: { payment_method: true, payment_status: false }
        },
        order: ['logo', 'customer', 'items', 'totals', 'payment']
    });
    assert(legacy.sections.delivery_customer.customer_name === true, 'legacy name flag missing');
    assert(legacy.sections.delivery_customer.delivery_address === true, 'legacy address flag missing');
    assert(legacy.sections.payment.payment_status === false, 'legacy payment status disable lost');
    assert(legacy.order.indexOf('delivery_customer') === legacy.order.indexOf('customer') + 1, 'legacy order did not insert delivery_customer');
    var html = T.renderDocument('customer', legacy, job());
    assert(html.indexOf('John Doe') !== -1, 'legacy template did not print delivery customer');
    assert(html.indexOf('Payment Status') === -1, 'legacy disabled payment status still printed');
});

test('editor wires grouped delivery customer fields and persists order on save', function () {
    assert(editorSrc.indexOf('delivery_customer') !== -1, 'editor missing delivery_customer block');
    assert(editorSrc.indexOf("withGroup(groups.delivery_customer || [], 'delivery_customer')") !== -1, 'editor missing grouped delivery customer fields');
    assert(editorSrc.indexOf('field.group || fieldGroup(field.key)') !== -1, 'editor must use explicit field groups');
    assert(editorSrc.indexOf('persistOrderFromDom();') !== -1, 'save must persist block order');
    assert(editorSrc.indexOf("job.isDelivery = previewAs.value === 'delivery'") !== -1, 'preview isDelivery must not treat marketplace as POS delivery');
    assert(editorPartial.indexOf('value="delivery" selected') !== -1, 'preview default should be Delivery');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}
console.log(passed + ' passed');
