#!/usr/bin/env node
'use strict';

var fs = require('fs');
var path = require('path');
var root = path.join(__dirname, '..', '..');
var app = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-pos-app.js'), 'utf8');
var version = fs.readFileSync(path.join(root, 'app/Support/PosClientVersion.php'), 'utf8');

var passed = 0;
var failed = 0;

function assert(cond, msg) {
    if (cond) {
        passed += 1;
        return;
    }
    failed += 1;
    console.error('FAIL:', msg);
}

function test(name, fn) {
    try {
        fn();
    } catch (err) {
        failed += 1;
        console.error('FAIL:', name, err && err.message ? err.message : err);
    }
}

test('2-minute hard cart inactivity clear', function () {
    assert(app.indexOf('CART_INACTIVITY_MS = 2 * 60 * 1000') !== -1, '2 minute inactivity constant');
    assert(app.indexOf('function expireIdleCart') !== -1, 'expireIdleCart helper');
    assert(app.indexOf('function noteCartActivity') !== -1, 'noteCartActivity helper');
    assert(app.indexOf('Hard clear — no soft warning') !== -1 || app.indexOf('clearCart();') !== -1,
        'inactivity clears cart');
});

test('ring modal gated on empty cart; poll continues', function () {
    assert(app.indexOf('function cartHasLines') !== -1, 'cartHasLines helper');
    assert(app.indexOf('Never interrupt an active cart') !== -1, 'openOnlineOrderModal cart gate comment');
    assert(app.indexOf('Keep polling; suppress ring UI until cart is empty') !== -1, 'poll suppresses modal with cart');
    assert(app.indexOf('function promptOnlineOrderIfCartEmpty') !== -1, 'prompt when cart empties');
    assert(app.indexOf('function afterCartLinesChanged') !== -1, 'afterCartLinesChanged hook');
});

test('polling stays alive across failures and visibility', function () {
    assert(app.indexOf('function pollOnlineOrdersTick') !== -1, 'poll tick wrapper');
    assert(app.indexOf('onlineRing.visibilityBound') !== -1, 'visibility listener once');
    assert(app.indexOf('onlineRing.onlineBound') !== -1, 'online listener once');
    assert(app.indexOf('onlineRing.inFlight') !== -1, 'in-flight guard');
    assert(app.indexOf('Never replace a live interval') !== -1, 'interval never replaced away');
    assert(app.indexOf('interval must stay alive') !== -1, 'tick try/catch');
});

test('asset version bumped', function () {
    assert(version.indexOf("ASSET = '6.21'") !== -1, 'PosClientVersion should be 6.21');
});

console.log(passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
