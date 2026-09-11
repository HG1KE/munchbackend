'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');

var root = path.join(__dirname, '../..');
var ticketSrc = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-receipt-ticket.js'), 'utf8');
var editorSrc = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-receipt-templates.js'), 'utf8');

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

function sampleJob(overrides) {
    return Object.assign({
        number: 'A10001',
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

function classList() {
    var names = {};
    return {
        add: function (name) { names[name] = true; },
        remove: function (name) { delete names[name]; },
        toggle: function (name, on) {
            if (arguments.length > 1) {
                if (on) names[name] = true;
                else delete names[name];
                return;
            }
            if (names[name]) delete names[name];
            else names[name] = true;
        }
    };
}

function makeNode(id, extras) {
    var node = {
        id: id || '',
        innerHTML: '',
        hidden: false,
        checked: false,
        value: extras && extras.value != null ? extras.value : '',
        textContent: '',
        className: '',
        classList: classList(),
        style: {},
        files: null,
        attributes: Object.assign({}, (extras && extras.attributes) || {}),
        listeners: {},
        children: [],
        getAttribute: function (name) {
            return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
        },
        setAttribute: function (name, val) {
            this.attributes[name] = String(val);
        },
        addEventListener: function (type, fn) {
            this.listeners[type] = this.listeners[type] || [];
            this.listeners[type].push(fn);
        },
        closest: function () { return null; },
        querySelector: function (sel) {
            var all = this.querySelectorAll(sel);
            return all.length ? all[0] : null;
        },
        querySelectorAll: function (sel) {
            return queryHtml(this.innerHTML, sel);
        }
    };
    return Object.assign(node, extras || {});
}

function queryHtml(html, sel) {
    html = String(html || '');
    if (sel === '.munch-receipt-blocks') {
        return html.indexOf('munch-receipt-blocks') === -1 ? [] : [makeNode('', { innerHTML: html })];
    }
    if (sel === '[data-block-id]' || sel.indexOf('[data-block-id]') === 0) {
        var found = [];
        var re = /data-block-id="([^"]+)"/g;
        var match;
        while ((match = re.exec(html))) {
            found.push(makeNode('', { attributes: { 'data-block-id': match[1] } }));
        }
        return found;
    }
    return [];
}

function bootEditor(payload, options) {
    options = options || {};
    var T = renderer();
    var srcdocWrites = [];
    var preview = makeNode('receipt-preview-frame');
    Object.defineProperty(preview, 'srcdoc', {
        configurable: true,
        get: function () { return this._srcdoc; },
        set: function (value) {
            srcdocWrites.push(value);
            this._srcdoc = value;
        }
    });
    preview._srcdoc = '';

    var editor = makeNode('receipt-editor');
    var paper = makeNode('receipt-paper');
    var previewTitle = makeNode('receipt-preview-title');
    var rootEl = makeNode('munch-receipt-root');
    var channel = makeNode('receipt-preview-channel', { value: options.channel || 'delivery' });
    var tabs = [
        makeNode('', { attributes: { 'data-receipt-tab': 'customer' } }),
        makeNode('', { attributes: { 'data-receipt-tab': 'kitchen' } }),
        makeNode('', { attributes: { 'data-receipt-tab': 'printer' } })
    ];
    var byId = {
        'munch-receipt-root': rootEl,
        'receipt-editor': editor,
        'receipt-preview-frame': preview,
        'receipt-paper': paper,
        'receipt-preview-title': previewTitle,
        'receipt-preview-channel': channel,
        'receipt-scope': options.withScope ? makeNode('receipt-scope', { value: '' }) : null,
        'receipt-mode-company': null,
        'receipt-mode-custom': null,
        'receipt-mode-wrap': makeNode('receipt-mode-wrap'),
        'receipt-save': makeNode('receipt-save'),
        'receipt-test-print': makeNode('receipt-test-print'),
        'receipt-test-kitchen': makeNode('receipt-test-kitchen'),
        'receipt-reset-company': makeNode('receipt-reset-company'),
        'receipt-reset-branch': makeNode('receipt-reset-branch'),
        'receipt-reset-section': makeNode('receipt-reset-section'),
        'receipt-print-frame': makeNode('receipt-print-frame'),
        'receipt-lock-hint': makeNode('receipt-lock-hint')
    };

    var sandbox = {
        console: console,
        window: {
            MUNCH_RECEIPT_EDITOR: {
                payload: payload,
                currency: 'KSh ',
                urls: options.urls || {}
            },
            toastr: null,
            alert: function () {},
            history: { replaceState: function () {} }
        },
        document: {
            getElementById: function (id) { return Object.prototype.hasOwnProperty.call(byId, id) ? byId[id] : null; },
            querySelector: function (sel) {
                if (sel === 'meta[name="csrf-token"]') return null;
                if (sel === '.munch-receipt-card:hover, .munch-receipt-card:focus-within') return null;
                var all = this.querySelectorAll(sel);
                return all.length ? all[0] : null;
            },
            querySelectorAll: function (sel) {
                if (sel === '[data-receipt-tab]') return tabs;
                return [];
            }
        },
        fetch: function () {
            return Promise.reject(new Error('network disabled in test'));
        },
        setTimeout: function (fn) { return 0; },
        clearTimeout: function () {},
        Node: function () {}
    };
    sandbox.window.window = sandbox.window;
    sandbox.window.MunchReceiptTicket = T;
    sandbox.window.console = console;
    sandbox.window.document = sandbox.document;
    sandbox.document.defaultView = sandbox.window;

    vm.runInNewContext(editorSrc, sandbox);
    return {
        T: T,
        preview: preview,
        editor: editor,
        srcdocWrites: srcdocWrites,
        html: preview.srcdoc || '',
        sandbox: sandbox,
        byId: byId
    };
}

function fireEditorChange(session, attrs) {
    var listeners = session.editor.listeners.change || [];
    var target = {
        name: attrs.name || '',
        checked: !!attrs.checked,
        value: attrs.value != null ? attrs.value : '',
        getAttribute: function (name) {
            return Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null;
        }
    };
    listeners.forEach(function (fn) { fn({ target: target }); });
}

test('editor source never blanks srcdoc before writing preview html', function () {
    assert(editorSrc.indexOf("els.preview.srcdoc = ''") === -1, 'blank srcdoc assignment is back');
    assert(editorSrc.indexOf('hydrateState') !== -1, 'editor must hydrate before render');
    assert(editorSrc.indexOf('previewSeq') !== -1, 'editor must cache-bust srcdoc without clearing it');
    assert(editorSrc.indexOf('MunchReceiptTicket.renderDocument') !== -1, 'preview must keep the shared renderer');
    assert(editorSrc.indexOf('if (els.editor)') !== -1, 'editor listeners must tolerate a missing editor node');
});

test('empty payload still initializes a default customer preview', function () {
    var session = bootEditor({});
    assert(session.srcdocWrites.length >= 1, 'preview iframe was never written');
    assert(session.srcdocWrites.every(function (html) { return html !== ''; }), 'preview was blanked');
    assert(session.html.indexOf('class="ticket') !== -1, 'default preview missing ticket markup');
    assert(session.html.indexOf('ORDER #') !== -1, 'default preview missing order number');
    assert(session.editor.innerHTML.indexOf('data-block-id="order_number"') !== -1, 'editor cards missing');
    assert(session.editor.innerHTML.indexOf('data-block-id="delivery_customer"') !== -1, 'delivery customer card missing');
    assert(session.editor.innerHTML.indexOf('data-block-id="payment"') !== -1, 'payment card missing');
});

test('null payload and missing optional sections still load', function () {
    var session = bootEditor(null);
    assert(session.html.indexOf('class="ticket') !== -1, 'null payload did not render');
    var partial = bootEditor({
        customer: { sections: { header: { branch_name: true } } },
        kitchen: {},
        print: null,
        sample_job: sampleJob()
    });
    assert(partial.html.indexOf('ORDER #A10001') !== -1, 'partial template lost order number');
    assert(partial.html.indexOf('<p>CUSTOMER</p>') !== -1, 'partial template lost delivery customer defaults');
    assert(partial.html.indexOf('Payment Status') !== -1, 'partial template lost payment status default');
});

test('legacy template without delivery_customer or payment_status still previews', function () {
    var T = renderer();
    var legacy = {
        sections: {
            header: { branch_name: true },
            order: { order_number: true, customer_name: true, customer_phone: true, delivery_address: true },
            items: { product_name: true, quantity: true, line_total: true },
            summary: { subtotal: true, total: true, delivery_fee: true },
            payment: { payment_method: true }
        },
        order: ['order_number', 'customer', 'items', 'totals', 'payment', 'footer']
    };
    var session = bootEditor({
        customer: legacy,
        kitchen: T.defaults('kitchen'),
        print: { paper: '80mm' },
        sample_job: sampleJob()
    });
    assert(session.html.indexOf('ORDER #A10001') !== -1, 'legacy preview missing order number');
    assert(session.html.indexOf('<p>CUSTOMER</p>') !== -1, 'legacy preview missing CUSTOMER');
    assert(session.html.indexOf('John Doe') !== -1, 'legacy preview missing name');
    assert(session.html.indexOf('0712345678') !== -1, 'legacy preview missing phone');
    assert(session.html.indexOf('Nyali, Mombasa') !== -1, 'legacy preview missing address');
    assert(session.html.indexOf('<p>DELIVERY FEE</p>') !== -1, 'legacy preview missing delivery fee');
    assert(session.html.indexOf('Payment Status') !== -1, 'legacy preview should default payment status on');
});

test('payment status enabled and disabled update the live preview', function () {
    var T = renderer();
    var tmpl = T.defaults('customer');
    tmpl.sections.payment.payment_status = true;
    var on = bootEditor({
        customer: tmpl,
        kitchen: T.defaults('kitchen'),
        sample_job: sampleJob({ payment_status: 'paid' })
    });
    assert(on.html.indexOf('Payment Status') !== -1, 'enabled payment status missing from preview');
    assert(on.html.indexOf('PAID') !== -1, 'enabled payment status missing PAID');

    fireEditorChange(on, { 'data-section-group': 'payment', 'data-section-key': 'payment_status', checked: false });
    assert(on.preview.srcdoc.indexOf('Payment Status') === -1, 'disabling payment status did not update preview');

    fireEditorChange(on, { 'data-section-group': 'payment', 'data-section-key': 'payment_status', checked: true });
    assert(on.preview.srcdoc.indexOf('Payment Status') !== -1, 're-enabling payment status did not update preview');
});

test('delivery customer information can be toggled in the live preview', function () {
    var T = renderer();
    var tmpl = T.defaults('customer');
    var session = bootEditor({
        customer: tmpl,
        kitchen: T.defaults('kitchen'),
        sample_job: sampleJob()
    });
    assert(session.html.indexOf('<p>CUSTOMER</p>') !== -1, 'delivery customer missing');
    assert(session.html.indexOf('John Doe') !== -1, 'sample customer name missing');
    assert(session.html.indexOf('<p>PHONE</p>') !== -1, 'phone label missing');
    assert(session.html.indexOf('0712345678') !== -1, 'phone missing');
    assert(session.html.indexOf('<p>ADDRESS</p>') !== -1, 'address label missing');
    assert(session.html.indexOf('Nyali, Mombasa') !== -1, 'address missing');
    assert(session.html.indexOf('<p>DELIVERY FEE</p>') !== -1, 'delivery fee label missing');
    assert(session.html.indexOf('KSh ') !== -1, 'currency missing from preview');

    ['customer_name', 'customer_phone', 'delivery_address', 'delivery_fee'].forEach(function (key) {
        fireEditorChange(session, { 'data-section-group': 'delivery_customer', 'data-section-key': key, checked: false });
    });
    assert(session.preview.srcdoc.indexOf('<p>CUSTOMER</p>') === -1, 'disabled delivery customer still shown');
    assert(session.preview.srcdoc.indexOf('<p>DELIVERY FEE</p>') === -1, 'disabled delivery fee still shown');
});

test('alignment and typography changes update the live preview', function () {
    var T = renderer();
    var session = bootEditor({
        customer: T.defaults('customer'),
        kitchen: T.defaults('kitchen'),
        sample_job: sampleJob()
    });
    fireEditorChange(session, { 'data-style-align': 'right', 'data-block': 'delivery_customer', checked: true });
    fireEditorChange(session, { name: 'fs-order_number', value: 'extra_large' });
    fireEditorChange(session, { 'data-style-bool': 'bold', 'data-block': 'order_number', checked: true });
    var html = session.preview.srcdoc;
    assert(html.indexOf('ticket-block--delivery_customer') !== -1, 'delivery block missing after style change');
    assert(html.indexOf('is-right') !== -1, 'right align did not reach preview');
    assert(html.indexOf('ticket-block--order_number') !== -1 && html.indexOf('fs-extra_large') !== -1, 'font size did not reach preview');
    assert(html.indexOf('is-bold') !== -1, 'bold did not reach preview');
});

test('save payload round-trip keeps styled config on reload', function () {
    var T = renderer();
    var tmpl = T.defaults('customer');
    tmpl.sections.order.order_number = true;
    tmpl.sections.payment.payment_status = true;
    tmpl.block_styles.order_number = Object.assign({}, tmpl.block_styles.order_number, {
        align: 'center', font_size: 'extra_large', bold: true
    });
    tmpl.block_styles.delivery_customer = Object.assign({}, tmpl.block_styles.delivery_customer, {
        align: 'right', font_size: 'large', bold: true
    });
    var first = bootEditor({
        customer: tmpl,
        kitchen: T.defaults('kitchen'),
        print: { paper: '80mm', receipt_copies: 1 },
        sample_job: sampleJob()
    });
    var savedCustomer = JSON.parse(JSON.stringify(tmpl));
    var reloaded = bootEditor({
        customer: savedCustomer,
        kitchen: T.defaults('kitchen'),
        print: { paper: '80mm', receipt_copies: 1 },
        sample_job: sampleJob()
    });
    ['ORDER #A10001', 'Payment Status', '<p>CUSTOMER</p>', 'John Doe', 'is-center', 'fs-extra_large', 'is-right'].forEach(function (needle) {
        assert(first.html.indexOf(needle) !== -1, 'first load missing ' + needle);
        assert(reloaded.html.indexOf(needle) !== -1, 'reload missing ' + needle);
    });
});

test('failed optional QR request cannot crash editor initialization', function () {
    var T = renderer();
    var tmpl = T.defaults('customer');
    tmpl.sections.footer.qr_code = true;
    tmpl.qr = { type: 'website', url: 'https://munch.co.ke', size: 'medium' };
    var session = bootEditor({
        customer: tmpl,
        kitchen: T.defaults('kitchen'),
        sample_job: sampleJob(),
        context: { website: 'https://munch.co.ke' }
    }, { urls: { qr: '/receipt-templates/qr' } });
    assert(session.html.indexOf('class="ticket') !== -1, 'QR fetch failure blanked the preview');
    assert(session.html.indexOf('ORDER #A10001') !== -1, 'QR fetch failure dropped the rest of the receipt');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}
console.log(passed + ' passed');
