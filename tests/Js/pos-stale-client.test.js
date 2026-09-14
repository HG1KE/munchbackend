'use strict';

var fs = require('fs');
var path = require('path');

var root = path.join(__dirname, '../..');
var js = fs.readFileSync(path.join(root, 'public/assets/admin/js/munch-pos-app.js'), 'utf8');
var page = fs.readFileSync(path.join(root, 'resources/views/branch-views/pos/index.blade.php'), 'utf8');
var css = fs.readFileSync(path.join(root, 'public/assets/admin/css/munch-pos.css'), 'utf8');
var controller = fs.readFileSync(path.join(root, 'app/Http/Controllers/Branch/POSController.php'), 'utf8');

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

function extractFn(source, name) {
    var needle = 'function ' + name;
    var start = source.indexOf(needle + '(');
    if (start < 0) start = source.indexOf(needle + ' ');
    if (start < 0) throw new Error(name + ' missing');
    var i = source.indexOf('{', start);
    var depth = 0;
    var quote = '';
    var escape = false;
    var j;
    for (j = i; j < source.length; j++) {
        var ch = source[j];
        if (quote) {
            if (escape) {
                escape = false;
                continue;
            }
            if (ch === '\\') {
                escape = true;
                continue;
            }
            if (ch === quote) quote = '';
            continue;
        }
        if (ch === '"' || ch === "'") {
            quote = ch;
            continue;
        }
        if (ch === '{') depth += 1;
        else if (ch === '}') {
            depth -= 1;
            if (depth === 0) return source.slice(start, j + 1);
        }
    }
    throw new Error(name + ' unclosed');
}

function modalMarkup() {
    var start = page.indexOf('id="pos-stale-modal"');
    assert(start !== -1, 'stale modal missing');
    return page.slice(start, start + 1200);
}

function requestAnimationFrame(fn) { fn(); }

var CFG = { assetVersion: 'loaded-build' };
var state = { staleClient: false };
var els = {
    staleModal: { hidden: true },
    staleRefresh: { focus: function () {} }
};
var overlayCalls = 0;
function syncPosOverlayState() { overlayCalls += 1; }
function renderStatus() { syncStaleModal(); }

var isPosClientStale;
var syncStaleModal;
var applyStaleClient;
eval('isPosClientStale = ' + extractFn(js, 'isPosClientStale'));
eval('syncStaleModal = ' + extractFn(js, 'syncStaleModal'));
eval('applyStaleClient = ' + extractFn(js, 'applyStaleClient'));

test('new version detected opens the blocking modal', function () {
    state.staleClient = false;
    els.staleModal.hidden = true;
    applyStaleClient('deployed-build');
    assert(state.staleClient === true, 'mismatch must mark the client stale');
    assert(els.staleModal.hidden === false, 'stale modal must open');
    assert(page.indexOf('id="pos-stale-modal"') !== -1, 'blocking modal missing');
    assert(page.indexOf("translate('New POS version available')") !== -1, 'title missing');
    assert(page.indexOf("translate('A new version of the POS is available. Please refresh before placing new orders.')") !== -1, 'message missing');
});

test('stale modal has no dismiss or close action', function () {
    var modal = modalMarkup();
    assert(modal.indexOf('pos-stale-dismiss') === -1, 'dismiss control must not exist');
    assert(modal.indexOf('pos-stale-close') === -1, 'close control must not exist');
    assert(modal.indexOf('munch-pos-clear') === -1, 'secondary close button must not exist');
    assert(modal.indexOf('×') === -1 && modal.indexOf('&times;') === -1, 'X close must not exist');
    assert(js.indexOf('closeStaleModal') === -1, 'there must be no stale close helper');
    var bind = extractFn(js, 'bind');
    assert(bind.indexOf("ev.target.id === 'pos-stale-modal'") === -1, 'outside click must not close the modal');
    assert(js.indexOf("if (ev.key === 'Escape')") !== -1, 'Escape handler missing');
    assert(js.indexOf('if (isStaleModalOpen())') !== -1, 'Escape must see the stale modal');
    var escapeAt = js.indexOf("if (ev.key === 'Escape')");
    var staleAt = js.indexOf('if (isStaleModalOpen())', escapeAt);
    var cancelAt = js.indexOf('if (cancelUi.open)', escapeAt);
    assert(staleAt !== -1 && staleAt < cancelAt, 'Escape must ignore the stale modal before other dismissals');
    assert(js.indexOf('els.staleModal.hidden = true') === -1, 'Escape must not hide the stale modal');
});

test('Refresh POS is the only action', function () {
    var modal = modalMarkup();
    var buttons = modal.match(/<button\b[^>]*>/g) || [];
    assert(buttons.length === 1, 'expected exactly one button, got ' + buttons.length);
    assert(modal.indexOf('id="pos-stale-refresh"') !== -1, 'Refresh POS button missing');
    assert(modal.indexOf("translate('Refresh POS')") !== -1, 'Refresh POS label missing');
    assert(modal.indexOf('class="munch-pos-place" id="pos-stale-refresh"') !== -1, 'Refresh POS must be the primary button');
    assert(css.indexOf('.munch-pos-stale-modal .munch-pos-dialog__actions') !== -1, 'stale actions must stay full width');
    assert(css.indexOf('grid-template-columns: 1fr') !== -1, 'Refresh POS must be full width');
    assert(page.indexOf('id="pos-stale-banner"') === -1, 'header banner must be gone');
    assert(page.indexOf('munch-pos-modal munch-pos-stale-modal') !== -1, 'must reuse munch-pos-modal styling');
});

test('Refresh POS triggers the existing reload', function () {
    var bind = extractFn(js, 'bind');
    var refreshAt = bind.indexOf("els.staleRefresh.addEventListener('click'");
    var reloadAt = bind.indexOf('window.location.reload()', refreshAt);
    assert(refreshAt !== -1, 'Refresh POS must keep the click listener');
    assert(reloadAt !== -1 && reloadAt - refreshAt < 180, 'Refresh POS must reload the page');
});

test('current version does not show the modal', function () {
    state.staleClient = true;
    els.staleModal.hidden = false;
    applyStaleClient('loaded-build');
    assert(state.staleClient === false, 'matching versions must clear stale');
    assert(els.staleModal.hidden === true, 'modal must hide on the latest version');
    applyStaleClient('');
    assert(state.staleClient === false, 'empty server version must not force the modal');
});

test('a newly deployed version is detected without hardcoding the version', function () {
    var apply = extractFn(js, 'applyStaleClient');
    assert(apply.indexOf('String(serverVersion) !== String(CFG.assetVersion)') !== -1, 'must compare server vs loaded asset version');
    assert(apply.indexOf("=== '6.") === -1, 'must not hardcode a 6.x version');
    assert(apply.indexOf("=== '6.4'") === -1, 'must not hardcode 6.4');
    assert(apply.indexOf("=== '6.5'") === -1, 'must not hardcode 6.5');
    assert(apply.indexOf("=== '6.6'") === -1, 'must not hardcode 6.6');
    assert(apply.indexOf("=== '6.7'") === -1, 'must not hardcode 6.7');
    assert(js.indexOf('json.pos_asset_version') !== -1, 'heartbeat must still send the live version');
    assert(js.indexOf('applyStaleClient(json.pos_asset_version)') !== -1, 'heartbeat must apply the live version');
    assert(controller.indexOf("'pos_asset_version' => PosClientVersion::ASSET") !== -1, 'server must keep publishing PosClientVersion::ASSET');

    CFG.assetVersion = 'tab-build';
    applyStaleClient('origin-build');
    assert(isPosClientStale() === true, 'any deployed mismatch must be stale');
    applyStaleClient('tab-build');
    assert(isPosClientStale() === false, 'matching live version must not reopen the modal');
});

test('stale client still blocks placing a new order', function () {
    var place = extractFn(js, 'placeOrder');
    var market = extractFn(js, 'confirmMarketplaceAndPlace');
    var delivery = extractFn(js, 'confirmDeliveryAndPlace');
    assert(place.indexOf('isPosClientStale()') !== -1, 'Place Order must still refuse a stale tab');
    assert(place.indexOf('syncStaleModal()') !== -1, 'Place Order must keep the blocking modal visible');
    assert(market.indexOf('isPosClientStale()') !== -1, 'marketplace confirm must still refuse a stale tab');
    assert(delivery.indexOf('isPosClientStale()') !== -1, 'delivery confirm must still refuse a stale tab');
    assert(place.indexOf('beginOrderSubmit()') !== -1, 'order placement lock must remain');
});

if (failed) {
    console.error(failed + ' failed, ' + passed + ' passed');
    process.exit(1);
}

console.log(passed + ' passed');
