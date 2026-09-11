(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (typeof window !== 'undefined') {
        window.MunchDashboardKpis = api;
    }
    if (typeof jQuery === 'function') {
        jQuery(function () {
            api.boot(jQuery);
        });
    }
}(typeof window !== 'undefined' ? window : this, function () {
    var FALLBACK_MS = 30000;
    var RECONNECT_MS = 2000;

    function currentFilters() {
        var timeframe = '';
        var branch = 'all';
        var from = '';
        var to = '';
        if (typeof document !== 'undefined') {
            var timeframeEl = document.getElementById('kpi-timeframe');
            var branchEl = document.getElementById('kpi-branch');
            var fromEl = document.getElementById('kpi-from');
            var toEl = document.getElementById('kpi-to');
            timeframe = timeframeEl ? (timeframeEl.value || 'today') : 'today';
            branch = branchEl ? (branchEl.value || 'all') : 'all';
            from = fromEl ? fromEl.value : '';
            to = toEl ? toEl.value : '';
        }
        return {
            branch_id: branch,
            timeframe: timeframe || 'today',
            from: from,
            to: to
        };
    }

    function eventKey(event) {
        return String(event && event.order_id != null ? event.order_id : '') + ':' + String(event && event.version != null ? event.version : '');
    }

    function eventAffectsFilters(event, filters) {
        if (!event) {
            return false;
        }
        filters = filters || currentFilters();
        if (filters.branch_id && filters.branch_id !== 'all') {
            if (String(event.branch_id) !== String(filters.branch_id)) {
                return false;
            }
        }
        if (!event.created_at && !event.date) {
            return true;
        }
        var created = Date.parse(String(event.created_at || event.date).replace(' ', 'T'));
        if (isNaN(created)) {
            return true;
        }
        if (filters.from) {
            var fromMs = Date.parse(filters.from + (filters.from.length === 10 ? 'T00:00:00' : ''));
            if (!isNaN(fromMs) && created < fromMs) {
                return false;
            }
        }
        if (filters.to) {
            var toValue = filters.to.length === 10 ? filters.to + 'T23:59:59' : filters.to;
            var toMs = Date.parse(toValue);
            if (!isNaN(toMs) && created > toMs) {
                return false;
            }
        }
        return true;
    }

    function shouldIgnoreDuplicate(seen, event) {
        var key = eventKey(event);
        if (!key || key === ':') {
            return false;
        }
        if (seen[key]) {
            return true;
        }
        seen[key] = true;
        var keys = Object.keys(seen);
        if (keys.length > 200) {
            delete seen[keys[0]];
        }
        return false;
    }

    function boot($) {
        var $root = $('#munch-dash-kpis');
        if (!$root.length) {
            return;
        }

        var lastFilters = currentFilters();
        var seen = {};
        var since = 0;
        var didHandshake = false;
        var pollTimer = null;
        var reconnectTimer = null;
        var abortCtrl = null;
        var connected = false;
        var started = false;

        function syncCustomDates() {
            $root.toggleClass('is-custom', $('#kpi-timeframe').val() === 'custom');
        }

        function applyKpis(data) {
            if (!data) {
                return;
            }
            $('#kpi-munch-sales').text(data.munch_sales);
            $('#kpi-cash').text(data.cash);
            $('#kpi-card').text(data.card);
            $('#kpi-mpesa').text(data.mpesa);
            $('#kpi-glovo').text(data.glovo);
            $('#kpi-uber').text(data.uber);
            $('#kpi-bolt-food').text(data.bolt_food);
            lastFilters = {
                branch_id: data.branch_id != null ? data.branch_id : currentFilters().branch_id,
                timeframe: data.timeframe || currentFilters().timeframe,
                from: data.from || currentFilters().from,
                to: data.to || currentFilters().to
            };
        }

        function loadSalesKpis() {
            var filters = currentFilters();
            var payload = {
                branch_id: filters.branch_id,
                timeframe: filters.timeframe
            };

            if (filters.timeframe === 'custom') {
                payload.from = filters.from;
                payload.to = filters.to;
                if (!payload.from || !payload.to) {
                    return;
                }
            }

            $root.addClass('is-loading');
            $.get($root.data('url'), payload)
                .done(applyKpis)
                .always(function () {
                    $root.removeClass('is-loading');
                });
        }

        function stopFallback() {
            if (pollTimer) {
                window.clearInterval(pollTimer);
                pollTimer = null;
            }
        }

        function startFallback() {
            if (pollTimer || typeof window === 'undefined') {
                return;
            }
            pollTimer = window.setInterval(loadSalesKpis, FALLBACK_MS);
        }

        function handleEvents(events) {
            var shouldRefetch = false;
            (events || []).forEach(function (event) {
                if (shouldIgnoreDuplicate(seen, event)) {
                    return;
                }
                if (eventAffectsFilters(event, lastFilters)) {
                    shouldRefetch = true;
                }
            });
            if (shouldRefetch) {
                loadSalesKpis();
            }
        }

        function stopRealtime() {
            if (abortCtrl && abortCtrl.abort) {
                abortCtrl.abort();
            }
            abortCtrl = null;
            if (reconnectTimer) {
                window.clearTimeout(reconnectTimer);
                reconnectTimer = null;
            }
        }

        function scheduleReconnect() {
            if (reconnectTimer || typeof window === 'undefined') {
                return;
            }
            reconnectTimer = window.setTimeout(function () {
                reconnectTimer = null;
                pollEvents();
            }, RECONNECT_MS);
        }

        function pollEvents() {
            var eventsUrl = $root.data('events-url');
            if (!eventsUrl || typeof fetch !== 'function') {
                startFallback();
                return;
            }
            if (typeof document !== 'undefined' && document.hidden) {
                startFallback();
                scheduleReconnect();
                return;
            }

            stopRealtime();
            abortCtrl = typeof AbortController === 'function' ? new AbortController() : null;
            var url = eventsUrl + (eventsUrl.indexOf('?') === -1 ? '?' : '&') + 'since=' + encodeURIComponent(since);
            if (didHandshake) {
                url += '&poll=1';
            }

            fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                signal: abortCtrl ? abortCtrl.signal : undefined
            })
                .then(function (res) {
                    if (!res.ok) {
                        throw new Error('kpi-events');
                    }
                    return res.json();
                })
                .then(function (payload) {
                    connected = true;
                    didHandshake = true;
                    $root.attr('data-realtime', 'connected');
                    stopFallback();
                    if (payload && payload.version != null) {
                        since = payload.version;
                    }
                    handleEvents(payload && payload.events ? payload.events : []);
                    pollEvents();
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') {
                        return;
                    }
                    connected = false;
                    $root.attr('data-realtime', 'disconnected');
                    startFallback();
                    scheduleReconnect();
                });
        }

        function onVisibilityOrOnline() {
            if (typeof document !== 'undefined' && document.hidden) {
                return;
            }
            loadSalesKpis();
            pollEvents();
        }

        syncCustomDates();
        $('#kpi-branch, #kpi-timeframe, #kpi-from, #kpi-to').on('change', function () {
            syncCustomDates();
            lastFilters = currentFilters();
            loadSalesKpis();
        });

        if (!started) {
            started = true;
            loadSalesKpis();
            pollEvents();
        }

        if (typeof document !== 'undefined') {
            document.addEventListener('visibilitychange', onVisibilityOrOnline);
        }
        if (typeof window !== 'undefined') {
            window.addEventListener('online', onVisibilityOrOnline);
        }

        return {
            loadSalesKpis: loadSalesKpis,
            handleEvents: handleEvents,
            isConnected: function () { return connected; }
        };
    }

    return {
        FALLBACK_MS: FALLBACK_MS,
        currentFilters: currentFilters,
        eventAffectsFilters: eventAffectsFilters,
        shouldIgnoreDuplicate: shouldIgnoreDuplicate,
        eventKey: eventKey,
        boot: boot
    };
}));
