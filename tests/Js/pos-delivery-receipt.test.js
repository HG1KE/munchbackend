'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');

var root = path.join(__dirname, '../..');
var app = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-pos-app.js'), 'utf8');
var ticketSrc = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-receipt-ticket.js'), 'utf8');
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

function renderer() {
    var sandbox = { window: {}, console: console };
    sandbox.window = sandbox;
    vm.runInNewContext(ticketSrc, sandbox);
    return sandbox.window.MunchReceiptTicket;
}

function deliveryJob(overrides) {
    return Object.assign({
        number: '#M-9001',
        branch: 'Nyali',
        orderType: 'Delivery',
        salesChannel: 'delivery',
        isDelivery: true,
        items: [{ name: 'Burger', quantity: 1, options: [], unit_price: 690, line_total: 690 }],
        customer: 'John Doe',
        phone: '0712345678',
        address: 'Nyali, Links Road',
        subtotal: 690,
        delivery_fee: 200,
        discount: 0,
        grand_total: 890,
        payment_method: 'cash',
        payment_status: 'paid',
        riderName: '',
        riderPhone: ''
    }, overrides || {});
}

function receiptHtml(job) {
    var T = renderer();
    return T.renderDocument('customer', T.defaults('customer'), job);
}

function functionBody(source, needle) {
    var start = source.indexOf(needle);
    if (start < 0) throw new Error(needle + ' not found');
    return source.slice(start, start + 2200);
}

test('delivery receipt prints saved customer name phone address and fee', function () {
    var html = receiptHtml(deliveryJob());
    assert(html.indexOf('CUSTOMER') !== -1 && html.indexOf('John Doe') !== -1, 'missing customer name');
    assert(html.indexOf('PHONE') !== -1 && html.indexOf('0712345678') !== -1, 'missing customer phone');
    assert(html.indexOf('ADDRESS') !== -1 && html.indexOf('Nyali, Links Road') !== -1, 'missing customer address');
    assert(html.indexOf('DELIVERY FEE') !== -1, 'missing delivery fee label');
    assert(html.indexOf('200') !== -1, 'missing delivery fee amount');
    assert(html.indexOf('Rider Name') === -1, 'new receipts must not print rider name');
    assert(html.indexOf('Rider Phone') === -1, 'new receipts must not print rider phone');
});

test('reprinting a saved delivery order keeps customer details', function () {
    var saved = {
        id: 9001,
        number: '#M-9001',
        sales_channel: 'delivery',
        sales_channel_label: 'Delivery',
        customer: 'John Doe',
        phone: '0712345678',
        address: 'Nyali, Links Road',
        delivery_fee: 200,
        items: [{ name: 'Burger', quantity: 1, options: [], unit_price: 690, line_total: 690 }],
        subtotal: 690,
        grand_total: 890,
        payment_method: 'cash',
        payment_status: 'paid',
        rider_name: '',
        rider_phone: ''
    };
    var fromOrder = function (order) {
        return {
            isDelivery: order.sales_channel === 'delivery',
            salesChannel: order.sales_channel,
            customer: order.customer || '',
            phone: order.phone || '',
            address: order.address || '',
            delivery_fee: order.sales_channel === 'delivery' ? Number(order.delivery_fee || 0) : 0,
            riderName: order.rider_name || '',
            riderPhone: order.rider_phone || '',
            items: order.items,
            subtotal: order.subtotal,
            grand_total: order.grand_total,
            payment_method: order.payment_method,
            payment_status: order.payment_status,
            number: order.number
        };
    };
    var html = receiptHtml(fromOrder(saved));
    assert(html.indexOf('John Doe') !== -1, 'reprint lost name');
    assert(html.indexOf('0712345678') !== -1, 'reprint lost phone');
    assert(html.indexOf('Nyali, Links Road') !== -1, 'reprint lost address');
    assert(html.indexOf('200') !== -1, 'reprint lost fee');
});

test('empty delivery customer fields do not print labels', function () {
    var html = receiptHtml(deliveryJob({ customer: '', phone: '', address: '', delivery_fee: 200 }));
    assert(html.indexOf('<p>CUSTOMER</p>') === -1, 'empty CUSTOMER label');
    assert(html.indexOf('<p>PHONE</p>') === -1, 'empty PHONE label');
    assert(html.indexOf('<p>ADDRESS</p>') === -1, 'empty ADDRESS label');
    assert(html.indexOf('Walk-in') === -1, 'Walk-in must not stand in for delivery customer');
    assert(html.indexOf('DELIVERY FEE') !== -1, 'fee should still print');
});

test('walk-in placeholder is omitted on delivery receipts', function () {
    var html = receiptHtml(deliveryJob({ customer: 'Walk-in', phone: '0712345678', address: 'Nyali, Links Road' }));
    assert(html.indexOf('Walk-in') === -1, 'Walk-in leaked onto delivery receipt');
    assert(html.indexOf('0712345678') !== -1, 'phone should still print');
});

test('delivery customer information is omitted when the template element is disabled', function () {
    var T = renderer();
    var template = T.defaults('customer');
    template.sections.delivery_customer.customer_name = false;
    template.sections.delivery_customer.customer_phone = false;
    template.sections.delivery_customer.delivery_address = false;
    template.sections.delivery_customer.delivery_fee = false;
    var html = T.renderDocument('customer', template, deliveryJob());
    assert(html.indexOf('John Doe') === -1, 'name printed while delivery customer was disabled');
    assert(html.indexOf('0712345678') === -1, 'phone printed while delivery customer was disabled');
    assert(html.indexOf('Nyali, Links Road') === -1, 'address printed while delivery customer was disabled');
    assert(html.indexOf('DELIVERY FEE') === -1, 'grouped delivery fee printed while disabled');
});

test('dine in takeaway and marketplace receipts omit delivery customer information', function () {
    ['dine_in', 'takeaway', 'glovo', 'uber', 'bolt_food'].forEach(function (channel) {
        var html = receiptHtml(deliveryJob({
            salesChannel: channel,
            orderType: channel,
            isDelivery: channel !== 'dine_in' && channel !== 'takeaway'
        }));
        assert(html.indexOf('<p>CUSTOMER</p>') === -1, channel + ' showed delivery CUSTOMER label');
        assert(html.indexOf('<p>ADDRESS</p>') === -1, channel + ' showed delivery ADDRESS label');
        assert(html.indexOf('<p>DELIVERY FEE</p>') === -1, channel + ' showed grouped DELIVERY FEE');
    });
});

test('historical rider values still print when present on a saved order', function () {
    var html = receiptHtml(deliveryJob({ riderName: 'Alex Rider', riderPhone: '0799999999' }));
    assert(html.indexOf('Rider Name') !== -1 && html.indexOf('Alex Rider') !== -1, 'historical rider name missing');
    assert(html.indexOf('Rider Phone') !== -1 && html.indexOf('0799999999') !== -1, 'historical rider phone missing');
});

test('online snapshot prefers the saved order payload over cart state', function () {
    var snap = functionBody(app, 'function snapshotPrintJob');
    assert(snap.indexOf('if (body && body.order)') !== -1, 'snapshot must prefer body.order');
    assert(snap.indexOf('return printJobFromOrder(body.order)') !== -1, 'snapshot must print from saved order');
    assert(app.indexOf('openSuccessModal(snapshotPrintJob(body))') !== -1, 'online success must snapshot the place-order response');
});

test('offline snapshot keeps queued customer details after the cart is cleared', function () {
    var finish = functionBody(app, 'function finishQueuedOrder');
    assert(finish.indexOf('snapshotPrintJob({') !== -1, 'offline success must snapshot a print job');
    assert(finish.indexOf(', payload)') !== -1, 'offline snapshot must receive the queued payload');
    assert(finish.indexOf('snapshotPrintJob') < finish.indexOf('clearCart()'), 'must snapshot before clearing the cart');

    var snap = functionBody(app, 'function snapshotPrintJob');
    assert(snap.indexOf('payload && payload.address') !== -1, 'offline snapshot must read queued address');
    assert(snap.indexOf('payload.delivery_charge') !== -1, 'offline snapshot must read queued delivery fee');
});

test('new delivery payloads omit rider fields', function () {
    var payload = functionBody(app, 'function buildPayload');
    assert(payload.indexOf('rider_name') === -1, 'payload still sends rider_name');
    assert(payload.indexOf('rider_phone') === -1, 'payload still sends rider_phone');
    assert(payload.indexOf('contact_person_name') !== -1, 'payload lost customer name');
    assert(payload.indexOf('contact_person_number') !== -1, 'payload lost customer phone');
    assert(payload.indexOf('delivery_charge: deliveryCharge()') !== -1, 'payload lost delivery fee');
});

test('delivery modal no longer collects rider fields', function () {
    assert(page.indexOf('id="pos-del-name"') !== -1, 'customer name field missing');
    assert(page.indexOf('id="pos-del-phone"') !== -1, 'customer phone field missing');
    assert(page.indexOf('id="pos-del-address"') !== -1, 'customer address field missing');
    assert(page.indexOf('id="pos-del-fee"') !== -1, 'delivery fee field missing');
    assert(page.indexOf('pos-del-rider-name') === -1, 'rider name field still in modal');
    assert(page.indexOf('pos-del-rider-phone') === -1, 'rider phone field still in modal');
    assert(page.indexOf('Who will deliver this order?') === -1, 'who-delivers heading still in modal');
    assert(page.indexOf('Rider Name') === -1, 'Rider Name label still in modal');
    assert(page.indexOf('Rider Phone') === -1, 'Rider Phone label still in modal');
    assert(app.indexOf('pos-del-rider-name') === -1, 'app still reads rider name input');
    assert(app.indexOf('pos-del-rider-phone') === -1, 'app still reads rider phone input');
});

test('persistable cart still strips live delivery PII and leftover rider state', function () {
    var cart = {
        lines: [{ productId: 1, quantity: 1 }],
        deliveryFee: 200,
        address: {
            contact_person_name: 'Jane Doe',
            contact_person_number: '0700000000',
            address: 'Westlands'
        },
        rider: { rider_name: 'Alex', rider_phone: '0711111111' }
    };
    var stored = delivery.persistableCart(cart);
    assert(stored.address.contact_person_name === '', 'name leaked into IDB');
    assert(stored.deliveryFee === 0, 'fee leaked into IDB');
    assert(!stored.rider, 'rider leaked into IDB');
    assert(cart.address.contact_person_name === 'Jane Doe', 'live cart was mutated');
});

test('POS print uses the live catalog receipt pack and shared renderer', function () {
    assert(app.indexOf('function receiptPack()') !== -1, 'POS missing receiptPack');
    assert(app.indexOf('if (state.catalog && state.catalog.receipt) return state.catalog.receipt') !== -1, 'POS print must prefer live catalog templates');
    assert(app.indexOf('CFG.catalog = catalog') !== -1, 'catalog refresh must update CFG.catalog');
    assert(app.indexOf("MunchReceiptTicket.renderDocument('customer'") !== -1, 'POS customer receipt must use the shared renderer');
    assert(app.indexOf("MunchReceiptTicket.renderDocument('kitchen'") !== -1, 'POS kitchen ticket must use the shared renderer');
    assert(app.indexOf('paid_amount: total') !== -1, 'new POS receipts should pass paid_amount');
    assert(app.indexOf('paid_amount: order.paid_amount') !== -1, 'reprints should pass paid_amount');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}
console.log(passed + ' passed');
