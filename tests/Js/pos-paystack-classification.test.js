'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');

var root = path.join(__dirname, '../..');
var appSrc = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-pos-app.js'), 'utf8');
var ticketSrc = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-receipt-ticket.js'), 'utf8');

var failed = 0;
var passed = 0;

function assert(cond, message) {
    if (!cond) throw new Error(message || 'assertion failed');
}

function test(name, fn) {
    return Promise.resolve()
        .then(fn)
        .then(function () {
            passed += 1;
            console.log('ok - ' + name);
        })
        .catch(function (err) {
            failed += 1;
            console.error('not ok - ' + name + ': ' + err.message);
        });
}

function extractFunction(src, name) {
    var start = src.indexOf('function ' + name);
    if (start === -1) throw new Error(name + ' not found');
    var depth = 0;
    var i = src.indexOf('{', start);
    for (; i < src.length; i += 1) {
        if (src[i] === '{') depth += 1;
        else if (src[i] === '}') {
            depth -= 1;
            if (depth === 0) {
                return src.slice(start, i + 1);
            }
        }
    }
    throw new Error(name + ' unclosed');
}

function run() {
    return test('paystack is only offered on delivery', function () {
        var methodsFn = extractFunction(appSrc, 'paymentMethods');
        assert(methodsFn.indexOf("state.cart.orderType === 'delivery'") !== -1, 'delivery branch missing');
        assert(methodsFn.indexOf("delivery.push('paystack')") !== -1, 'paystack not added for delivery');
        assert(methodsFn.indexOf("take_away") !== -1, 'take away branch missing');
        var takeAwayBlock = methodsFn.slice(methodsFn.indexOf("take_away"));
        assert(takeAwayBlock.indexOf('paystack') === -1, 'paystack leaked into take away/dine in');
        assert(methodsFn.indexOf('PaystackPop') === -1, 'gateway popup leaked into paymentMethods');
    })
        .then(function () {
            return test('switching away from delivery drops an invalid paystack selection', function () {
                var renderPay = extractFunction(appSrc, 'renderPay');
                assert(renderPay.indexOf('methods.indexOf(state.cart.payment) === -1') !== -1, 'invalid payment is not reset');
                assert(renderPay.indexOf('state.cart.payment = methods[0]') !== -1, 'fallback payment missing');
            });
        })
        .then(function () {
            return test('place order sends paystack as a classification only', function () {
                var build = extractFunction(appSrc, 'buildPayload');
                var submit = extractFunction(appSrc, 'submitPlacedOrder');
                assert(build.indexOf('type: state.cart.payment') !== -1, 'payload must send selected payment');
                assert(appSrc.indexOf('PaystackPop') === -1, 'Paystack popup must not exist');
                assert(appSrc.indexOf('initializeTransaction') === -1, 'Paystack initialize must not exist');
                assert(appSrc.indexOf('paystack.com') === -1, 'Paystack API host must not exist');
                assert(appSrc.indexOf('inline_checkout') === -1, 'website Paystack checkout must not be used');
                assert(submit.indexOf('postOrder(payload)') !== -1, 'normal POS submit missing');
                assert(submit.indexOf('finishQueuedOrder(payload)') !== -1, 'offline queue must stay available');
            });
        })
        .then(function () {
            return test('receipt prints Paystack and not cash/card/mpesa', function () {
                var sandbox = { window: {}, console: console };
                sandbox.window = sandbox;
                vm.runInNewContext(ticketSrc, sandbox);
                var html = sandbox.window.MunchReceiptTicket.renderDocument('customer', sandbox.window.MunchReceiptTicket.defaults('customer'), {
                    number: '#M-9001',
                    orderType: 'Delivery',
                    salesChannel: 'delivery',
                    isDelivery: true,
                    items: [{ name: 'Burger', quantity: 1, options: [], unit_price: 500, line_total: 500 }],
                    grand_total: 500,
                    payment_method: 'paystack',
                    payment_status: 'paid'
                });
                assert(html.indexOf('Paystack') !== -1, 'receipt missing Paystack');
                assert(html.indexOf('Payment Method') !== -1, 'receipt missing payment method');
                assert(html.indexOf('>Card<') === -1 && html.indexOf('Card</') === -1, 'receipt showed Card');
                assert(html.indexOf('M-PESA') === -1, 'receipt showed M-PESA');
                assert(html.indexOf('>Cash<') === -1, 'receipt showed Cash');
            });
        })
        .then(function () {
            if (failed) {
                console.error('\n' + failed + ' failed, ' + passed + ' passed');
                process.exit(1);
            }
            console.log('\n' + passed + ' passed');
        });
}

run();
