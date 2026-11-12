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
var css = fs.readFileSync(path.join(root, 'public/assets/admin/css/munch-pos.css'), 'utf8');
var blade = fs.readFileSync(path.join(root, 'resources/views/branch-views/pos/index.blade.php'), 'utf8');
var guard = fs.readFileSync(path.join(root, 'app/Support/PosNearDuplicateGuard.php'), 'utf8');
var version = fs.readFileSync(path.join(root, 'app/Support/PosClientVersion.php'), 'utf8');
var copy = 'Duplicate order detected. You just posted the same order.';

test('POS toast shows near-duplicate copy plainly', function () {
    assert(js.indexOf("body.code === 'near_duplicate'") !== -1, 'must detect near_duplicate code');
    assert(js.indexOf('toast(nearMessage, 5200, { danger: true })') !== -1, 'must toast danger style longer');
    assert(js.indexOf(copy) !== -1, 'copy missing');
    assert(blade.indexOf('nearDuplicate:') !== -1, 'blade label missing');
    assert(blade.indexOf(copy) !== -1, 'blade copy missing');
});

test('near-duplicate toast is centered and red', function () {
    assert(css.indexOf('.munch-pos-toast.munch-pos-toast--danger') !== -1, 'danger toast class missing');
    assert(css.indexOf('top: 50%') !== -1, 'must center vertically');
    assert(css.indexOf('background: #dc2626') !== -1, 'must use red danger background');
    assert(css.indexOf('z-index: 80') !== -1, 'danger toast must sit above other POS overlays');
    assert(js.indexOf("classList.toggle('munch-pos-toast--danger'") !== -1, 'must apply danger class');
    assert(js.indexOf("els.toast.style.top = '50%'") !== -1, 'must inline mid-page top');
    assert(js.indexOf("els.toast.style.background = '#dc2626'") !== -1, 'must inline red background');
});

test('guard hard-blocks within two minutes', function () {
    assert(guard.indexOf('WINDOW_SECONDS = 120') !== -1, 'window should be 120s');
    assert(guard.indexOf('CODE = \'near_duplicate\'') !== -1, 'code constant missing');
    assert(guard.indexOf(copy) !== -1, 'message missing');
});

test('asset version bumped for POS clients', function () {
    assert(version.indexOf("ASSET = '6.17'") !== -1, 'PosClientVersion should be 6.17');
    var layout = fs.readFileSync(path.join(root, 'resources/views/layouts/branch/pos.blade.php'), 'utf8');
    assert(layout.indexOf("munch-pos.css') }}?v={{ \\App\\Support\\PosClientVersion::ASSET }}") !== -1,
        'POS CSS must cache-bust with PosClientVersion::ASSET');
});

console.log(passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
