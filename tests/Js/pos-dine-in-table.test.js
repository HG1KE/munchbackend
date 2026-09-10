'use strict';

var path = require('path');
var dineIn = require(path.join(
    __dirname,
    '../../public/assets/admin/js/munch-pos-dine-in.js'
));

var failed = 0;
var passed = 0;
var labels = {
    table: 'please select a table number',
    people: 'please enter people number'
};

function assert(cond, message) {
    if (!cond) throw new Error(message || 'assertion failed');
}

function cart(overrides) {
    return Object.assign({
        lines: [{ productId: 1, quantity: 1 }],
        orderType: 'dine_in',
        tableId: '7',
        people: '2',
        payment: 'cash',
        discount: 0,
        address: { contact_person_name: 'Jane' }
    }, overrides || {});
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

test('Dine In with selected table succeeds', function () {
    assert(dineIn.validate(cart(), labels) === null, 'expected no validation error');
    var payload = dineIn.payloadFields(cart());
    assert(payload.table_id === '7', 'table should reach payload');
    assert(payload.people_number === '2', 'people should reach payload');
});

test('Dine In without table is rejected', function () {
    var error = dineIn.validate(cart({ tableId: '' }), labels);
    assert(error === labels.table, 'expected table validation, got ' + error);
    assert(dineIn.payloadFields(cart({ tableId: '  ' })).table_id === null, 'blank table should not post');
});

test('Selected table reaches offline queue payload', function () {
    var queued = dineIn.payloadFields(cart({ tableId: '15', people: '4' }));
    assert(queued.table_id === '15');
    assert(queued.people_number === '4');
});

test('Selected table reaches online POST payload', function () {
    var posted = dineIn.payloadFields(cart({ tableId: '9', people: '1' }));
    assert(String(posted.table_id) === '9');
});

test('Switching payment method does not clear table', function () {
    var next = dineIn.applyPatch(cart({ tableId: '7', payment: 'cash' }), { payment: 'card' });
    assert(next.tableId === '7', 'payment change cleared table');
    assert(next.people === '2', 'payment change cleared people');
    assert(next.payment === 'card');
});

test('Switching customer does not clear table', function () {
    var next = dineIn.applyPatch(cart({ tableId: '7' }), {
        address: { contact_person_name: 'Alex', contact_person_number: '0700000000' }
    });
    assert(next.tableId === '7', 'customer change cleared table');
    assert(next.people === '2');
    assert(next.address.contact_person_name === 'Alex');
});

test('Switching between Dine In and other order types keeps table for return', function () {
    var takeaway = dineIn.applyPatch(cart({ tableId: '7' }), { orderType: 'take_away' });
    assert(takeaway.tableId === '7', 'leaving dine-in should keep table in cart');
    assert(dineIn.payloadFields(takeaway).table_id === null, 'takeaway must not post table');
    assert(dineIn.validate(takeaway, labels) === null, 'takeaway should not require table');

    var back = dineIn.applyPatch(takeaway, { orderType: 'dine_in' });
    assert(back.tableId === '7', 'returning to dine-in lost table');
    assert(dineIn.validate(back, labels) === null);

    var delivery = dineIn.applyPatch(back, { orderType: 'delivery' });
    assert(dineIn.payloadFields(delivery).table_id === null);
    assert(dineIn.validate(delivery, labels) === null);
});

test('Existing POS order types remain unaffected', function () {
    ['take_away', 'delivery', 'glovo', 'uber', 'bolt_food'].forEach(function (type) {
        var current = cart({ orderType: type, tableId: '' });
        assert(dineIn.validate(current, labels) === null, type + ' should not require a table');
        assert(dineIn.payloadFields(current).table_id === null, type + ' should not send table_id');
    });
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}
console.log(passed + ' passed');
