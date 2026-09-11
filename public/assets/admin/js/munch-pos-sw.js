/* Branch POS service worker — caches the POS shell/catalog and triggers queue sync. */
var CACHE = 'munch-pos-shell-v28';
var syncRunning = false;

self.addEventListener('install', function (event) {
    self.skipWaiting();
    event.waitUntil(caches.open(CACHE));
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (key) {
                return key.indexOf('munch-pos-shell-') === 0 && key !== CACHE;
            }).map(function (key) {
                return caches.delete(key);
            }));
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function (event) {
    var request = event.request;
    if (request.method !== 'GET') return;
    var url = new URL(request.url);
    var isPos = url.pathname.indexOf('/branch/pos') === 0 || url.pathname.indexOf('/assets/admin/js/munch-pos') !== -1 || url.pathname.indexOf('/assets/admin/css/munch-pos') !== -1;
    if (!isPos) return;
    event.respondWith(
        fetch(request).then(function (response) {
            if (response && response.ok) {
                var copy = response.clone();
                caches.open(CACHE).then(function (cache) { cache.put(request, copy); });
            }
            return response;
        }).catch(function () {
            return caches.match(request);
        })
    );
});

self.addEventListener('sync', function (event) {
    if (event.tag !== 'munch-pos-sync') return;
    if (syncRunning) return;
    syncRunning = true;
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
            clients.forEach(function (client) {
                client.postMessage({ type: 'munch-pos-sync' });
            });
        }).then(function () {
            syncRunning = false;
        }).catch(function () {
            syncRunning = false;
        })
    );
});
