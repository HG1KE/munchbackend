'use strict';

var fs = require('fs');
var path = require('path');

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

var root = path.join(__dirname, '../..');
var js = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-pos-app.js'), 'utf8');
var blade = fs.readFileSync(path.join(root, 'resources/views/branch-views/pos/index.blade.php'), 'utf8');
var guard = fs.readFileSync(path.join(root, 'app/Support/PosNearDuplicateGuard.php'), 'utf8');
var version = fs.readFileSync(path.join(root, 'app/Support/PosClientVersion.php'), 'utf8');

test('POS toast shows near-duplicate copy plainly', function () {
    assert(js.indexOf("body.code === 'near_duplicate'") !== -1, 'must detect near_duplicate code');
    assert(js.indexOf('toast(nearMessage, 5200)') !== -1, 'must toast the plain message longer');
    assert(js.indexOf('This looks like an order you just placed. Open that order to edit it, or cancel it first.') !== -1, 'copy missing');
    assert(blade.indexOf('nearDuplicate:') !== -1, 'blade label missing');
});

test('guard hard-blocks within two minutes', function () {
    assert(guard.indexOf('WINDOW_SECONDS = 120') !== -1, 'window should be 120s');
    assert(guard.indexOf('CODE = \'near_duplicate\'') !== -1, 'code constant missing');
    assert(guard.indexOf('This looks like an order you just placed') !== -1, 'message missing');
});

test('asset version bumped for POS clients', function () {
    assert(version.indexOf("ASSET = '6.11'") !== -1, 'PosClientVersion should be 6.11');
});

console.log(passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
