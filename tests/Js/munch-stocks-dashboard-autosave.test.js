'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');

var source = fs.readFileSync(
    path.join(__dirname, '../../public/assets/admin/js/munch-stocks-dashboard.js'),
    'utf8'
);

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

function loadDashboard() {
    var sandbox = {
        console: console,
        setTimeout: setTimeout,
        clearTimeout: clearTimeout,
        URLSearchParams: URLSearchParams,
        document: {
            readyState: 'complete',
            getElementById: function () {
                return null;
            },
            querySelector: function () {
                return null;
            },
            addEventListener: function () {}
        }
    };
    sandbox.window = sandbox;
    vm.runInNewContext(source, sandbox);
    return sandbox.MunchStocksDashboard;
}

function run() {
    var api = loadDashboard();

    return test('exports autosave helpers', function () {
        assert(api, 'MunchStocksDashboard missing');
        assert(typeof api.createAutosave === 'function', 'createAutosave');
        assert(typeof api.collectRowPayload === 'function', 'collectRowPayload');
        assert(api.AUTOSAVE_MS >= 400 && api.AUTOSAVE_MS <= 800, 'debounce in 400–800ms');
    })
        .then(function () {
            return test('formatResult still reports missing/excess', function () {
                assert(api.formatResult(null).kind === 'empty');
                assert(api.formatResult(0).kind === 'balanced');
                assert(api.formatResult(2).text === '2 excess');
                assert(api.formatResult(-1.5).text === '1.5 missing');
            });
        })
        .then(function () {
            return test('postRow sends partial=1 and row fields', function () {
                var posted = null;
                var statusText = '';
                var form = {
                    getAttribute: function (name) {
                        return name === 'action' ? '/stocks/counts' : null;
                    },
                    querySelector: function (sel) {
                        if (sel === 'input[name="_token"]') return { value: 'tok' };
                        if (sel === 'input[name="date"]') return { value: '2026-11-13' };
                        if (sel === 'input[name="branch_id"]') return { value: '4' };
                        return null;
                    }
                };
                var status = {
                    textContent: '',
                    setAttribute: function () {},
                    className: '',
                    set textContent(v) {
                        statusText = v;
                    },
                    get textContent() {
                        return statusText;
                    }
                };
                var autosave = api.createAutosave({
                    form: form,
                    statusEl: status,
                    debounceMs: 10,
                    fetchImpl: function (url, opts) {
                        posted = { url: url, body: String(opts.body), headers: opts.headers };
                        return Promise.resolve({
                            ok: true,
                            json: function () {
                                return Promise.resolve({ ok: true, saved: [9] });
                            }
                        });
                    }
                });

                return autosave
                    .postRow({
                        id: '9',
                        opening: '3',
                        received: '',
                        spoiled: '0',
                        closing: ''
                    })
                    .then(function () {
                        assert(posted.url === '/stocks/counts', 'posts to counts');
                        assert(posted.body.indexOf('partial=1') >= 0, 'partial flag');
                        assert(
                            posted.body.indexOf('opening%5B9%5D=3') >= 0 ||
                                posted.body.indexOf('opening[9]=3') >= 0,
                            'opening field'
                        );
                        assert(posted.body.indexOf('branch_id=4') >= 0, 'branch');
                        assert(statusText === 'Saved', 'status Saved, got: ' + statusText);
                    });
            });
        })
        .then(function () {
            console.log(passed + ' passed, ' + failed + ' failed');
            if (failed > 0) process.exit(1);
        });
}

run();
