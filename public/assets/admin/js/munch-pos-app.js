(function () {
    'use strict';

    var CFG = window.MUNCH_POS || {};
    var DB_NAME = 'munch_pos_v1';
    var DB_VERSION = 1;
    var CART_KEY = 'current';
    var state = {
        catalog: CFG.catalog || { products: [], categories: [], addons: [], tables: [], delivery: {} },
        cart: { lines: [], orderType: 'take_away', tableId: '', people: '', discount: 0, discountType: 'amount', payment: 'cash', paid: '', address: {} },
        categoryId: 0,
        search: '',
        online: navigator.onLine,
        queueCount: 0,
        queueItems: [],
        authRequired: false,
        syncLabel: '',
        syncKind: '',
        placing: false,
        productMap: {},
        addonMap: {}
    };
    var els = {};
    var renderScheduled = false;
    var toastTimer = 0;

    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    function openDb() {
        return new Promise(function (resolve, reject) {
            var req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = function () {
                var db = req.result;
                if (!db.objectStoreNames.contains('catalog')) db.createObjectStore('catalog');
                if (!db.objectStoreNames.contains('cart')) db.createObjectStore('cart');
                if (!db.objectStoreNames.contains('queue')) db.createObjectStore('queue', { keyPath: 'id' });
                if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta');
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
    }

    function idbOp(store, mode, fn) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(store, mode);
                var result = fn(tx.objectStore(store));
                tx.oncomplete = function () { resolve(result); };
                tx.onerror = function () { reject(tx.error); };
            });
        });
    }

    function idbGet(store, key) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(store, 'readonly');
                var req = tx.objectStore(store).get(key);
                req.onsuccess = function () { resolve(req.result); };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function idbPut(store, value, key) {
        return idbOp(store, 'readwrite', function (s) {
            if (typeof key === 'undefined') s.put(value);
            else s.put(value, key);
        });
    }

    function idbGetAll(store) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(store, 'readonly');
                var req = tx.objectStore(store).getAll();
                req.onsuccess = function () { resolve(req.result || []); };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function idbDelete(store, key) {
        return idbOp(store, 'readwrite', function (s) { s.delete(key); });
    }

    function money(amount) {
        var dec = Number(state.catalog.decimal || 0);
        var n = Number(amount || 0);
        var formatted = n.toFixed(dec);
        var symbol = state.catalog.currency_symbol || 'Ksh';
        if ((state.catalog.currency_position || 'left') === 'left') return symbol + formatted;
        return formatted + symbol;
    }

    function indexCatalog(catalog) {
        state.catalog = catalog;
        state.productMap = {};
        state.addonMap = {};
        (catalog.products || []).forEach(function (p) { state.productMap[p.id] = p; });
        (catalog.addons || []).forEach(function (a) { state.addonMap[a.id] = a; });
    }

    function variationPrice(product, selections) {
        var extra = 0;
        (product.variations || []).forEach(function (group, gi) {
            var picked = (selections[gi] && selections[gi].values && selections[gi].values.label) || [];
            (group.values || []).forEach(function (opt) {
                if (picked.indexOf(opt.label) !== -1) extra += Number(opt.optionPrice || 0);
            });
        });
        return extra;
    }

    function lineUnit(line) {
        var product = state.productMap[line.productId];
        if (!product) return 0;
        var unit = Number(product.price) - Number(product.discount || 0) + variationPrice(product, line.variations || []);
        (line.addon_id || []).forEach(function (id) {
            var addon = state.addonMap[id];
            var qty = Number((line.addon_quantities && line.addon_quantities[id]) || 1);
            unit += Number(addon ? addon.price : 0) * qty;
        });
        return unit;
    }

    function lineSubtotal(line) {
        return lineUnit(line) * Number(line.quantity || 1);
    }

    function cartSubtotal() {
        return state.cart.lines.reduce(function (sum, line) { return sum + lineSubtotal(line); }, 0);
    }

    function extraDiscount(subtotal) {
        var value = Number(state.cart.discount || 0);
        if (value <= 0) return 0;
        if (state.cart.discountType === 'percent') return subtotal * value / 100;
        return Math.min(value, subtotal);
    }

    function deliveryCharge(subtotal) {
        if (state.cart.orderType !== 'delivery') return 0;
        var setup = state.catalog.delivery || {};
        if (Number(setup.free_over_status) === 1 && subtotal >= Number(setup.free_over_amount || 0) && Number(setup.free_over_amount) > 0) {
            return 0;
        }
        if (setup.type === 'area') {
            var areaId = Number(state.cart.address.selected_area_id || 0);
            var area = (setup.areas || []).find(function (row) { return row.id === areaId; });
            return area ? Number(area.charge || 0) : 0;
        }
        if (setup.type === 'distance') return Number(setup.minimum_charge || 0);
        return Number(setup.fixed_charge || 0);
    }

    function grandTotal() {
        var sub = cartSubtotal();
        return Math.max(0, sub + deliveryCharge(sub) - extraDiscount(sub));
    }

    function filteredProducts() {
        var q = (state.search || '').trim().toLowerCase();
        return (state.catalog.products || []).filter(function (p) {
            if (state.categoryId && (p.category_ids || []).indexOf(state.categoryId) === -1) return false;
            if (q && String(p.name || '').toLowerCase().indexOf(q) === -1 && String(p.id) !== q) return false;
            return true;
        });
    }

    function scheduleRender() {
        if (renderScheduled) return;
        renderScheduled = true;
        requestAnimationFrame(function () {
            renderScheduled = false;
            renderAll();
        });
    }

    function renderAll() {
        renderStatus();
        renderTabs();
        renderGrid();
        renderTypes();
        renderExtras();
        renderLines();
        renderQueue();
        renderTotals();
        renderPay();
    }

    function renderStatus() {
        var badge = els.conn;
        if (!badge) return;
        badge.textContent = state.online ? CFG.labels.online : CFG.labels.offline;
        badge.className = 'munch-pos-badge ' + (state.online ? 'munch-pos-badge--online' : 'munch-pos-badge--offline');
        if (els.queue) {
            if (state.queueCount > 0) {
                els.queue.hidden = false;
                els.queue.textContent = state.queueCount + ' ' + CFG.labels.queued;
            } else {
                els.queue.hidden = true;
            }
        }
        if (els.auth) {
            els.auth.hidden = !state.authRequired;
            if (els.authLink && CFG.urls.login) els.authLink.href = CFG.urls.login;
        }
        if (els.sync) {
            if (state.syncLabel) {
                els.sync.hidden = false;
                els.sync.textContent = state.syncLabel;
                els.sync.className = 'munch-pos-badge ' + (state.syncKind === 'err' ? 'munch-pos-badge--err' : (state.syncKind === 'ok' ? 'munch-pos-badge--ok' : 'munch-pos-badge--sync'));
            } else {
                els.sync.hidden = true;
            }
        }
    }

    function renderTabs() {
        if (!els.tabs) return;
        var html = '<button type="button" class="munch-pos-tab' + (state.categoryId === 0 ? ' is-active' : '') + '" data-cat="0">' + escapeHtml(CFG.labels.all) + '</button>';
        (state.catalog.categories || []).forEach(function (cat) {
            html += '<button type="button" class="munch-pos-tab' + (state.categoryId === cat.id ? ' is-active' : '') + '" data-cat="' + cat.id + '">' + escapeHtml(cat.name) + '</button>';
        });
        els.tabs.innerHTML = html;
    }

    function renderGrid() {
        if (!els.grid) return;
        var list = filteredProducts();
        if (els.empty) els.empty.hidden = list.length > 0;
        var html = '';
        var i;
        var start = 0;
        var end = list.length;
        if (list.length > 80) {
            var rowH = 300;
            var cols = Math.max(2, Math.floor(els.grid.clientWidth / 220) || 2);
            var top = els.grid.scrollTop;
            var vis = Math.ceil(els.grid.clientHeight / rowH) + 3;
            var startRow = Math.max(0, Math.floor(top / rowH) - 1);
            start = startRow * cols;
            end = Math.min(list.length, start + vis * cols);
            html += '<div class="munch-pos-virt" style="grid-column:1/-1;height:' + (startRow * rowH) + 'px"></div>';
        }
        for (i = start; i < end; i++) html += productCard(list[i]);
        if (list.length > 80) {
            var remain = Math.ceil((list.length - end) / cols);
            html += '<div class="munch-pos-virt" style="grid-column:1/-1;height:' + (remain * 300) + 'px"></div>';
        }
        els.grid.innerHTML = html;
    }

    function productCard(product) {
        var img = product.image || state.catalog.placeholder_image || '';
        return '<article class="munch-pos-card" data-id="' + product.id + '">' +
            '<img src="' + escapeAttr(img) + '" alt="" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=\'' + escapeAttr(state.catalog.placeholder_image || '') + '\'">' +
            '<div class="munch-pos-card__body">' +
            '<div class="munch-pos-card__name">' + escapeHtml(product.name) + '</div>' +
            '<div class="munch-pos-card__price">' + money(product.price - (product.discount || 0)) + '</div>' +
            '<button type="button" class="munch-pos-card__add" data-add="' + product.id + '">' + escapeHtml(CFG.labels.add) + '</button>' +
            '</div></article>';
    }

    function renderTypes() {
        if (!els.types) return;
        var types = [
            ['delivery', CFG.labels.delivery],
            ['take_away', CFG.labels.takeAway],
            ['dine_in', CFG.labels.dineIn],
            ['glovo', CFG.labels.glovo],
            ['uber', CFG.labels.uber],
            ['bolt_food', CFG.labels.boltFood]
        ];
        els.types.innerHTML = types.map(function (row) {
            return '<button type="button" class="munch-pos-type' + (state.cart.orderType === row[0] ? ' is-active' : '') + '" data-type="' + row[0] + '">' + escapeHtml(row[1]) + '</button>';
        }).join('');
    }

    function renderExtras() {
        if (els.dineIn) els.dineIn.hidden = state.cart.orderType !== 'dine_in';
        if (els.delivery) els.delivery.hidden = state.cart.orderType !== 'delivery';
        if (els.table && !els.table.dataset.ready) {
            var opts = '<option value="">' + escapeHtml(CFG.labels.table) + '</option>';
            (state.catalog.tables || []).forEach(function (table) {
                opts += '<option value="' + table.id + '">#' + escapeHtml(table.number) + '</option>';
            });
            els.table.innerHTML = opts;
            els.table.dataset.ready = '1';
        }
        if (els.table) els.table.value = state.cart.tableId || '';
        if (els.people) els.people.value = state.cart.people || '';
        var areas = (state.catalog.delivery && state.catalog.delivery.areas) || [];
        if (els.area) {
            if ((state.catalog.delivery && state.catalog.delivery.type) === 'area' && areas.length) {
                els.area.hidden = false;
                if (!els.area.dataset.ready) {
                    els.area.innerHTML = '<option value="">Area</option>' + areas.map(function (area) {
                        return '<option value="' + area.id + '">' + escapeHtml(area.name) + ' · ' + money(area.charge) + '</option>';
                    }).join('');
                    els.area.dataset.ready = '1';
                }
                els.area.value = state.cart.address.selected_area_id || '';
            } else {
                els.area.hidden = true;
            }
        }
    }

    function renderLines() {
        if (!els.lines) return;
        if (!state.cart.lines.length) {
            els.lines.innerHTML = '<li class="munch-pos-empty">' + escapeHtml(CFG.labels.emptyCart) + '</li>';
            return;
        }
        els.lines.innerHTML = state.cart.lines.map(function (line, index) {
            var product = state.productMap[line.productId] || { name: 'Item' };
            var mods = modifierText(line);
            return '<li class="munch-pos-line">' +
                '<div class="munch-pos-line__name">' + escapeHtml(product.name) + '</div>' +
                '<div class="munch-pos-line__sub">' + money(lineSubtotal(line)) + '</div>' +
                (mods ? '<div class="munch-pos-line__meta">' + escapeHtml(mods) + '</div>' : '') +
                '<div class="munch-pos-qty">' +
                '<button type="button" data-qty="' + index + '" data-delta="-1">−</button>' +
                '<span>' + line.quantity + '</span>' +
                '<button type="button" data-qty="' + index + '" data-delta="1">+</button>' +
                '<button type="button" class="munch-pos-line__remove" data-remove="' + index + '" aria-label="Remove">×</button>' +
                '</div></li>';
        }).join('');
    }

    function modifierText(line) {
        var bits = [];
        (line.variations || []).forEach(function (group) {
            var labels = (group.values && group.values.label) || [];
            if (labels.length) bits.push((group.name ? group.name + ': ' : '') + labels.join(', '));
        });
        (line.addon_id || []).forEach(function (id) {
            var addon = state.addonMap[id];
            if (addon) {
                var qty = Number((line.addon_quantities && line.addon_quantities[id]) || 1);
                bits.push(addon.name + (qty > 1 ? ' ×' + qty : ''));
            }
        });
        return bits.join(' · ');
    }

    function queueStatusLabel(row) {
        if (row.status === 'syncing') return CFG.labels.syncing;
        if (row.status === 'failed') return CFG.labels.validationFailed;
        if (row.status === 'auth') return CFG.labels.sessionExpired;
        return CFG.labels.retrying;
    }

    function renderQueue() {
        if (!els.queueList) return;
        var rows = state.queueItems || [];
        if (!rows.length) {
            els.queueList.hidden = true;
            els.queueList.innerHTML = '';
            return;
        }
        els.queueList.hidden = false;
        els.queueList.innerHTML = rows.map(function (row) {
            var klass = 'munch-pos-queue__item';
            if (row.status === 'failed') klass += ' is-failed';
            if (row.status === 'auth') klass += ' is-auth';
            if (row.status === 'syncing') klass += ' is-syncing';
            var detail = row.lastError ? ' — ' + row.lastError : '';
            return '<li class="' + klass + '"><strong>' + escapeHtml(queueStatusLabel(row)) + '</strong>' +
                escapeHtml((row.payload && row.payload.order_type ? row.payload.order_type : 'order') + ' · ' + (row.createdAt || '').replace('T', ' ').slice(0, 19)) +
                escapeHtml(detail) + '</li>';
        }).join('');
    }

    function renderTotals() {
        if (!els.totals) return;
        var sub = cartSubtotal();
        var disc = extraDiscount(sub);
        var del = deliveryCharge(sub);
        var html = '<div><span>' + escapeHtml(CFG.labels.subtotal) + '</span><span>' + money(sub) + '</span></div>';
        if (state.cart.orderType === 'delivery') {
            html += '<div><span>' + escapeHtml(CFG.labels.deliveryCharge) + '</span><span>' + money(del) + '</span></div>';
        }
        if (disc > 0) {
            html += '<div><span>' + escapeHtml(CFG.labels.discount) + '</span><span>−' + money(disc) + '</span></div>';
        }
        html += '<div class="is-grand"><span>' + escapeHtml(CFG.labels.grandTotal) + '</span><span>' + money(grandTotal()) + '</span></div>';
        els.totals.innerHTML = html;
        if (els.topTotal) els.topTotal.textContent = money(grandTotal());
        if (els.discount) els.discount.value = state.cart.discount || '';
        if (els.discountType) els.discountType.value = state.cart.discountType;
        if (els.place) els.place.disabled = !state.cart.lines.length || state.placing;
        if (els.paidWrap) els.paidWrap.hidden = state.cart.payment === 'cash_on_delivery' || state.cart.payment === 'pay_after_eating';
        if (els.paid && document.activeElement !== els.paid) els.paid.value = state.cart.paid;
        if (els.change && state.cart.payment !== 'cash_on_delivery' && state.cart.payment !== 'pay_after_eating') {
            var paid = Number(state.cart.paid || 0);
            els.change.textContent = paid > 0 ? money(Math.max(0, paid - grandTotal())) : '';
        }
    }

    function renderPay() {
        if (!els.pay) return;
        var methods = paymentMethods();
        if (methods.indexOf(state.cart.payment) === -1) state.cart.payment = methods[0];
        els.pay.innerHTML = methods.map(function (method) {
            var label = method === 'cash' ? CFG.labels.cash : method === 'card' ? CFG.labels.card : method === 'pay_after_eating' ? CFG.labels.payAfter : CFG.labels.cod;
            return '<button type="button" class="' + (state.cart.payment === method ? 'is-active' : '') + '" data-pay="' + method + '">' + escapeHtml(label) + '</button>';
        }).join('');
    }

    function paymentMethods() {
        if (state.cart.orderType === 'delivery') return ['cash_on_delivery'];
        if (state.cart.orderType === 'dine_in') return ['cash', 'card', 'pay_after_eating'];
        return ['cash', 'card'];
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '');
    }

    function persistCart() {
        return idbPut('cart', state.cart, CART_KEY);
    }

    function toast(message) {
        if (!els.toast) return;
        els.toast.hidden = false;
        els.toast.textContent = message;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { els.toast.hidden = true; }, 2600);
    }

    function addSimple(product) {
        if (product.has_modifiers) {
            openModifiers(product);
            return;
        }
        var existing = state.cart.lines.find(function (line) {
            return line.productId === product.id && !line.has_modifiers;
        });
        if (existing) existing.quantity += 1;
        else state.cart.lines.push({ productId: product.id, quantity: 1, variations: [], addon_id: [], addon_quantities: {}, has_modifiers: false });
        persistCart();
        scheduleRender();
    }

    function openModifiers(product) {
        var card = document.getElementById('pos-modal-card');
        var modal = document.getElementById('pos-modal');
        if (!card || !modal) return;
        var html = '<h3>' + escapeHtml(product.name) + '</h3>';
        (product.variations || []).forEach(function (group, gi) {
            html += '<div><strong>' + escapeHtml(group.name) + '</strong> <small>' + escapeHtml(group.required === 'on' ? CFG.labels.required : CFG.labels.optional) + '</small>';
            (group.values || []).forEach(function (opt, oi) {
                var type = group.type === 'multi' ? 'checkbox' : 'radio';
                html += '<label class="munch-pos-choice"><span><input type="' + type + '" name="g' + gi + '" value="' + escapeAttr(opt.label) + '" data-g="' + gi + '"> ' + escapeHtml(opt.label) + '</span><span>' + money(opt.optionPrice) + '</span></label>';
            });
            html += '</div>';
        });
        if ((product.addon_ids || []).length) {
            html += '<div><strong>' + escapeHtml(CFG.labels.addons) + '</strong>';
            product.addon_ids.forEach(function (id) {
                var addon = state.addonMap[id];
                if (!addon) return;
                html += '<label class="munch-pos-choice"><span><input type="checkbox" data-addon="' + id + '"> ' + escapeHtml(addon.name) + '</span><span>' + money(addon.price) + '</span></label>';
            });
            html += '</div>';
        }
        html += '<div class="munch-pos-qty" style="margin:1rem 0"><button type="button" id="pos-mod-minus">−</button><span id="pos-mod-qty">1</span><button type="button" id="pos-mod-plus">+</button></div>';
        html += '<button type="button" class="munch-pos-place" id="pos-mod-add">' + escapeHtml(CFG.labels.add) + '</button>';
        html += '<button type="button" class="munch-pos-clear" id="pos-mod-close">Close</button>';
        card.innerHTML = html;
        modal.hidden = false;
        var qty = 1;
        card.querySelector('#pos-mod-minus').onclick = function () { qty = Math.max(1, qty - 1); card.querySelector('#pos-mod-qty').textContent = qty; };
        card.querySelector('#pos-mod-plus').onclick = function () { qty += 1; card.querySelector('#pos-mod-qty').textContent = qty; };
        card.querySelector('#pos-mod-close').onclick = function () { modal.hidden = true; };
        card.querySelector('#pos-mod-add').onclick = function () {
            var variations = [];
            var valid = true;
            (product.variations || []).forEach(function (group, gi) {
                var picked = [];
                card.querySelectorAll('input[data-g="' + gi + '"]:checked').forEach(function (input) { picked.push(input.value); });
                if (group.required === 'on' && !picked.length) valid = false;
                if (group.min && picked.length < group.min) valid = false;
                if (group.max && picked.length > group.max) valid = false;
                variations.push({
                    min: group.min,
                    max: group.max,
                    required: group.required,
                    name: group.name,
                    values: picked.length ? { label: picked } : undefined
                });
            });
            if (!valid) {
                toast(CFG.labels.required);
                return;
            }
            var addonIds = [];
            var addonQty = {};
            card.querySelectorAll('input[data-addon]:checked').forEach(function (input) {
                var id = Number(input.getAttribute('data-addon'));
                addonIds.push(id);
                addonQty[id] = 1;
            });
            state.cart.lines.push({
                productId: product.id,
                quantity: qty,
                variations: variations,
                addon_id: addonIds,
                addon_quantities: addonQty,
                has_modifiers: true
            });
            persistCart();
            modal.hidden = true;
            scheduleRender();
        };
    }

    function buildPayload(clientUuid, placedAt) {
        return {
            client_uuid: clientUuid,
            placed_at: placedAt,
            order_type: state.cart.orderType,
            type: state.cart.payment,
            paid_amount: state.cart.paid || grandTotal(),
            extra_discount: Number(state.cart.discount || 0),
            extra_discount_type: state.cart.discountType,
            table_id: state.cart.orderType === 'dine_in' ? state.cart.tableId : null,
            people_number: state.cart.orderType === 'dine_in' ? state.cart.people : null,
            address: state.cart.orderType === 'delivery' ? {
                contact_person_name: state.cart.address.contact_person_name || '',
                contact_person_number: state.cart.address.contact_person_number || '',
                address: state.cart.address.address || '',
                selected_area_id: state.cart.address.selected_area_id || null,
                area_id: state.cart.address.selected_area_id || null,
                distance: 0
            } : null,
            items: state.cart.lines.map(function (line) {
                return {
                    id: line.productId,
                    quantity: line.quantity,
                    variations: line.variations,
                    addon_id: line.addon_id,
                    addon_quantities: line.addon_quantities
                };
            })
        };
    }

    function validateCart() {
        if (!state.cart.lines.length) return CFG.labels.emptyCart;
        if (state.cart.orderType === 'dine_in' && !state.cart.tableId) return CFG.labels.table;
        if (state.cart.orderType === 'dine_in' && !state.cart.people) return CFG.labels.people;
        if (state.cart.orderType === 'delivery' && !String(state.cart.address.address || '').trim()) return CFG.labels.address;
        return null;
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return (meta && meta.getAttribute('content')) || CFG.csrf || '';
    }

    function setCsrf(token) {
        if (!token) return;
        CFG.csrf = token;
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', token);
    }

    function parsePosResponse(res, body) {
        body = body && typeof body === 'object' ? body : {};
        body._http = res.status;
        body._ok = res.ok;
        if (res.redirected && String(res.url).indexOf('login') !== -1) {
            body._http = 401;
            body.code = 'unauthenticated';
        }
        return body;
    }

    function refreshHeartbeat() {
        if (!CFG.urls.heartbeat) return Promise.resolve(null);
        return fetch(CFG.urls.heartbeat, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Munch-POS': '1' }
        }).then(function (res) {
            if (res.status === 401 || (res.redirected && String(res.url).indexOf('login') !== -1)) {
                state.authRequired = true;
                scheduleRender();
                return false;
            }
            return res.json().then(function (json) {
                if (json && json.csrf) setCsrf(json.csrf);
                state.authRequired = false;
                if (json && json.catalog_version && json.catalog_version !== (state.catalog.version || '')) {
                    return refreshCatalog(true).then(function () { return true; });
                }
                return true;
            });
        }).catch(function () { return null; });
    }

    function postOrder(payload, retried) {
        return fetch(CFG.urls.order, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Munch-POS': '1'
            },
            body: JSON.stringify(payload)
        }).then(function (res) {
            if (res.status === 419 && !retried) {
                return refreshHeartbeat().then(function (ok) {
                    if (ok === false) return { success: 0, _http: 401, _ok: false, code: 'unauthenticated' };
                    if (!ok) return { success: 0, _http: 419, _ok: false };
                    return postOrder(payload, true);
                });
            }
            return res.json().catch(function () { return {}; }).then(function (body) {
                return parsePosResponse(res, body);
            });
        });
    }

    function clearCart() {
        state.cart.lines = [];
        state.cart.discount = 0;
        state.cart.paid = '';
        persistCart();
        scheduleRender();
    }

    function refreshQueueCount() {
        return idbGetAll('queue').then(function (rows) {
            state.queueItems = (rows || []).filter(function (row) { return row.status !== 'synced'; });
            state.queueCount = state.queueItems.length;
            state.authRequired = state.queueItems.some(function (row) { return row.status === 'auth'; }) || state.authRequired;
            scheduleRender();
        });
    }

    function enqueue(payload) {
        var row = {
            id: payload.client_uuid,
            createdAt: payload.placed_at,
            payload: payload,
            status: 'queued',
            attempts: 0,
            lastError: null
        };
        return idbPut('queue', row).then(refreshQueueCount);
    }

    function syncQueue() {
        if (!navigator.onLine) return Promise.resolve();
        state.syncLabel = CFG.labels.syncing;
        state.syncKind = 'sync';
        scheduleRender();
        return idbGetAll('queue').then(function (rows) {
            var pending = rows.filter(function (row) {
                return row.status === 'queued' || row.status === 'auth' || row.status === 'syncing';
            });
            var chain = Promise.resolve();
            pending.forEach(function (row) {
                if (row.status === 'syncing') row.status = 'queued';
                chain = chain.then(function () { return syncOne(row); });
            });
            return chain;
        }).then(function () {
            if (state.queueCount === 0) {
                state.syncLabel = CFG.labels.synced;
                state.syncKind = 'ok';
            }
            scheduleRender();
            setTimeout(function () {
                if (state.syncKind === 'ok') {
                    state.syncLabel = '';
                    scheduleRender();
                }
            }, 2500);
        }).catch(function () {
            state.syncLabel = CFG.labels.syncFailed;
            state.syncKind = 'err';
            scheduleRender();
        });
    }

    function syncOne(row) {
        if (row.status === 'syncing') return Promise.resolve();
        row.status = 'syncing';
        row.attempts = (row.attempts || 0) + 1;
        return idbPut('queue', row).then(function () {
            return postOrder(row.payload);
        }).then(function (body) {
            if (body && (body.success === 1 || body.duplicate)) {
                return idbDelete('queue', row.id).then(refreshQueueCount);
            }
            if (body && (body._http === 401 || body._http === 403 || body.code === 'unauthenticated')) {
                row.status = 'auth';
                row.lastError = CFG.labels.sessionExpired;
                state.authRequired = true;
                state.syncLabel = CFG.labels.sessionExpired;
                state.syncKind = 'err';
                return idbPut('queue', row).then(refreshQueueCount);
            }
            if (body && body._http === 422) {
                row.status = 'failed';
                row.lastError = body.message || CFG.labels.validationFailed;
                state.syncLabel = CFG.labels.syncFailed;
                state.syncKind = 'err';
                return idbPut('queue', row).then(refreshQueueCount);
            }
            row.status = 'queued';
            row.lastError = (body && body.message) || CFG.labels.retrying;
            return idbPut('queue', row).then(refreshQueueCount);
        }).catch(function () {
            row.status = 'queued';
            row.lastError = CFG.labels.retrying;
            return idbPut('queue', row).then(refreshQueueCount);
        });
    }

    function placeOrder() {
        var error = validateCart();
        if (error) {
            toast(error);
            return;
        }
        var payload = buildPayload(uuid(), new Date().toISOString());
        if (!navigator.onLine) {
            enqueue(payload).then(function () {
                clearCart();
                toast(CFG.labels.queuedSaved);
                requestBackgroundSync();
            });
            return;
        }
        state.placing = true;
        scheduleRender();
        postOrder(payload).then(function (body) {
            if (body && body.success === 1) {
                clearCart();
                toast(CFG.labels.placed);
                return;
            }
            if (body && (body._http === 401 || body._http === 403 || body.code === 'unauthenticated')) {
                state.authRequired = true;
                return enqueue(payload).then(function () {
                    clearCart();
                    toast(CFG.labels.sessionExpired);
                });
            }
            if (body && body._http === 422) {
                toast((body && body.message) || CFG.labels.syncFailed);
                return;
            }
            return enqueue(payload).then(function () {
                clearCart();
                toast(CFG.labels.queuedSaved);
            });
        }).catch(function () {
            return enqueue(payload).then(function () {
                clearCart();
                toast(CFG.labels.queuedSaved);
            });
        }).then(function () {
            state.placing = false;
            scheduleRender();
        });
    }

    function refreshCatalog(force) {
        if (!navigator.onLine || !CFG.urls.catalog) return Promise.resolve();
        if (!force) {
            return refreshHeartbeat();
        }
        return fetch(CFG.urls.catalog, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Munch-POS': '1' } })
            .then(function (res) {
                if (res.status === 401) {
                    state.authRequired = true;
                    scheduleRender();
                    return null;
                }
                return res.json();
            })
            .then(function (json) {
                if (json && json.data) {
                    indexCatalog(json.data);
                    return idbPut('catalog', json.data, 'latest').then(scheduleRender);
                }
            })
            .catch(function () { /* keep cached catalog */ });
    }

    function requestBackgroundSync() {
        if (!('serviceWorker' in navigator) || !('SyncManager' in window)) return;
        navigator.serviceWorker.ready.then(function (reg) {
            return reg.sync.register('munch-pos-sync');
        }).catch(function () {});
    }

    function bind() {
        els.conn = document.getElementById('pos-conn-badge');
        els.queue = document.getElementById('pos-queue-badge');
        els.sync = document.getElementById('pos-sync-badge');
        els.tabs = document.getElementById('pos-tabs');
        els.grid = document.getElementById('pos-grid');
        els.empty = document.getElementById('pos-empty');
        els.types = document.getElementById('pos-types');
        els.dineIn = document.getElementById('pos-dine-in');
        els.delivery = document.getElementById('pos-delivery');
        els.table = document.getElementById('pos-table');
        els.people = document.getElementById('pos-people');
        els.area = document.getElementById('pos-del-area');
        els.lines = document.getElementById('pos-lines');
        els.totals = document.getElementById('pos-totals');
        els.pay = document.getElementById('pos-pay');
        els.discount = document.getElementById('pos-discount');
        els.discountType = document.getElementById('pos-discount-type');
        els.paid = document.getElementById('pos-paid');
        els.paidWrap = document.getElementById('pos-paid-wrap');
        els.change = document.getElementById('pos-change');
        els.place = document.getElementById('pos-place');
        els.toast = document.getElementById('pos-toast');
        els.topTotal = document.getElementById('pos-top-total');
        els.queueList = document.getElementById('pos-queue-list');
        els.auth = document.getElementById('pos-auth');
        els.authLink = document.getElementById('pos-auth-link');

        document.getElementById('pos-search').addEventListener('input', function (ev) {
            state.search = ev.target.value;
            scheduleRender();
        });
        els.tabs.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-cat]');
            if (!btn) return;
            state.categoryId = Number(btn.getAttribute('data-cat'));
            scheduleRender();
        });
        els.grid.addEventListener('click', function (ev) {
            var add = ev.target.closest('[data-add]');
            if (!add) return;
            var product = state.productMap[Number(add.getAttribute('data-add'))];
            if (product) addSimple(product);
        });
        var gridTimer = 0;
        els.grid.addEventListener('scroll', function () {
            if ((state.catalog.products || []).length <= 80) return;
            clearTimeout(gridTimer);
            gridTimer = setTimeout(renderGrid, 16);
        }, { passive: true });
        els.types.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-type]');
            if (!btn) return;
            state.cart.orderType = btn.getAttribute('data-type');
            persistCart();
            scheduleRender();
        });
        els.lines.addEventListener('click', function (ev) {
            var qtyBtn = ev.target.closest('[data-qty]');
            if (qtyBtn) {
                var idx = Number(qtyBtn.getAttribute('data-qty'));
                var delta = Number(qtyBtn.getAttribute('data-delta'));
                var line = state.cart.lines[idx];
                if (!line) return;
                line.quantity = Math.max(1, line.quantity + delta);
                persistCart();
                scheduleRender();
                return;
            }
            var remove = ev.target.closest('[data-remove]');
            if (remove) {
                state.cart.lines.splice(Number(remove.getAttribute('data-remove')), 1);
                persistCart();
                scheduleRender();
            }
        });
        els.pay.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-pay]');
            if (!btn) return;
            state.cart.payment = btn.getAttribute('data-pay');
            persistCart();
            scheduleRender();
        });
        els.table.addEventListener('change', function () { state.cart.tableId = els.table.value; persistCart(); });
        els.people.addEventListener('input', function () { state.cart.people = els.people.value; persistCart(); });
        ['pos-del-name', 'pos-del-phone', 'pos-del-address'].forEach(function (id) {
            var node = document.getElementById(id);
            if (!node) return;
            node.addEventListener('input', function () {
                state.cart.address.contact_person_name = document.getElementById('pos-del-name').value;
                state.cart.address.contact_person_number = document.getElementById('pos-del-phone').value;
                state.cart.address.address = document.getElementById('pos-del-address').value;
                persistCart();
            });
        });
        if (els.area) {
            els.area.addEventListener('change', function () {
                state.cart.address.selected_area_id = els.area.value;
                persistCart();
                scheduleRender();
            });
        }
        els.discount.addEventListener('input', function () {
            state.cart.discount = Number(els.discount.value || 0);
            persistCart();
            scheduleRender();
        });
        els.discountType.addEventListener('change', function () {
            state.cart.discountType = els.discountType.value;
            persistCart();
            scheduleRender();
        });
        els.paid.addEventListener('input', function () {
            state.cart.paid = els.paid.value;
            persistCart();
            scheduleRender();
        });
        els.place.addEventListener('click', placeOrder);
        document.getElementById('pos-clear').addEventListener('click', function () {
            clearCart();
        });
        document.getElementById('pos-modal').addEventListener('click', function (ev) {
            if (ev.target.id === 'pos-modal') ev.target.hidden = true;
        });
        window.addEventListener('online', function () {
            state.online = true;
            scheduleRender();
            refreshHeartbeat().then(function () { syncQueue(); });
        });
        window.addEventListener('offline', function () {
            state.online = false;
            scheduleRender();
        });
        setInterval(function () {
            var now = navigator.onLine;
            if (now !== state.online) {
                state.online = now;
                scheduleRender();
                if (now) refreshHeartbeat().then(function () { syncQueue(); });
            } else if (now && state.queueCount) {
                syncQueue();
            }
        }, 10000);
        setInterval(function () {
            if (navigator.onLine) refreshHeartbeat();
        }, 30000);
        if ('serviceWorker' in navigator && CFG.urls.sw) {
            navigator.serviceWorker.register(CFG.urls.sw).catch(function () {});
            navigator.serviceWorker.addEventListener('message', function (ev) {
                if (ev.data && ev.data.type === 'munch-pos-sync') syncQueue();
            });
        }
    }

    function boot() {
        bind();
        indexCatalog(state.catalog);
        Promise.all([
            idbGet('catalog', 'latest'),
            idbGet('cart', CART_KEY),
            idbPut('meta', { csrf: csrfToken(), orderUrl: CFG.urls.order }, 'session')
        ]).then(function (results) {
            if (results[0] && results[0].products && (!CFG.catalog || !CFG.catalog.version || results[0].version === CFG.catalog.version)) {
                indexCatalog(results[0]);
            } else if (CFG.catalog) {
                idbPut('catalog', CFG.catalog, 'latest');
            }
            if (results[1] && Array.isArray(results[1].lines)) state.cart = Object.assign(state.cart, results[1]);
            return refreshQueueCount();
        }).then(function () {
            scheduleRender();
            if (navigator.onLine) refreshHeartbeat().then(function () { syncQueue(); });
        }).catch(function () {
            scheduleRender();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
