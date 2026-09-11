'use strict';

var path = require('path');
var kpis = require(path.join(
    __dirname,
    '../../public/assets/admin/js/munch-dashboard-kpis.js'
));

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

function run() {
    return test('all branches + today refetch a qualifying sale', function () {
        var event = { branch_id: 4, created_at: '2026-09-11 10:00:00', category: 'munch', order_id: 1, version: 1 };
        assert(kpis.eventAffectsFilters(event, {
            branch_id: 'all',
            timeframe: 'today',
            from: '2026-09-11',
            to: '2026-09-11'
        }), 'today all-branches should match');
    })
        .then(function () {
            return test('kilimani filter ignores a nyali sale', function () {
                var event = { branch_id: 9, created_at: '2026-09-11 10:00:00', category: 'munch', order_id: 2, version: 2 };
                assert(!kpis.eventAffectsFilters(event, {
                    branch_id: '4',
                    timeframe: 'today',
                    from: '2026-09-11',
                    to: '2026-09-11'
                }), 'nyali sale must not affect kilimani');
            });
        })
        .then(function () {
            return test('yesterday filter ignores a today sale', function () {
                var event = { branch_id: 4, created_at: '2026-09-11 10:00:00', category: 'munch', order_id: 3, version: 3 };
                assert(!kpis.eventAffectsFilters(event, {
                    branch_id: 'all',
                    timeframe: 'yesterday',
                    from: '2026-09-10',
                    to: '2026-09-10'
                }), 'today sale must not affect yesterday');
            });
        })
        .then(function () {
            return test('marketplace and munch events still match the open filters', function () {
                var filters = { branch_id: '4', timeframe: 'today', from: '2026-09-11', to: '2026-09-11' };
                assert(kpis.eventAffectsFilters({
                    branch_id: 4, created_at: '2026-09-11 11:00:00', category: 'glovo', order_id: 4, version: 4
                }, filters));
                assert(kpis.eventAffectsFilters({
                    branch_id: 4, created_at: '2026-09-11 11:00:00', category: 'uber', order_id: 5, version: 5
                }, filters));
                assert(kpis.eventAffectsFilters({
                    branch_id: 4, created_at: '2026-09-11 11:00:00', category: 'bolt_food', order_id: 6, version: 6
                }, filters));
                assert(kpis.eventAffectsFilters({
                    branch_id: 4, created_at: '2026-09-11 11:00:00', category: 'munch', payment_method: 'mpesa', order_id: 7, version: 7
                }, filters));
            });
        })
        .then(function () {
            return test('duplicate events are ignored before refetch', function () {
                var seen = {};
                var event = { order_id: 10, version: 8 };
                assert(!kpis.shouldIgnoreDuplicate(seen, event), 'first delivery is new');
                assert(kpis.shouldIgnoreDuplicate(seen, event), 'second delivery is duplicate');
            });
        })
        .then(function () {
            return test('fallback interval matches live cards and never reloads the page', function () {
                var fs = require('fs');
                var src = fs.readFileSync(path.join(
                    __dirname,
                    '../../public/assets/admin/js/munch-dashboard-kpis.js'
                ), 'utf8');
                assert(kpis.FALLBACK_MS === 30000, 'fallback must stay at 30s');
                assert(src.indexOf('location.reload') === -1, 'must not reload the browser');
                assert(src.indexOf('window.location') === -1, 'must not assign window.location');
                assert(src.indexOf('loadSalesKpis()') !== -1, 'must refetch the authoritative KPI query');
                assert(src.indexOf('data-events-url') !== -1 || src.indexOf('events-url') !== -1, 'must subscribe to the events URL');
                assert(src.indexOf('setInterval(loadSalesKpis, 1000)') === -1, 'must not poll every second');
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
