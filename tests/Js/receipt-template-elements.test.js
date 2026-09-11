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

function blockClass(html, id) {
    var match = html.match(new RegExp('class="([^"]*ticket-block--' + id + '[^"]*)"'));
    return match ? match[1] : '';
}

function withBlockStyle(template, id, style) {
    template.block_styles = template.block_styles || {};
    template.block_styles[id] = Object.assign({
        font_size: 'normal',
        bold: false,
        align: 'left',
        divider_before: false,
        divider_after: false,
        margin_top: false,
        margin_bottom: false
    }, style || {});
    return template;
}

test('order number visibility alignment and weight reach the renderer', function () {
    var T = renderer();
    var off = T.defaults('customer');
    off.sections.order.order_number = false;
    var hidden = T.renderDocument('customer', off, job({ number: 'A10001' }));
    assert(hidden.indexOf('ORDER #') === -1, 'order number stayed visible when disabled');

    var on = withBlockStyle(T.defaults('customer'), 'order_number', {
        align: 'center',
        font_size: 'extra_large',
        bold: true
    });
    on.sections.order.order_number = true;
    var shown = T.renderDocument('customer', on, job({ number: 'A10001' }));
    var cls = blockClass(shown, 'order_number');
    assert(shown.indexOf('ORDER #A10001') !== -1, 'preview sample order number missing');
    assert(cls.indexOf('fs-extra_large') !== -1, 'order number font size ignored');
    assert(cls.indexOf('is-bold') !== -1, 'order number bold ignored');
    assert(cls.indexOf('is-center') !== -1, 'order number center ignored');
});

test('delivery customer alignment and weight reach the renderer', function () {
    var T = renderer();
    var tmpl = withBlockStyle(T.defaults('customer'), 'delivery_customer', {
        align: 'right',
        font_size: 'large',
        bold: true
    });
    var html = T.renderDocument('customer', tmpl, job());
    var cls = blockClass(html, 'delivery_customer');
    assert(html.indexOf('<p>CUSTOMER</p>') !== -1, 'delivery customer block missing');
    assert(cls.indexOf('is-right') !== -1, 'delivery customer right align ignored');
    assert(cls.indexOf('fs-large') !== -1, 'delivery customer font size ignored');
    assert(cls.indexOf('is-bold') !== -1, 'delivery customer bold ignored');
    assert(html.indexOf('.ticket-block.is-right .meta p') !== -1, 'right align CSS lost to hardcoded meta styles');
    assert(html.indexOf('.ticket-block.fs-large,.ticket-block.fs-large .meta') !== -1, 'font size CSS does not override .meta');
});

test('payment status alignment reaches the renderer and can be disabled', function () {
    var T = renderer();
    var centered = withBlockStyle(T.defaults('customer'), 'payment', { align: 'center' });
    centered.sections.payment.payment_status = true;
    var html = T.renderDocument('customer', centered, job({ payment_status: 'paid' }));
    assert(html.indexOf('Payment Status') !== -1, 'enabled payment status missing');
    assert(blockClass(html, 'payment').indexOf('is-center') !== -1, 'payment center ignored');
    assert(html.indexOf('.ticket-block.is-center .row{display:flex;justify-content:center') !== -1, 'payment row still uses space-between');

    var off = T.defaults('customer');
    off.sections.payment.payment_status = false;
    var hidden = T.renderDocument('customer', off, job({ payment_status: 'paid' }));
    assert(hidden.indexOf('Payment Status') === -1, 'disabled payment status still printed');
});

test('acceptance: styled config is visible in the shared renderer', function () {
    var T = renderer();
    var tmpl = T.defaults('customer');
    withBlockStyle(tmpl, 'order_number', { align: 'center', font_size: 'extra_large', bold: true });
    withBlockStyle(tmpl, 'delivery_customer', { align: 'right', font_size: 'large', bold: true });
    withBlockStyle(tmpl, 'payment', { align: 'center', font_size: 'normal', bold: false });
    tmpl.sections.order.order_number = true;
    tmpl.sections.payment.payment_status = true;
    var html = T.renderDocument('customer', tmpl, job({ number: 'A10001', payment_status: 'paid' }));
    assert(html.indexOf('ORDER #A10001') !== -1, 'acceptance order number missing');
    assert(blockClass(html, 'order_number').indexOf('fs-extra_large') !== -1, 'acceptance order number size missing');
    assert(blockClass(html, 'order_number').indexOf('is-center') !== -1, 'acceptance order number align missing');
    assert(blockClass(html, 'order_number').indexOf('is-bold') !== -1, 'acceptance order number bold missing');
    assert(blockClass(html, 'delivery_customer').indexOf('is-right') !== -1, 'acceptance delivery align missing');
    assert(blockClass(html, 'delivery_customer').indexOf('fs-large') !== -1, 'acceptance delivery size missing');
    assert(blockClass(html, 'delivery_customer').indexOf('is-bold') !== -1, 'acceptance delivery bold missing');
    assert(html.indexOf('Payment Status') !== -1, 'acceptance payment status missing');
    assert(blockClass(html, 'payment').indexOf('is-center') !== -1, 'acceptance payment align missing');
});

test('acceptance: flipping styles updates the shared renderer', function () {
    var T = renderer();
    var tmpl = T.defaults('customer');
    withBlockStyle(tmpl, 'order_number', { align: 'left', font_size: 'normal', bold: false });
    withBlockStyle(tmpl, 'delivery_customer', { align: 'left', font_size: 'normal', bold: false });
    tmpl.sections.order.order_number = true;
    tmpl.sections.payment.payment_status = false;
    var html = T.renderDocument('customer', tmpl, job({ number: 'A10001', payment_status: 'paid' }));
    assert(html.indexOf('ORDER #A10001') !== -1, 'flipped order number missing');
    assert(blockClass(html, 'order_number').indexOf('is-left') !== -1, 'flipped order number align missing');
    assert(blockClass(html, 'order_number').indexOf('is-normal') !== -1, 'flipped order number weight missing');
    assert(blockClass(html, 'order_number').indexOf('fs-normal') !== -1, 'flipped order number size missing');
    assert(blockClass(html, 'delivery_customer').indexOf('is-left') !== -1, 'flipped delivery align missing');
    assert(blockClass(html, 'delivery_customer').indexOf('is-normal') !== -1, 'flipped delivery weight missing');
    assert(html.indexOf('Payment Status') === -1, 'flipped payment status still printed');
});

test('preview and print share one renderer and schema keys', function () {
    assert(editorSrc.indexOf('MunchReceiptTicket.renderDocument') !== -1, 'editor preview does not use the shared renderer');
    assert(editorSrc.indexOf("els.preview.srcdoc = ''") !== -1, 'editor preview must force an iframe rewrite');
    assert(editorSrc.indexOf('st.align = styleAlign') !== -1, 'editor writes align');
    assert(editorSrc.indexOf('ensureStyle(fsName.slice(3)).font_size = target.value') !== -1, 'editor writes font_size');
    assert(editorSrc.indexOf('data-style-bool="bold"') !== -1, 'editor writes bold');
    assert(ticketSrc.indexOf('st.align ===') !== -1, 'renderer reads align');
    assert(ticketSrc.indexOf('st.font_size') !== -1, 'renderer reads font_size');
    assert(ticketSrc.indexOf('st.bold') !== -1, 'renderer reads bold');
    assert(ticketSrc.indexOf('textAlign') === -1, 'renderer has a mismatched textAlign schema');
    assert(editorSrc.indexOf('textAlign') === -1, 'editor has a mismatched textAlign schema');
    assert(ticketSrc.indexOf('.ticket-block.is-left{text-align:left}') !== -1, 'block align must beat hardcoded center');
    assert(ticketSrc.indexOf('.ticket-block.is-normal,.ticket-block.is-normal *{font-weight:500}') !== -1, 'normal weight must beat ticket default bold');
});

test('80mm and 58mm paper sizes change the shared CSS', function () {
    var T = renderer();
    var tmpl = T.defaults('customer');
    var wide = T.renderDocument('customer', tmpl, job(), { print: { paper: '80mm' } });
    var narrow = T.renderDocument('customer', tmpl, job(), { print: { paper: '58mm' } });
    assert(wide.indexOf('size:80mm') !== -1, '80mm preview CSS missing');
    assert(wide.indexOf('width:80mm') !== -1, '80mm body width missing');
    assert(narrow.indexOf('size:58mm') !== -1, '58mm preview CSS missing');
    assert(narrow.indexOf('width:58mm') !== -1, '58mm body width missing');
    withBlockStyle(tmpl, 'order_number', { align: 'center', font_size: 'large', bold: true });
    var styled80 = T.renderDocument('customer', tmpl, job({ number: 'A10001' }), { print: { paper: '80mm' } });
    var styled58 = T.renderDocument('customer', tmpl, job({ number: 'A10001' }), { print: { paper: '58mm' } });
    assert(blockClass(styled80, 'order_number').indexOf('is-center') !== -1, '80mm lost order number align');
    assert(blockClass(styled58, 'order_number').indexOf('is-center') !== -1, '58mm lost order number align');
});

test('reordering blocks is reflected by the shared renderer', function () {
    var T = renderer();
    var tmpl = T.defaults('customer');
    tmpl.order = ['delivery_customer', 'order_number', 'items', 'payment'];
    var html = T.renderDocument('customer', tmpl, job({ number: 'A10001' }));
    var deliveryAt = html.indexOf('ticket-block--delivery_customer');
    var orderAt = html.indexOf('ticket-block--order_number');
    assert(deliveryAt !== -1 && orderAt !== -1, 'reordered blocks missing');
    assert(deliveryAt < orderAt, 'block order ignored');
});

test('long customer names and addresses wrap instead of overflowing', function () {
    var html = render('customer', null, {
        customer: 'Jonathan Bartholomew Winterbottom-Cheltenham',
        address: 'Apartment 12B, Nyali Beach Apartments, Links Road, Nyali, Mombasa, Kenya'
    });
    assert(html.indexOf('overflow-wrap:anywhere') !== -1, 'missing wrap CSS');
    assert(html.indexOf('Jonathan Bartholomew Winterbottom-Cheltenham') !== -1, 'long name omitted');
    assert(html.indexOf('Links Road, Nyali, Mombasa, Kenya') !== -1, 'long address omitted');
});

test('paid amount toggle is not decorative', function () {
    var T = renderer();
    var on = T.defaults('customer');
    on.sections.summary.paid_amount = true;
    var shown = T.renderDocument('customer', on, job({ paid_amount: 600, grand_total: 600 }));
    assert(shown.indexOf('Paid Amount') !== -1, 'paid amount enabled but ignored');
    var off = T.defaults('customer');
    off.sections.summary.paid_amount = false;
    var hidden = T.renderDocument('customer', off, job({ paid_amount: 600, grand_total: 600 }));
    assert(hidden.indexOf('Paid Amount') === -1, 'paid amount stayed visible');
});

test('kitchen tickets ignore customer-only delivery and payment styling', function () {
    var T = renderer();
    withBlockStyle(T.defaults('customer'), 'delivery_customer', { align: 'right', bold: true, font_size: 'extra_large' });
    var kitchen = T.renderDocument('kitchen', T.defaults('kitchen'), job());
    assert(kitchen.indexOf('ticket-block--delivery_customer') === -1, 'kitchen rendered delivery customer');
    assert(kitchen.indexOf('Payment Status') === -1, 'kitchen rendered payment status');
    assert(kitchen.indexOf('<p>CUSTOMER</p>') === -1, 'kitchen used grouped delivery labels');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}
console.log(passed + ' passed');
