(function () {
    'use strict';

    var CFG = window.MUNCH_POS || {};
    var Delivery = window.MunchPosDelivery;
    var DB_NAME = 'munch_pos_v1';
    var DB_VERSION = 1;
    var CART_KEY = 'current';
    var state = {
        catalog: CFG.catalog || { products: [], categories: [], delivery: {} },
        cart: { lines: [], orderType: 'take_away', discount: 0, discountType: 'amount', payment: 'cash', paid: '', deliveryFee: 0, address: {} },
        categoryId: 0,
        search: '',
        searchDraft: '',
        online: navigator.onLine,
        queueCount: 0,
        queueItems: [],
        authRequired: false,
        syncLabel: '',
        syncKind: '',
        placing: false,
        orderSubmitting: false,
        productMap: {}
    };
    var SubmitGuard = window.MunchPosSubmitGuard || null;
    var backgroundSyncOnce = SubmitGuard && SubmitGuard.createSyncOnce
        ? SubmitGuard.createSyncOnce()
        : null;
    var syncInFlight = false;
    var els = {};
    var renderScheduled = false;
    var toastTimer = 0;
    var searchTimer = 0;
    var categoryScroll = {};
    var lastGridKey = '';
    var ordersUi = {
        open: false,
        search: '',
        filter: 'all',
        page: 1,
        lastPage: 1,
        total: 0,
        orders: [],
        expandedId: 0,
        timer: 0,
        searchTimer: 0,
        loading: false
    };
    var successJob = null;
    var printBusy = false;
    var cancelUi = {
        open: false,
        submitting: false,
        order: null,
        clientUuid: '',
        opener: null
    };
    var cancelQueuedIds = {};

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
        CFG.catalog = catalog;
        state.productMap = {};
        (catalog.products || []).forEach(function (p) { state.productMap[p.id] = p; });
        lastGridKey = '';
    }

    function productNeedsVariation(product) {
        return !!(product && (product.variations || []).length);
    }

    function posAddons(product) {
        if (!product || !product.allow_addon_on_pos) return [];
        return product.addons || [];
    }

    function productNeedsModifiers(product) {
        return productNeedsVariation(product) || posAddons(product).length > 0;
    }

    function modifierBadge(product) {
        if (productNeedsVariation(product)) return variationBadge(product);
        if (posAddons(product).length) return (CFG.labels && CFG.labels.addons) || 'Addons';
        return '';
    }

    function variationBadge(product) {
        var groups = (product && product.variations) || [];
        if (!groups.length) return '';
        if (groups.length === 1) {
            var name = String(groups[0].name || '').trim();
            if (name) return ((CFG.labels && CFG.labels.choose) || 'Choose') + ' ' + name;
            return (CFG.labels && CFG.labels.chooseFlavour) || 'Choose Flavour';
        }
        return groups.length + ' ' + ((CFG.labels && CFG.labels.variations) || 'Variations');
    }

    function variationSelectionKey(variations) {
        return (variations || []).map(function (group) {
            var raw = (group.values && group.values.label) || [];
            var labels = raw.slice().map(function (label) { return String(label); }).sort();
            return String(group.name || '') + ':' + labels.join(',');
        }).join('|');
    }

    function nextAddonQty(current, delta) {
        var qty = Math.max(0, Number(current) || 0) + Number(delta || 0);
        return qty < 0 ? 0 : Math.floor(qty);
    }

    function selectedAddonsFromQuantities(quantities) {
        var addonId = [];
        var addonQuantities = {};
        Object.keys(quantities || {}).forEach(function (key) {
            var id = Number(key);
            var qty = nextAddonQty(quantities[key], 0);
            if (!(id > 0) || qty < 1) return;
            addonId.push(id);
            addonQuantities[id] = qty;
        });
        addonId.sort(function (a, b) { return a - b; });
        return { addon_id: addonId, addon_quantities: addonQuantities };
    }

    function setAddonRowQty(row, qty) {
        qty = nextAddonQty(qty, 0);
        row.setAttribute('data-addon-qty', String(qty));
        var count = row.querySelector('[data-addon-count]');
        if (count) count.textContent = String(qty);
        if (qty > 0) row.classList.add('munch-pos-addon--on');
        else row.classList.remove('munch-pos-addon--on');
    }

    function collectSelectedAddons(root) {
        var quantities = {};
        if (!root) return selectedAddonsFromQuantities(quantities);
        root.querySelectorAll('[data-addon-id]').forEach(function (row) {
            var id = Number(row.getAttribute('data-addon-id'));
            if (!(id > 0)) return;
            quantities[id] = nextAddonQty(row.getAttribute('data-addon-qty'), 0);
        });
        return selectedAddonsFromQuantities(quantities);
    }

    function addonSelectionKey(addonId, addonQuantities) {
        return (addonId || []).map(function (id) { return Number(id); })
            .filter(function (id) { return id > 0; })
            .sort(function (a, b) { return a - b; })
            .map(function (id) {
                var qty = Number((addonQuantities || {})[id] || (addonQuantities || {})[String(id)] || 1);
                return id + 'x' + (qty > 0 ? qty : 1);
            })
            .join(',');
    }

    function findMatchingVariationLine(productId, variations, addonId, addonQuantities) {
        var key = variationSelectionKey(variations);
        var addonKey = addonSelectionKey(addonId, addonQuantities);
        var id = Number(productId);
        var i;
        for (i = 0; i < (state.cart.lines || []).length; i++) {
            var line = state.cart.lines[i];
            if (Number(line.productId) === id
                && variationSelectionKey(line.variations) === key
                && addonSelectionKey(line.addon_id, line.addon_quantities) === addonKey) {
                return i;
            }
        }
        return -1;
    }

    function addSelectedVariations(product, variations, qty, addonId, addonQuantities) {
        var amount = Number(qty || 1);
        if (!product || !(amount > 0)) return;
        addonId = addonId || [];
        addonQuantities = addonQuantities || {};
        var matchIdx = findMatchingVariationLine(product.id, variations, addonId, addonQuantities);
        if (matchIdx >= 0) {
            state.cart.lines[matchIdx].quantity += amount;
            return;
        }
        state.cart.lines.push({
            productId: product.id,
            quantity: amount,
            variations: variations,
            addon_id: addonId,
            addon_quantities: addonQuantities,
            has_modifiers: true
        });
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

    function pricingChannel(orderType) {
        if (orderType === 'uber') return 'uber';
        if (orderType === 'glovo') return 'glovo';
        if (orderType === 'bolt_food') return 'bolt_food';
        return 'pos';
    }

    function productChannelAvailable(product) {
        if (!product) return false;
        var ch = pricingChannel(state.cart.orderType);
        var flags = product.channel_available || {};
        if (flags[ch] === undefined) return true;
        return !!flags[ch];
    }

    function resolvedProductPrice(product) {
        if (!product) return 0;
        var ch = pricingChannel(state.cart.orderType);
        var prices = product.channel_prices || {};
        if (prices[ch] != null && prices[ch] !== '') return Number(prices[ch]);
        return Number(product.price || 0);
    }

    function productDiscountAmount(product) {
        if (!product) return 0;
        var data = product.discount_data || {};
        var price = resolvedProductPrice(product);
        if (data.discount_type === 'percent') return price * Number(data.discount || 0) / 100;
        if (data.discount != null && data.discount !== '') return Number(data.discount);
        return Number(product.discount || 0);
    }

    function lineUnit(line) {
        var product = state.productMap[line.productId];
        if (!product) return 0;
        var unit = resolvedProductPrice(product) - productDiscountAmount(product) + variationPrice(product, line.variations || []);
        return unit;
    }

    function lineAddonTotal(line) {
        var product = state.productMap[line.productId];
        var selected = line.addon_id || [];
        var qtys = line.addon_quantities || {};
        var extra = 0;
        posAddons(product).forEach(function (addon) {
            if (selected.indexOf(addon.id) === -1 && selected.indexOf(String(addon.id)) === -1) return;
            extra += Number(addon.price || 0) * Number(qtys[addon.id] || qtys[String(addon.id)] || 1);
        });
        return extra;
    }

    function lineSubtotal(line) {
        return lineUnit(line) * Number(line.quantity || 1) + lineAddonTotal(line);
    }

    function cartSubtotal() {
        return state.cart.lines.reduce(function (sum, line) { return sum + lineSubtotal(line); }, 0);
    }

    function allowsDiscount() {
        return state.cart.orderType === 'delivery' || state.cart.orderType === 'take_away' || state.cart.orderType === 'dine_in';
    }

    function extraDiscount(subtotal) {
        if (!allowsDiscount()) return 0;
        var value = Number(state.cart.discount || 0);
        if (value <= 0) return 0;
        if (state.cart.discountType === 'percent') return subtotal * value / 100;
        return Math.min(value, subtotal);
    }

    function deliveryCharge() {
        if (state.cart.orderType !== 'delivery') return 0;
        var fee = Number(state.cart.deliveryFee || 0);
        if (!isFinite(fee) || fee < 0) return 0;
        return fee;
    }

    function grandTotal() {
        var sub = cartSubtotal();
        return Math.max(0, sub + deliveryCharge() - extraDiscount(sub));
    }

    function productInCategory(product, categoryId) {
        if (!categoryId) return true;
        var ids = product.category_ids || [];
        var i;
        for (i = 0; i < ids.length; i++) {
            if (Number(ids[i]) === categoryId) return true;
        }
        return false;
    }

    function filteredProducts() {
        var q = (state.search || '').trim().toLowerCase();
        return (state.catalog.products || []).filter(function (p) {
            if (!productChannelAvailable(p)) return false;
            if (!productInCategory(p, state.categoryId)) return false;
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

    function catalogGridKey() {
        return [
            state.categoryId,
            state.search,
            state.cart.orderType,
            (state.catalog && state.catalog.version) || '',
            (state.catalog.products || []).length
        ].join('|');
    }

    function renderAll() {
        renderStatus();
        renderTabs();
        var listLen = (state.catalog.products || []).length;
        var cardCount = els.grid ? els.grid.querySelectorAll('.munch-pos-card').length : 0;
        var gridKey = catalogGridKey();
        if (gridKey !== lastGridKey || (listLen > 0 && cardCount === 0)) {
            lastGridKey = gridKey;
            renderGrid();
        }
        renderTypes();
        renderExtras();
        renderLines();
        renderQueue();
        renderTotals();
        renderPay();
    }

    function productQty(productId) {
        var total = 0;
        var id = Number(productId);
        (state.cart.lines || []).forEach(function (line) {
            if (Number(line.productId) === id) total += Number(line.quantity || 0);
        });
        return total;
    }

    function lastLineIndex(productId) {
        var id = Number(productId);
        var i;
        for (i = (state.cart.lines || []).length - 1; i >= 0; i--) {
            if (Number(state.cart.lines[i].productId) === id) return i;
        }
        return -1;
    }

    function cardQtyHtml(productId, qty) {
        if (qty > 0) {
            return '<div class="munch-pos-card__qty">' +
                '<button type="button" class="munch-pos-card__step" data-card-delta="-1" data-product="' + productId + '" aria-label="−">−</button>' +
                '<span class="munch-pos-card__count">' + qty + '</span>' +
                '<button type="button" class="munch-pos-card__step" data-card-delta="1" data-product="' + productId + '" aria-label="+">+</button>' +
                '</div>';
        }
        return '<button type="button" class="munch-pos-card__plus" data-card-delta="1" data-product="' + productId + '" aria-label="+">+</button>';
    }

    function updateProductCard(productId) {
        if (!els.grid) return;
        var card = els.grid.querySelector('.munch-pos-card[data-id="' + productId + '"]');
        if (!card) return;
        var mount = card.querySelector('.munch-pos-card__actions');
        if (!mount) return;
        var qty = productQty(productId);
        if (Number(mount.getAttribute('data-qty') || 0) === qty) return;
        mount.setAttribute('data-qty', String(qty));
        mount.innerHTML = cardQtyHtml(productId, qty);
    }

    function refreshCartUi(productId) {
        renderLines();
        renderTotals();
        if (productId) updateProductCard(productId);
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
        var key = (state.catalog.categories || []).map(function (cat) { return cat.id; }).join(',');
        if (els.tabs.dataset.key !== key) {
            els.tabs.innerHTML = html;
            els.tabs.dataset.key = key;
        }
        els.tabs.querySelectorAll('[data-cat]').forEach(function (btn) {
            btn.classList.toggle('is-active', Number(btn.getAttribute('data-cat')) === state.categoryId);
        });
        updateTabArrows();
    }

    function updateTabArrows() {
        var tabs = els.tabs;
        if (!tabs) return;
        var max = tabs.scrollWidth - tabs.clientWidth;
        var overflow = max > 8;
        if (els.tabsWrap) els.tabsWrap.classList.toggle('is-overflow', overflow);
        if (els.tabsPrev) els.tabsPrev.disabled = !overflow || tabs.scrollLeft <= 2;
        if (els.tabsNext) els.tabsNext.disabled = !overflow || tabs.scrollLeft >= max - 2;
    }

    function scrollTabs(direction) {
        if (!els.tabs) return;
        var distance = Math.max(180, Math.floor(els.tabs.clientWidth * 0.7));
        els.tabs.scrollBy({ left: direction * distance, behavior: 'smooth' });
    }

    function measuredGridColumns(containerWidth, cardWidth, gap) {
        var width = Number(containerWidth || 0);
        var card = Number(cardWidth || 0);
        var gutter = Number(gap || 0);
        if (!(width > 80) || !(card > 80)) return 0;
        return Math.max(1, Math.round((width + gutter) / (card + gutter)));
    }

    function renderGrid() {
        if (!els.grid) return;
        var list = filteredProducts();
        if (els.empty) els.empty.hidden = list.length > 0;
        var savedTop = els.grid.scrollTop;
        els.grid.innerHTML = list.map(productCard).join('');
        els.grid.scrollTop = savedTop;
    }

    function productCard(product) {
        var img = product.image || state.catalog.placeholder_image || '';
        var qty = productQty(product.id);
        var hasOptions = productNeedsModifiers(product);
        return '<article class="munch-pos-card" data-id="' + product.id + '">' +
            '<img src="' + escapeAttr(img) + '" alt="" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=\'' + escapeAttr(state.catalog.placeholder_image || '') + '\'">' +
            '<div class="munch-pos-card__body">' +
            '<div class="munch-pos-card__name">' + escapeHtml(product.name) + '</div>' +
            (hasOptions ? '<div class="munch-pos-card__opt">' + escapeHtml(modifierBadge(product)) + '</div>' : '') +
            '<div class="munch-pos-card__price">' + money(resolvedProductPrice(product) - productDiscountAmount(product)) + '</div>' +
            '</div>' +
            '<div class="munch-pos-card__actions" data-qty="' + qty + '">' + cardQtyHtml(product.id, qty) + '</div>' +
            '</article>';
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
        if (els.delivery) els.delivery.hidden = true;
        if (els.discountWrap) els.discountWrap.hidden = !allowsDiscount();
        if (!allowsDiscount() && Number(state.cart.discount || 0) !== 0) {
            state.cart.discount = 0;
            persistCart();
        }
        if (els.feeCurrency) els.feeCurrency.textContent = state.catalog.currency_symbol || 'Ksh';
        if (els.fee) {
            var decimals = Number(state.catalog.decimal || 0);
            els.fee.step = decimals > 0 ? String(1 / Math.pow(10, decimals)) : '1';
            if (document.activeElement !== els.fee) {
                els.fee.value = state.cart.orderType === 'delivery' ? (state.cart.deliveryFee || 0) : 0;
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
            var qty = Number(line.quantity || 1);
            return '<li class="munch-pos-line' + (mods ? ' munch-pos-line--mods' : '') + '">' +
                '<div class="munch-pos-line__main">' +
                '<div class="munch-pos-line__name">' + escapeHtml(product.name) + '</div>' +
                (mods ? '<div class="munch-pos-line__meta">' + escapeHtml(mods) + '</div>' : '') +
                '<div class="munch-pos-line__price">' + money(lineUnit(line)) + ' × ' + qty + '</div>' +
                '</div>' +
                '<div class="munch-pos-line__sub">' + money(lineSubtotal(line)) + '</div>' +
                '<div class="munch-pos-qty">' +
                '<button type="button" data-qty="' + index + '" data-delta="-1" aria-label="−">−</button>' +
                '<span>' + qty + '</span>' +
                '<button type="button" data-qty="' + index + '" data-delta="1" aria-label="+">+</button>' +
                '</div>' +
                '<button type="button" class="munch-pos-line__remove" data-remove="' + index + '" aria-label="Remove">×</button>' +
                '</li>';
        }).join('');
    }

    function modifierText(line) {
        var bits = [];
        (line.variations || []).forEach(function (group) {
            var labels = (group.values && group.values.label) || [];
            if (labels.length) bits.push((group.name ? group.name + ': ' : '') + labels.join(', '));
        });
        addonLabels(line).forEach(function (label) { bits.push(label); });
        return bits.join(' · ');
    }

    function addonLabels(line) {
        var product = state.productMap[line.productId];
        var selected = line.addon_id || [];
        var qtys = line.addon_quantities || {};
        var out = [];
        posAddons(product).forEach(function (addon) {
            if (selected.indexOf(addon.id) === -1 && selected.indexOf(String(addon.id)) === -1) return;
            var qty = Number(qtys[addon.id] || qtys[String(addon.id)] || 1);
            out.push(qty > 1 ? addon.name + ' × ' + qty : addon.name);
        });
        return out;
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
        var del = deliveryCharge();
        var html = '<div class="munch-pos-totals__sub"><span>' + escapeHtml(CFG.labels.subtotal) + '</span><span>' + money(sub) + '</span></div>';
        if (state.cart.orderType === 'delivery') {
            html += '<div class="munch-pos-totals__fee"><span>' + escapeHtml(CFG.labels.deliveryFee || CFG.labels.deliveryCharge) + '</span><span>' + money(del) + '</span></div>';
        }
        if (disc > 0) {
            html += '<div class="munch-pos-totals__disc"><span>' + escapeHtml(CFG.labels.discount) + '</span><span>−' + money(disc) + '</span></div>';
        }
        html += '<div class="is-grand"><span>' + escapeHtml(CFG.labels.grandTotal) + '</span><span>' + money(grandTotal()) + '</span></div>';
        els.totals.innerHTML = html;
        if (els.topTotal) els.topTotal.textContent = money(grandTotal());
        if (els.discount) els.discount.value = state.cart.discount || '';
        if (els.discountType) els.discountType.value = state.cart.discountType;
        if (els.place) els.place.disabled = !state.cart.lines.length || state.placing || state.orderSubmitting || !!successJob;
        if (els.clear) els.clear.disabled = !!successJob;
    }

    function isMarketplaceOrderType(type) {
        type = type || state.cart.orderType;
        return type === 'glovo' || type === 'uber' || type === 'bolt_food';
    }

    function renderPay() {
        if (!els.pay) return;
        var methods = paymentMethods();
        if (methods.indexOf(state.cart.payment) === -1) {
            state.cart.payment = remapPaymentForOrderType(state.cart.payment, methods);
        }
        if (isMarketplaceOrderType()) {
            els.pay.hidden = true;
            els.pay.innerHTML = '';
            return;
        }
        els.pay.hidden = false;
        els.pay.innerHTML = methods.map(function (method) {
            var label = paymentLabel(method);
            return '<button type="button" class="' + (state.cart.payment === method ? 'is-active' : '') + '" data-pay="' + method + '">' + escapeHtml(label) + '</button>';
        }).join('');
    }

    function posMpesaEnabled() {
        var flag = state.catalog && state.catalog.pos_mpesa_enabled;
        if (flag === undefined && CFG.catalog) flag = CFG.catalog.pos_mpesa_enabled;
        return flag !== false && flag !== 0 && flag !== '0';
    }

    function branchMpesaTill() {
        var till = state.catalog && state.catalog.mpesa_till;
        if ((till === undefined || till === null || till === '') && CFG.catalog) till = CFG.catalog.mpesa_till;
        return String(till || '').trim();
    }

    function paymentMethods() {
        if (state.cart.orderType === 'delivery') {
            return posMpesaEnabled() ? ['cash', 'paystack', 'mpesa'] : ['cash', 'paystack'];
        }
        if (state.cart.orderType === 'take_away' || state.cart.orderType === 'dine_in') {
            return posMpesaEnabled() ? ['cash', 'card', 'mpesa'] : ['cash', 'card'];
        }
        if (state.cart.orderType === 'glovo') return ['glovo'];
        if (state.cart.orderType === 'uber') return ['uber'];
        if (state.cart.orderType === 'bolt_food') return ['bolt_food'];
        return ['cash', 'card'];
    }

    function remapPaymentForOrderType(payment, methods) {
        if (methods.indexOf(payment) !== -1) return payment;
        if (payment === 'paystack' && methods.indexOf('card') !== -1) return 'card';
        if (payment === 'card' && methods.indexOf('paystack') !== -1) return 'paystack';
        return methods[0] || '';
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
        var stored = Delivery ? Delivery.persistableCart(state.cart) : state.cart;
        return idbPut('cart', stored, CART_KEY);
    }

    function toast(message) {
        if (!els.toast) return;
        els.toast.hidden = false;
        els.toast.textContent = message;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { els.toast.hidden = true; }, 2600);
    }

    function addSimple(product) {
        if (productNeedsModifiers(product)) {
            openModifiers(product);
            return;
        }
        var existing = state.cart.lines.find(function (line) {
            return line.productId === product.id && !line.has_modifiers;
        });
        if (existing) existing.quantity += 1;
        else state.cart.lines.push({ productId: product.id, quantity: 1, variations: [], addon_id: [], addon_quantities: {}, has_modifiers: false });
        persistCart();
        refreshCartUi(product.id);
    }

    function adjustProductQty(productId, delta) {
        var product = state.productMap[productId];
        if (!product || !delta) return;
        if (delta > 0) {
            if (productNeedsModifiers(product)) {
                openModifiers(product);
                return;
            }
            addSimple(product);
            return;
        }
        var idx = lastLineIndex(productId);
        if (idx < 0) return;
        var line = state.cart.lines[idx];
        line.quantity += delta;
        if (line.quantity <= 0) state.cart.lines.splice(idx, 1);
        persistCart();
        refreshCartUi(productId);
    }

    function openModifiers(product) {
        if (!productNeedsModifiers(product)) {
            addSimple(product);
            return;
        }
        var card = document.getElementById('pos-modal-card');
        var modal = document.getElementById('pos-modal');
        if (!card || !modal) return;
        var html = '<h3>' + escapeHtml(product.name) + '</h3><div class="munch-pos-dialog__body">';
        (product.variations || []).forEach(function (group, gi) {
            html += '<div><strong>' + escapeHtml(group.name) + '</strong> <small>' + escapeHtml(group.required === 'on' ? CFG.labels.required : CFG.labels.optional) + '</small>';
            (group.values || []).forEach(function (opt) {
                var type = group.type === 'multi' ? 'checkbox' : 'radio';
                html += '<label class="munch-pos-choice"><span><input type="' + type + '" name="g' + gi + '" value="' + escapeAttr(opt.label) + '" data-g="' + gi + '"> ' + escapeHtml(opt.label) + '</span><span>' + money(opt.optionPrice) + '</span></label>';
            });
            html += '</div>';
        });
        posAddons(product).forEach(function (addon, ai) {
            if (ai === 0) {
                html += '<div><strong>' + escapeHtml((CFG.labels && CFG.labels.addons) || 'Addons') + '</strong> <small>' + escapeHtml(CFG.labels.optional || 'optional') + '</small>';
            }
            html += '<div class="munch-pos-addon" data-addon-id="' + escapeAttr(addon.id) + '" data-addon-qty="0">';
            html += '<div class="munch-pos-addon__meta"><strong>' + escapeHtml(addon.name) + '</strong><span>' + money(addon.price) + '</span></div>';
            html += '<div class="munch-pos-qty munch-pos-addon__qty">';
            html += '<button type="button" data-addon-delta="-1" aria-label="−">−</button>';
            html += '<span data-addon-count>0</span>';
            html += '<button type="button" data-addon-delta="1" aria-label="+">+</button>';
            html += '</div></div>';
        });
        if (posAddons(product).length) html += '</div>';
        html += '</div><div class="munch-pos-dialog__actions">';
        html += '<div class="munch-pos-qty munch-pos-dialog__qty"><button type="button" id="pos-mod-minus">−</button><span id="pos-mod-qty">1</span><button type="button" id="pos-mod-plus">+</button></div>';
        html += '<button type="button" class="munch-pos-place" id="pos-mod-add">' + escapeHtml(CFG.labels.add) + '</button>';
        html += '<button type="button" class="munch-pos-clear" id="pos-mod-close">Close</button></div>';
        card.innerHTML = html;
        modal.hidden = false;
        var qty = 1;
        card.querySelector('#pos-mod-minus').onclick = function () { qty = Math.max(1, qty - 1); card.querySelector('#pos-mod-qty').textContent = qty; };
        card.querySelector('#pos-mod-plus').onclick = function () { qty += 1; card.querySelector('#pos-mod-qty').textContent = qty; };
        card.querySelector('#pos-mod-close').onclick = function () { modal.hidden = true; };
        card.querySelectorAll('[data-addon-delta]').forEach(function (btn) {
            btn.onclick = function () {
                var row = btn.closest('[data-addon-id]');
                if (!row) return;
                setAddonRowQty(row, nextAddonQty(row.getAttribute('data-addon-qty'), btn.getAttribute('data-addon-delta')));
            };
        });
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
            var selectedAddons = collectSelectedAddons(card);
            addSelectedVariations(product, variations, qty, selectedAddons.addon_id, selectedAddons.addon_quantities);
            persistCart();
            modal.hidden = true;
            refreshCartUi(product.id);
        };
    }

    function buildPayload(clientUuid, placedAt) {
        return {
            client_uuid: clientUuid,
            placed_at: placedAt,
            order_type: state.cart.orderType,
            type: state.cart.payment,
            paid_amount: grandTotal(),
            extra_discount: allowsDiscount() ? Number(state.cart.discount || 0) : 0,
            extra_discount_type: state.cart.discountType,
            delivery_charge: deliveryCharge(),
            address: state.cart.orderType === 'delivery' ? {
                contact_person_name: state.cart.address.contact_person_name || '',
                contact_person_number: state.cart.address.contact_person_number || '',
                address: state.cart.address.address || '',
                distance: 0
            } : null,
            items: state.cart.lines.map(function (line) {
                return {
                    id: line.productId,
                    quantity: line.quantity,
                    variations: line.variations,
                    addon_id: line.addon_id || [],
                    addon_quantities: line.addon_quantities || {}
                };
            })
        };
    }

    function validateCart() {
        if (!state.cart.lines.length) return CFG.labels.emptyCart;
        return null;
    }

    function phoneDigits(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    function invalidPhone(value) {
        var digits = phoneDigits(value);
        return digits.length < 9 || digits.length > 12;
    }

    function validateDeliveryDetails() {
        if (!String(state.cart.address.contact_person_name || '').trim()) return CFG.labels.customerName || 'Customer Name';
        if (!String(state.cart.address.contact_person_number || '').trim()) return CFG.labels.customerPhone || 'Customer Phone';
        if (invalidPhone(state.cart.address.contact_person_number)) return CFG.labels.invalidPhone || 'Invalid phone number';
        if (!String(state.cart.address.address || '').trim()) return CFG.labels.deliveryAddress || CFG.labels.address;
        return null;
    }

    function readDeliveryModal() {
        if (!state.cart.address) state.cart.address = {};
        var name = document.getElementById('pos-del-name');
        var phone = document.getElementById('pos-del-phone');
        var address = document.getElementById('pos-del-address');
        var fee = document.getElementById('pos-del-fee');
        if (name) state.cart.address.contact_person_name = name.value;
        if (phone) state.cart.address.contact_person_number = phone.value;
        if (address) state.cart.address.address = address.value;
        if (fee) {
            var amount = Number(fee.value);
            state.cart.deliveryFee = isFinite(amount) && amount >= 0 ? amount : 0;
        }
    }

    function resetDelivery() {
        if (Delivery) Delivery.applyEmptyDelivery(state.cart);
        else {
            state.cart.deliveryFee = 0;
            state.cart.address = { contact_person_name: '', contact_person_number: '', address: '' };
        }
        fillDeliveryModal();
    }

    function fillDeliveryModal() {
        if (!state.cart.address) state.cart.address = {};
        var name = document.getElementById('pos-del-name');
        var phone = document.getElementById('pos-del-phone');
        var address = document.getElementById('pos-del-address');
        var fee = document.getElementById('pos-del-fee');
        if (name) name.value = state.cart.address.contact_person_name || '';
        if (phone) phone.value = state.cart.address.contact_person_number || '';
        if (address) address.value = state.cart.address.address || '';
        if (fee) fee.value = Number(state.cart.deliveryFee || 0);
        var error = document.getElementById('pos-delivery-error');
        if (error) {
            error.hidden = true;
            error.textContent = '';
        }
    }

    function showDeliveryError(message) {
        var error = document.getElementById('pos-delivery-error');
        if (!error) {
            toast(message);
            return;
        }
        error.hidden = false;
        error.textContent = message;
    }

    function openDeliveryModal() {
        fillDeliveryModal();
        if (els.deliveryModal) els.deliveryModal.hidden = false;
        syncPosOverlayState();
        var name = document.getElementById('pos-del-name');
        if (name) {
            requestAnimationFrame(function () {
                name.focus();
                try { name.select(); } catch (err) {}
            });
        }
    }

    function closeDeliveryModal() {
        if (els.deliveryModal) els.deliveryModal.hidden = true;
        syncPosOverlayState();
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

    function postCancel(payload, retried) {
        if (!CFG.urls.cancelOrder) {
            return Promise.resolve({ success: 0, message: 'Cancel is unavailable' });
        }
        return fetch(CFG.urls.cancelOrder, {
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
                    return postCancel(payload, true);
                });
            }
            return res.json().catch(function () { return {}; }).then(function (body) {
                return parsePosResponse(res, body);
            });
        });
    }

    function clearCart() {
        var ids = [];
        (state.cart.lines || []).forEach(function (line) {
            if (ids.indexOf(line.productId) === -1) ids.push(line.productId);
        });
        state.cart.lines = [];
        state.cart.discount = 0;
        state.cart.paid = '';
        resetDelivery();
        persistCart();
        renderLines();
        renderTotals();
        ids.forEach(updateProductCard);
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
        var id = payload && payload.client_uuid != null ? String(payload.client_uuid) : '';
        if (!id) return Promise.reject(new Error('missing_uuid'));
        return idbGet('queue', id).then(function (existing) {
            var decision = SubmitGuard && SubmitGuard.enqueueUnique
                ? SubmitGuard.enqueueUnique(existing ? [existing] : [], payload)
                : { inserted: !existing, duplicate: !!existing, row: existing || {
                    id: id,
                    createdAt: payload.placed_at,
                    payload: payload,
                    status: 'queued',
                    attempts: 0,
                    lastError: null
                } };
            if (!decision.inserted) {
                return refreshQueueCount().then(function () { return decision.row; });
            }
            return idbPut('queue', decision.row).then(refreshQueueCount).then(function () {
                return decision.row;
            });
        });
    }

    function syncQueue() {
        if (!navigator.onLine || syncInFlight) return Promise.resolve();
        syncInFlight = true;
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
        }).then(function () {
            syncInFlight = false;
        });
    }

    function syncOne(row) {
        if (row.status === 'syncing') return Promise.resolve();
        row.status = 'syncing';
        row.attempts = (row.attempts || 0) + 1;
        return idbPut('queue', row).then(function () {
            var payload = row.payload || {};
            var post = payload.action === 'cancel' ? postCancel(payload) : postOrder(payload);
            return post;
        }).then(function (body) {
            if (body && (body.success === 1 || body.duplicate)) {
                return idbDelete('queue', row.id).then(refreshQueueCount).then(function () {
                    if ((row.payload || {}).action === 'cancel') {
                        applyCancelledOrder(body && body.order, row.payload);
                        if (ordersUi.open) fetchTodayOrders();
                    }
                });
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

    function pad2(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function formatTicketDate(d) {
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return pad2(d.getDate()) + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
    }

    function formatTicketTime(d) {
        return pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }

    function orderTypeLabel(type) {
        if (type === 'delivery') return L('delivery', 'Delivery');
        if (type === 'take_away' || type === 'takeaway') return L('takeAway', 'Take Away');
        if (type === 'dine_in') return L('dineIn', 'Dine In');
        if (type === 'glovo') return L('glovo', 'Glovo');
        if (type === 'uber') return L('uber', 'Uber');
        if (type === 'bolt_food') return L('boltFood', 'Bolt Food');
        return type || '';
    }

    function optionLabelsFromVariations(variations) {
        var out = [];
        (variations || []).forEach(function (group) {
            var values = group && group.values;
            if (values && values.label) {
                (values.label || []).forEach(function (label) {
                    if (label) out.push(String(label));
                });
                return;
            }
            if (!Array.isArray(values)) return;
            values.forEach(function (value) {
                if (value && value.label) out.push(String(value.label));
                else if (typeof value === 'string' && value) out.push(value);
            });
        });
        return out;
    }

    function snapshotPrintJob(body, payload) {
        if (body && body.order) {
            return printJobFromOrder(body.order);
        }
        var now = new Date();
        var type = (payload && payload.order_type) || state.cart.orderType;
        var addr = (payload && payload.address) || (type === 'delivery' ? (state.cart.address || {}) : {}) || {};
        var fee = payload && payload.delivery_charge != null ? Number(payload.delivery_charge) : deliveryCharge();
        var total = payload && payload.paid_amount != null ? Number(payload.paid_amount) : grandTotal();
        var pay = (payload && payload.type) || state.cart.payment;
        return {
            orderId: body && body.order_id ? Number(body.order_id) : 0,
            kitchenPrinted: !!(body && body.kitchen_printed),
            receiptPrinted: !!(body && body.receipt_printed),
            number: (body && (body.order_display_id || (body.order_id ? '#' + body.order_id : ''))) || '',
            branch: CFG.branchName || '',
            date: formatTicketDate(now),
            time: formatTicketTime(now),
            orderType: orderTypeLabel(type),
            salesChannel: type === 'take_away' ? 'takeaway' : type,
            isDelivery: type === 'delivery',
            items: (state.cart.lines || []).map(function (line) {
                var product = state.productMap[line.productId] || { name: 'Item' };
                return {
                    name: product.name,
                    quantity: line.quantity,
                    options: optionLabelsFromVariations(line.variations || []).concat(addonLabels(line)),
                    unit_price: lineUnit(line),
                    line_total: lineSubtotal(line)
                };
            }),
            customer: type === 'delivery' ? (addr.contact_person_name || '') : '',
            phone: type === 'delivery' ? (addr.contact_person_number || '') : '',
            address: type === 'delivery' ? (addr.address || '') : '',
            notes: '',
            subtotal: cartSubtotal(),
            delivery_fee: type === 'delivery' ? fee : 0,
            discount: extraDiscount(cartSubtotal()),
            grand_total: total,
            payment_method: pay,
            payment_status: immediatePaymentStatus(pay),
            paid_amount: total,
            cash_received: 0,
            change: 0,
            mpesa_till: branchMpesaTill(),
            cashier: CFG.cashierName || CFG.branchName || '',
            riderName: '',
            riderPhone: ''
        };
    }

    function printJobFromOrder(order) {
        return {
            orderId: Number(order.id || 0),
            kitchenPrinted: !!order.kitchen_printed,
            receiptPrinted: !!order.receipt_printed,
            number: order.number || '',
            branch: order.branch || order.cashier || CFG.branchName || '',
            date: order.date || String(order.created_at || '').replace(/\s+\d{2}:\d{2}$/, ''),
            time: order.time || '',
            orderType: order.sales_channel_label || orderTypeLabel(order.sales_channel),
            salesChannel: order.sales_channel,
            isDelivery: order.sales_channel === 'delivery',
            items: (order.items || []).map(function (item) {
                return {
                    name: item.name,
                    quantity: item.quantity,
                    options: item.options || optionLabelsFromVariations(item.variations || []),
                    unit_price: item.unit_price,
                    line_total: item.line_total
                };
            }),
            customer: order.customer || '',
            phone: order.phone || '',
            address: order.address || '',
            notes: order.notes || '',
            subtotal: order.subtotal,
            delivery_fee: order.sales_channel === 'delivery' ? Number(order.delivery_fee || 0) : 0,
            discount: order.discount,
            grand_total: order.grand_total,
            payment_method: order.payment_method,
            payment_status: order.payment_status || immediatePaymentStatus(order.payment_method),
            paid_amount: order.paid_amount != null ? order.paid_amount : order.grand_total,
            cash_received: 0,
            change: 0,
            mpesa_till: String(order.mpesa_till || branchMpesaTill()).trim(),
            cashier: order.cashier || CFG.cashierName || CFG.branchName || '',
            riderName: order.rider_name || '',
            riderPhone: order.rider_phone || '',
            order_status: order.order_status || '',
            cancel_queued: !!order.cancel_queued
        };
    }

    function openSuccessModal(job) {
        if (successJob) return;
        successJob = job;
        if (!els.successModal) {
            toast(CFG.labels.placed);
            successJob = null;
            clearCart();
            return;
        }
        if (els.successNumber) els.successNumber.textContent = job.number || '';
        if (els.successTotal) els.successTotal.textContent = money(job.grand_total);
        if (els.successPay) els.successPay.textContent = paymentLabel(job.payment_method);
        applyPrintButtonState(job);
        els.successModal.hidden = false;
        syncPosOverlayState();
        renderTotals();
    }

    function dismissPlacedOrder() {
        successJob = null;
        if (els.successModal) els.successModal.hidden = true;
        syncPosOverlayState();
        clearCart();
    }

    function printedLabel(kind, done) {
        if (kind === 'kitchen') {
            return done ? ('✓ ' + L('kitchenPrinted', 'Kitchen Order Printed')) : L('printKitchen', 'Print Kitchen Order');
        }
        return done ? ('✓ ' + L('receiptPrinted', 'Receipt Printed')) : L('printReceipt', 'Print Receipt');
    }

    function isCancelledStatus(status) {
        return status === 'canceled' || status === 'cancelled';
    }

    function isCancelledOrder(order) {
        return !!(order && (isCancelledStatus(order.order_status) || order.cancel_queued));
    }

    function isCancelledJob(job) {
        return !!(job && (isCancelledStatus(job.orderStatus) || isCancelledOrder(job)));
    }

    function orderAllowsCancel(order) {
        return !!(order && order.cancellable && !isMarketplaceChannel(order.sales_channel) && !isCancelledOrder(order));
    }

    function ordersCardEl() {
        return els.ordersModal ? els.ordersModal.querySelector('.munch-pos-orders__card') : null;
    }

    function syncPosOverlayState() {
        var nested = !!(cancelUi.open && els.cancelModal && !els.cancelModal.hidden);
        if (els.ordersModal) {
            els.ordersModal.classList.toggle('is-nested-open', nested);
        }
        var card = ordersCardEl();
        if (card) {
            if (nested) {
                card.setAttribute('inert', '');
                card.setAttribute('aria-hidden', 'true');
            } else {
                card.removeAttribute('inert');
                card.removeAttribute('aria-hidden');
            }
        }
        var overlayOpen = !!(
            (els.ordersModal && !els.ordersModal.hidden) ||
            (els.successModal && !els.successModal.hidden) ||
            (els.deliveryModal && !els.deliveryModal.hidden)
        );
        document.documentElement.classList.toggle('munch-pos-overlay-open', overlayOpen);
    }

    function applyCancelledOrder(serverOrder, payload) {
        var orderId = Number((serverOrder && serverOrder.id) || (payload && payload.order_id) || 0);
        if (!orderId) return;
        ordersUi.orders.forEach(function (order, index) {
            if (Number(order.id) !== orderId) return;
            if (serverOrder && typeof serverOrder === 'object') {
                ordersUi.orders[index] = serverOrder;
                delete cancelQueuedIds[orderId];
                return;
            }
            order.order_status = 'canceled';
            order.order_status_label = L('cancelled', 'Cancelled');
            order.cancellable = false;
            order.cancel_queued = false;
            order.cancellation_reason = (payload && payload.cancellation_reason) || order.cancellation_reason || '';
            delete cancelQueuedIds[orderId];
        });
        if (ordersUi.open) renderOrdersList();
    }

    function setCancelError(message) {
        if (!els.cancelError) return;
        if (!message) {
            els.cancelError.hidden = true;
            els.cancelError.textContent = '';
            return;
        }
        els.cancelError.hidden = false;
        els.cancelError.textContent = message;
    }

    function setCancelSubmitting(on) {
        cancelUi.submitting = !!on;
        if (els.cancelReason) els.cancelReason.disabled = !!on;
        if (els.cancelDismiss) els.cancelDismiss.disabled = !!on;
        if (els.cancelConfirm) {
            els.cancelConfirm.disabled = !!on;
            els.cancelConfirm.innerHTML = on
                ? ('<span class="munch-pos-place__spin" aria-hidden="true"></span>' + escapeHtml(L('cancelling', 'Cancelling...')))
                : escapeHtml(L('confirmCancellation', 'Confirm Cancellation'));
        }
    }

    function openCancelModal(order) {
        if (!orderAllowsCancel(order) || !els.cancelModal) return;
        cancelUi.open = true;
        cancelUi.order = order;
        cancelUi.clientUuid = uuid();
        cancelUi.submitting = false;
        cancelUi.opener = document.activeElement;
        if (els.cancelReason) {
            els.cancelReason.value = '';
            els.cancelReason.disabled = false;
        }
        setCancelError('');
        setCancelSubmitting(false);
        els.cancelModal.hidden = false;
        syncPosOverlayState();
        requestAnimationFrame(function () {
            if (els.cancelReason) els.cancelReason.focus();
        });
    }

    function closeCancelModal() {
        if (cancelUi.submitting) return;
        cancelUi.open = false;
        cancelUi.order = null;
        cancelUi.clientUuid = '';
        if (els.cancelModal) els.cancelModal.hidden = true;
        setCancelError('');
        setCancelSubmitting(false);
        syncPosOverlayState();
        var opener = cancelUi.opener;
        cancelUi.opener = null;
        if (opener && typeof opener.focus === 'function' && ordersUi.open && !opener.hasAttribute('disabled')) {
            try { opener.focus(); } catch (err) {}
        }
    }

    function trapCancelFocus(ev) {
        if (ev.key !== 'Tab' || !cancelUi.open || !els.cancelModal || els.cancelModal.hidden) return;
        var nodes = els.cancelModal.querySelectorAll('textarea:not([disabled]), button:not([disabled])');
        if (!nodes.length) return;
        var first = nodes[0];
        var last = nodes[nodes.length - 1];
        if (ev.shiftKey && document.activeElement === first) {
            ev.preventDefault();
            last.focus();
        } else if (!ev.shiftKey && document.activeElement === last) {
            ev.preventDefault();
            first.focus();
        }
    }

    function cancelReasonError(reason) {
        var text = String(reason || '').replace(/\s+/g, ' ').trim();
        if (text.length < 5) return L('cancellationReason', 'Cancellation Reason') + ' (min 5)';
        if (text.length > 500) return L('cancellationReason', 'Cancellation Reason') + ' (max 500)';
        return '';
    }

    function buildCancelPayload(order, reason, clientUuid) {
        return {
            action: 'cancel',
            client_uuid: clientUuid,
            order_id: Number(order.id),
            cancellation_reason: reason,
            placed_at: new Date().toISOString(),
            offline: !navigator.onLine
        };
    }

    function markOrderCancelQueued(order, reason) {
        cancelQueuedIds[Number(order.id)] = reason;
        order.cancellable = false;
        order.cancel_queued = true;
        order.cancellation_reason = reason;
        if (ordersUi.open) renderOrdersList();
    }

    function applyQueuedCancels(orders) {
        (orders || []).forEach(function (order) {
            var queued = cancelQueuedIds[Number(order.id)];
            if (!queued) return;
            if (isCancelledOrder(order)) {
                delete cancelQueuedIds[Number(order.id)];
                return;
            }
            order.cancellable = false;
            order.cancel_queued = true;
            if (!order.cancellation_reason) order.cancellation_reason = queued;
        });
        return orders;
    }

    function submitCancel() {
        if (cancelUi.submitting || !cancelUi.order) return;
        var reason = els.cancelReason ? String(els.cancelReason.value || '').replace(/\s+/g, ' ').trim() : '';
        var error = cancelReasonError(reason);
        if (error) {
            setCancelError(error);
            return;
        }
        setCancelError('');
        setCancelSubmitting(true);
        var order = cancelUi.order;
        var payload = buildCancelPayload(order, reason, cancelUi.clientUuid || uuid());
        if (!navigator.onLine) {
            return enqueue(payload).then(function () {
                markOrderCancelQueued(order, reason);
                cancelUi.submitting = false;
                closeCancelModal();
                toast(L('cancelQueued', 'Cancellation saved offline'));
            }).catch(function () {
                setCancelSubmitting(false);
                setCancelError(CFG.labels.queueFailed || 'Could not save offline. Please try again.');
            });
        }
        return postCancel(payload).then(function (body) {
            if (body && (body.success === 1 || body.duplicate)) {
                applyCancelledOrder(body.order, payload);
                cancelUi.submitting = false;
                closeCancelModal();
                if (ordersUi.open) fetchTodayOrders();
                return;
            }
            if (body && (body._http === 401 || body._http === 403 || body.code === 'unauthenticated')) {
                state.authRequired = true;
                setCancelSubmitting(false);
                setCancelError(CFG.labels.sessionExpired || 'Session expired');
                return;
            }
            setCancelSubmitting(false);
            setCancelError((body && body.message) || CFG.labels.syncFailed || 'Failed to sync');
        }).catch(function () {
            return enqueue(payload).then(function () {
                markOrderCancelQueued(order, reason);
                cancelUi.submitting = false;
                closeCancelModal();
                toast(L('cancelQueued', 'Cancellation saved offline'));
            }).catch(function () {
                setCancelSubmitting(false);
                setCancelError(CFG.labels.queueFailed || 'Could not save offline. Please try again.');
            });
        });
    }


    function applyPrintButtonState(job) {
        if (!job || !successJob || Number(successJob.orderId) !== Number(job.orderId)) return;
        if (els.successKitchen) {
            els.successKitchen.disabled = !!job.kitchenPrinted;
            els.successKitchen.textContent = printedLabel('kitchen', job.kitchenPrinted);
        }
        if (els.successReceipt) {
            els.successReceipt.disabled = !!job.receiptPrinted;
            els.successReceipt.textContent = printedLabel('receipt', job.receiptPrinted);
        }
    }

    function rememberPrinted(orderId, kind) {
        if (!orderId) return;
        ordersUi.orders.forEach(function (order) {
            if (Number(order.id) !== Number(orderId)) return;
            if (kind === 'kitchen') order.kitchen_printed = true;
            if (kind === 'receipt') order.receipt_printed = true;
        });
        if (ordersUi.open) renderOrdersList();
    }

    function markTicketPrinted(orderId, kind) {
        if (!orderId || !CFG.urls.printTicket) return Promise.resolve();
        return fetch(CFG.urls.printTicket, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Munch-POS': '1'
            },
            body: JSON.stringify({ order_id: orderId, ticket: kind })
        }).then(function (res) {
            return res.json().catch(function () { return {}; });
        }).catch(function () { return {}; });
    }

    function printPlacedTicket(kind) {
        if (!successJob) return;
        printOneTicket(successJob, kind).then(function () {
            applyPrintButtonState(successJob);
        });
    }

    function receiptPack() {
        if (state.catalog && state.catalog.receipt) return state.catalog.receipt;
        if (CFG.catalog && CFG.catalog.receipt) return CFG.catalog.receipt;
        return CFG.receipt || {};
    }

    function ticketRenderOptions() {
        var pack = receiptPack();
        return {
            context: pack.context || {
                branch_name: CFG.branchName,
                restaurant_name: CFG.restaurantName
            },
            print: pack.print || { paper: '80mm', receipt_copies: 1, kitchen_copies: 1, auto_cut: true, drawer_kick: false },
            currency: (state.catalog && state.catalog.currency_symbol) || (CFG.catalog && CFG.catalog.currency_symbol) || ''
        };
    }

    function ticketTemplate(kind) {
        var pack = receiptPack();
        return (kind === 'kitchen' ? pack.kitchen : pack.customer) || {};
    }

    function ticketCss(kind) {
        return window.MunchReceiptTicket.css(kind, ticketTemplate(kind), ticketRenderOptions().print);
    }

    function ticketDocument(kind, bodyHtml) {
        return window.MunchReceiptTicket.renderDocument(kind, ticketTemplate(kind), { items: [] }, ticketRenderOptions());
    }

    function orderTypeBannerHtml(job) {
        return window.MunchReceiptTicket.channelBadgeHtml(job);
    }

    function kitchenTicketHtml(job) {
        return window.MunchReceiptTicket.renderDocument('kitchen', ticketTemplate('kitchen'), job, ticketRenderOptions());
    }

    function receiptTicketHtml(job) {
        return window.MunchReceiptTicket.renderDocument('customer', ticketTemplate('customer'), job, ticketRenderOptions());
    }

    function printTicket(html) {
        return new Promise(function (resolve) {
            var frame = els.printFrame;
            if (!frame) {
                resolve();
                return;
            }
            var settled = false;
            function settle() {
                if (settled) return;
                settled = true;
                resolve();
            }
            frame.onload = function () {
                var win = frame.contentWindow;
                if (!win) {
                    settle();
                    return;
                }
                var fallback = 0;
                function onAfter() {
                    if (fallback) clearTimeout(fallback);
                    try { win.removeEventListener('afterprint', onAfter); } catch (err) {}
                    setTimeout(settle, 280);
                }
                try { win.addEventListener('afterprint', onAfter); } catch (err) {}
                var started = Date.now();
                try {
                    win.focus();
                    win.print();
                } catch (err) {
                    onAfter();
                    return;
                }
                if (Date.now() - started > 250) {
                    onAfter();
                    return;
                }
                fallback = setTimeout(onAfter, 45000);
            };
            frame.srcdoc = html;
        });
    }

    function printOneTicket(job, kind) {
        if (!job || printBusy) return Promise.resolve();
        if (kind === 'kitchen' && (job.kitchenPrinted || isCancelledJob(job))) return Promise.resolve();
        if (kind === 'receipt' && job.receiptPrinted) return Promise.resolve();
        printBusy = true;
        var html = kind === 'kitchen' ? kitchenTicketHtml(job) : receiptTicketHtml(job);
        var copies = window.MunchReceiptTicket.copies(kind, ticketRenderOptions().print);
        var printed = Promise.resolve();
        for (var i = 0; i < copies; i++) {
            printed = printed.then(function () { return printTicket(html); });
        }
        return printed.then(function () {
            if (kind === 'kitchen') job.kitchenPrinted = true;
            else job.receiptPrinted = true;
            rememberPrinted(job.orderId, kind);
            applyPrintButtonState(job);
            return markTicketPrinted(job.orderId, kind);
        }).then(function () {
            printBusy = false;
        }).catch(function (err) {
            printBusy = false;
            throw err;
        });
    }

    function beginOrderSubmit() {
        if (state.orderSubmitting) return false;
        if (successJob) return false;
        if (SubmitGuard) {
            if (!SubmitGuard.tryAcquire(state)) return false;
        } else {
            state.orderSubmitting = true;
        }
        state.placing = true;
        applySubmitLockUi();
        return true;
    }

    function endOrderSubmit() {
        if (SubmitGuard) SubmitGuard.release(state);
        else state.orderSubmitting = false;
        state.placing = false;
        restorePlaceButton();
        renderTotals();
    }

    function applySubmitLockUi() {
        if (els.place) {
            els.place.disabled = true;
            els.place.setAttribute('aria-busy', 'true');
            els.place.classList.add('is-submitting');
            els.place.innerHTML = '<span class="munch-pos-place__spin" aria-hidden="true"></span>' +
                escapeHtml(navigator.onLine ? L('placing', 'Placing...') : L('queueing', 'Queueing...'));
        }
        if (els.deliveryConfirm) els.deliveryConfirm.disabled = true;
    }

    function restorePlaceButton() {
        if (els.place) {
            els.place.removeAttribute('aria-busy');
            els.place.classList.remove('is-submitting');
            els.place.textContent = L('placeOrder', els.place.getAttribute('data-label') || 'Place Order');
        }
        if (els.deliveryConfirm) els.deliveryConfirm.disabled = false;
    }

    function ignoreIfSubmitting(ev) {
        if (!state.orderSubmitting && !successJob) return false;
        if (ev) {
            if (ev.preventDefault) ev.preventDefault();
            if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();
        }
        return true;
    }

    function bindSubmitControl(el, handler) {
        if (!el) return;
        function blockIfLocked(ev) {
            if (state.orderSubmitting || successJob) {
                ev.preventDefault();
                ev.stopImmediatePropagation();
            }
        }
        el.addEventListener('pointerdown', blockIfLocked, true);
        el.addEventListener('touchstart', blockIfLocked, { capture: true, passive: false });
        el.addEventListener('click', handler);
        el.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Enter' && ev.key !== ' ') return;
            ev.preventDefault();
            handler(ev);
        });
    }

    function placeOrder(ev) {
        if (state.orderSubmitting) return;
        if (ignoreIfSubmitting(ev)) return;
        if (!beginOrderSubmit()) return;
        var error = validateCart();
        if (error) {
            endOrderSubmit();
            toast(error);
            return;
        }
        if (state.cart.orderType === 'delivery') {
            endOrderSubmit();
            openDeliveryModal();
            return;
        }
        submitPlacedOrder();
    }

    function confirmDeliveryAndPlace(ev) {
        if (ignoreIfSubmitting(ev)) return;
        if (!beginOrderSubmit()) return;
        readDeliveryModal();
        var error = validateDeliveryDetails();
        if (error) {
            endOrderSubmit();
            showDeliveryError(error);
            return;
        }
        persistCart();
        closeDeliveryModal();
        renderTotals();
        submitPlacedOrder();
    }

    function finishQueuedOrder(payload, extraToast) {
        return enqueue(payload).then(function () {
            openSuccessModal(snapshotPrintJob({
                order_display_id: extraToast || L('queuedSaved', 'Order saved offline')
            }, payload));
            clearCart();
            endOrderSubmit();
            requestBackgroundSync();
        }).catch(function () {
            endOrderSubmit();
            toast(L('queueFailed', 'Could not save offline. Please try again.'));
        });
    }

    function submitPlacedOrder() {
        if (state.cart.orderType === 'delivery') {
            var deliveryError = validateDeliveryDetails();
            if (deliveryError) {
                endOrderSubmit();
                showDeliveryError(deliveryError);
                openDeliveryModal();
                return;
            }
        }
        var payload = buildPayload(uuid(), new Date().toISOString());
        if (!navigator.onLine) {
            finishQueuedOrder(payload);
            return;
        }
        renderTotals();
        postOrder(payload).then(function (body) {
            if (body && body.success === 1) {
                openSuccessModal(snapshotPrintJob(body));
                clearCart();
                endOrderSubmit();
                return;
            }
            if (body && (body._http === 401 || body._http === 403 || body.code === 'unauthenticated')) {
                state.authRequired = true;
                return finishQueuedOrder(payload, CFG.labels.sessionExpired);
            }
            if (body && body._http === 422) {
                endOrderSubmit();
                toast((body && body.message) || CFG.labels.syncFailed);
                return;
            }
            return finishQueuedOrder(payload);
        }).catch(function () {
            return finishQueuedOrder(payload);
        }).then(function () {
            renderStatus();
            renderQueue();
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
        var register = function () {
            return navigator.serviceWorker.ready.then(function (reg) {
                return reg.sync.register('munch-pos-sync');
            }).catch(function () {});
        };
        if (backgroundSyncOnce) {
            backgroundSyncOnce(register);
            return;
        }
        register();
    }

    function L(key, fallback) {
        return (CFG.labels && CFG.labels[key]) || fallback || key;
    }

    function isMarketplacePayment(method) {
        return method === 'glovo' || method === 'uber' || method === 'bolt_food';
    }

    function immediatePaymentStatus(method) {
        return (method === 'cash' || method === 'card' || method === 'mpesa' || method === 'paystack') ? 'paid' : '';
    }

    function paymentLabel(method) {
        if (method === 'cash') return L('cash', 'Cash');
        if (method === 'card') return L('card', 'Card');
        if (method === 'mpesa') return L('mpesa', 'M-PESA');
        if (method === 'paystack') return L('paystack', 'Paystack');
        if (method === 'pay_after_eating') return L('payAfter', 'Pay after eating');
        if (method === 'cash_on_delivery') return L('cod', 'Cash On Delivery');
        if (method === 'glovo') return L('glovo', 'Glovo');
        if (method === 'uber') return L('uber', 'Uber');
        if (method === 'bolt_food') return L('boltFood', 'Bolt Food');
        return method || '';
    }

    function channelBadgeHtml(channel, label) {
        if (!isMarketplaceChannel(channel)) {
            return escapeHtml(label || '');
        }
        return '<span class="munch-channel-badge munch-channel-badge--' + escapeAttr(channel) + '">' + escapeHtml(label || channel) + '</span>';
    }

    function isMarketplaceChannel(channel) {
        return channel === 'glovo' || channel === 'uber' || channel === 'bolt_food';
    }

    function paymentStatusClass(status) {
        return status === 'paid' ? 'munch-pos-order__pill--paid' : 'munch-pos-order__pill--unpaid';
    }

    function paymentStatusLabel(status) {
        return status === 'paid' ? L('paid', 'Paid') : L('unpaid', 'Unpaid');
    }

    function orderStatusClass(status) {
        if (status === 'delivered') return 'munch-pos-order__pill--done';
        if (status === 'canceled' || status === 'cancelled' || status === 'failed' || status === 'returned') {
            return 'munch-pos-order__pill--cancel';
        }
        return '';
    }

    function orderFilterChips() {
        return [
            { id: 'all', label: L('allOrders', 'All') },
            { id: 'delivery', label: L('delivery', 'Delivery') },
            { id: 'takeaway', label: L('takeAway', 'Take Away') },
            { id: 'dine_in', label: L('dineIn', 'Dine In') },
            { id: 'glovo', label: L('glovo', 'Glovo') },
            { id: 'uber', label: L('uber', 'Uber') },
            { id: 'bolt_food', label: L('boltFood', 'Bolt Food') },
            { id: 'completed', label: L('completed', 'Completed') },
            { id: 'cancelled', label: L('cancelled', 'Cancelled') },
            { id: 'active', label: L('active', 'Active') }
        ];
    }

    function renderOrderFilters() {
        if (!els.ordersFilters) return;
        els.ordersFilters.innerHTML = orderFilterChips().map(function (chip) {
            return '<button type="button" class="munch-pos-orders__chip' + (ordersUi.filter === chip.id ? ' is-active' : '') + '" data-order-filter="' + chip.id + '">' + escapeHtml(chip.label) + '</button>';
        }).join('');
    }

    function renderOrderPager() {
        if (!els.ordersPager) return;
        if (ordersUi.lastPage <= 1) {
            els.ordersPager.hidden = true;
            els.ordersPager.innerHTML = '';
            return;
        }
        els.ordersPager.hidden = false;
        els.ordersPager.innerHTML =
            '<button type="button" class="munch-pos-orders__page" data-orders-page="' + (ordersUi.page - 1) + '"' + (ordersUi.page <= 1 ? ' disabled' : '') + '>‹</button>' +
            '<span>' + escapeHtml(L('page', 'Page')) + ' ' + ordersUi.page + ' / ' + ordersUi.lastPage + '</span>' +
            '<button type="button" class="munch-pos-orders__page" data-orders-page="' + (ordersUi.page + 1) + '"' + (ordersUi.page >= ordersUi.lastPage ? ' disabled' : '') + '>›</button>';
    }

    function orderDetailHtml(order) {
        var rows = (order.items || []).map(function (item) {
            return '<tr><td>' + escapeHtml(item.name) + '</td><td>' + escapeHtml(item.quantity) + '</td><td>' + money(item.unit_price) + '</td><td>' + money(item.discount) + '</td><td>' + money(item.line_total) + '</td></tr>';
        }).join('');
        var html = '<dl class="munch-pos-order__details">';
        html += '<dt>' + escapeHtml(L('customer', 'Customer')) + '</dt><dd>' + escapeHtml(order.customer || L('walkIn', 'Walk-in')) + '</dd>';
        if (order.phone) html += '<dt>' + escapeHtml(L('phone', 'Phone')) + '</dt><dd>' + escapeHtml(order.phone) + '</dd>';
        if (order.sales_channel === 'delivery' && order.address) {
            html += '<dt>' + escapeHtml(L('addressLabel', 'Address')) + '</dt><dd>' + escapeHtml(order.address) + '</dd>';
            html += '<dt>' + escapeHtml(L('deliveryFee', 'Delivery Fee')) + '</dt><dd>' + money(order.delivery_fee) + '</dd>';
        }
        html += '<dt>' + escapeHtml(L('items', 'Items')) + '</dt><dd><table class="munch-pos-order__table"><thead><tr><th>' + escapeHtml(L('items', 'Items')) + '</th><th>' + escapeHtml(L('quantity', 'Qty')) + '</th><th>' + escapeHtml(L('unitPrice', 'Price')) + '</th><th>' + escapeHtml(L('discount', 'Discount')) + '</th><th>' + escapeHtml(L('subtotal', 'Subtotal')) + '</th></tr></thead><tbody>' + rows + '</tbody></table></dd>';
        html += '<dt>' + escapeHtml(L('grandTotal', 'Grand Total')) + '</dt><dd>' + money(order.grand_total) + '</dd>';
        html += '<dt>' + escapeHtml(L('paymentStatus', 'Payment Status')) + '</dt><dd>' + escapeHtml(paymentStatusLabel(order.payment_status)) + '</dd>';
        html += '<dt>' + escapeHtml(L('paymentMethod', 'Payment Method')) + '</dt><dd>' + escapeHtml(paymentLabel(order.payment_method)) + '</dd>';
        html += '<dt>' + escapeHtml(L('cashier', 'Cashier')) + '</dt><dd>' + escapeHtml(order.cashier) + '</dd>';
        html += '<dt>' + escapeHtml(L('createdTime', 'Created at')) + '</dt><dd>' + escapeHtml(order.created_at) + '</dd>';
        if (order.completed_at) html += '<dt>' + escapeHtml(L('completedTime', 'Delivered')) + '</dt><dd>' + escapeHtml(order.completed_at) + '</dd>';
        if (isCancelledOrder(order)) {
            html += '<dt>' + escapeHtml(L('cancellationReason', 'Cancellation Reason')) + '</dt><dd>' + escapeHtml(order.cancellation_reason || '') + '</dd>';
            html += '<dt>' + escapeHtml(L('cancelledBy', 'Cancelled by')) + '</dt><dd>' + escapeHtml(order.cancelled_by || '') + '</dd>';
            if (order.cancelled_at) html += '<dt>' + escapeHtml(L('cancelledAt', 'Cancelled at')) + '</dt><dd>' + escapeHtml(order.cancelled_at) + '</dd>';
        }
        html += '</dl>';
        return html;
    }

    function renderOrdersList() {
        if (!els.ordersList) return;
        var scrollTop = els.ordersList.scrollTop;
        if (!ordersUi.orders.length) {
            els.ordersList.innerHTML = '<p class="munch-pos-orders__empty">' + escapeHtml(L('noOrders', 'No Data Found')) + '</p>';
        } else {
            els.ordersList.innerHTML = ordersUi.orders.map(function (order) {
                var open = Number(ordersUi.expandedId) === Number(order.id);
                var summary = (order.items_summary || []).join(', ');
                return '<article class="munch-pos-order' + (open ? ' is-open' : '') + '" data-order-id="' + order.id + '">' +
                    '<div class="munch-pos-order__top">' +
                    '<div><p class="munch-pos-order__id">' + escapeHtml(order.number) + '</p>' +
                    '<p class="munch-pos-order__meta"><span>' + escapeHtml(order.time) + '</span><span>' + channelBadgeHtml(order.sales_channel, order.sales_channel_label) + '</span><span>' + escapeHtml(order.cashier) + '</span></p></div>' +
                    '<div class="munch-pos-order__side"><div class="munch-pos-order__total">' + money(order.grand_total) + '</div>' +
                    '<div class="munch-pos-order__prints">' +
                    '<button type="button" class="munch-pos-order__print" data-print-kitchen="' + order.id + '"' + (order.kitchen_printed || isCancelledOrder(order) ? ' disabled' : '') + '>' + escapeHtml(printedLabel('kitchen', order.kitchen_printed)) + '</button>' +
                    '<button type="button" class="munch-pos-order__print munch-pos-order__print--receipt" data-print-receipt="' + order.id + '"' + (order.receipt_printed ? ' disabled' : '') + '>' + escapeHtml(printedLabel('receipt', order.receipt_printed)) + '</button>' +
                    (orderAllowsCancel(order) ? '<button type="button" class="munch-pos-order__cancel" data-cancel-order="' + order.id + '">' + escapeHtml(L('cancelOrder', 'Cancel Order')) + '</button>' : '') +
                    '</div></div></div>' +
                    '<div class="munch-pos-order__pills">' +
                    (isMarketplaceChannel(order.sales_channel) ? channelBadgeHtml(order.sales_channel, order.sales_channel_label) : '') +
                    '<span class="munch-pos-order__pill">' + escapeHtml(paymentLabel(order.payment_method)) + '</span>' +
                    '<span class="munch-pos-order__pill ' + paymentStatusClass(order.payment_status) + '">' + escapeHtml(paymentStatusLabel(order.payment_status)) + '</span>' +
                    '<span class="munch-pos-order__pill ' + orderStatusClass(order.order_status) + '">' + escapeHtml(order.order_status_label || order.order_status) + '</span>' +
                    '</div>' +
                    (summary ? '<p class="munch-pos-order__items">' + escapeHtml(summary) + '</p>' : '') +
                    (open ? orderDetailHtml(order) : '') +
                    '</article>';
            }).join('');
        }
        els.ordersList.scrollTop = scrollTop;
        if (els.ordersMeta) {
            els.ordersMeta.textContent = ordersUi.total ? String(ordersUi.total) : '';
        }
        renderOrderPager();
    }

    function fetchTodayOrders() {
        if (!CFG.urls.todayOrders || !ordersUi.open) return Promise.resolve();
        ordersUi.loading = true;
        var url = CFG.urls.todayOrders + (CFG.urls.todayOrders.indexOf('?') === -1 ? '?' : '&') +
            'search=' + encodeURIComponent(ordersUi.search) +
            '&filter=' + encodeURIComponent(ordersUi.filter) +
            '&page=' + encodeURIComponent(ordersUi.page);
        return fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Munch-POS': '1' }
        }).then(function (res) {
            if (res.status === 401 || (res.redirected && String(res.url).indexOf('login') !== -1)) {
                state.authRequired = true;
                scheduleRender();
                return null;
            }
            return res.json();
        }).then(function (json) {
            ordersUi.loading = false;
            if (!json || !json.data) return;
            ordersUi.orders = applyQueuedCancels(json.data.orders || []);
            ordersUi.page = Number(json.data.page || 1);
            ordersUi.lastPage = Number(json.data.last_page || 1);
            ordersUi.total = Number(json.data.total || 0);
            renderOrdersList();
        }).catch(function () {
            ordersUi.loading = false;
        });
    }

    function startOrdersRefresh() {
        stopOrdersRefresh();
        ordersUi.timer = setInterval(function () {
            if (ordersUi.open) fetchTodayOrders();
        }, 15000);
    }

    function stopOrdersRefresh() {
        if (ordersUi.timer) {
            clearInterval(ordersUi.timer);
            ordersUi.timer = 0;
        }
    }

    function openOrdersModal() {
        if (!els.ordersModal) return;
        ordersUi.open = true;
        els.ordersModal.hidden = false;
        syncPosOverlayState();
        renderOrderFilters();
        fetchTodayOrders();
        startOrdersRefresh();
    }

    function closeOrdersModal() {
        if (cancelUi.submitting) return;
        if (cancelUi.open) closeCancelModal();
        ordersUi.open = false;
        stopOrdersRefresh();
        if (els.ordersModal) els.ordersModal.hidden = true;
        syncPosOverlayState();
    }

    function bind() {
        els.conn = document.getElementById('pos-conn-badge');
        els.queue = document.getElementById('pos-queue-badge');
        els.sync = document.getElementById('pos-sync-badge');
        els.tabs = document.getElementById('pos-tabs');
        els.tabsWrap = document.getElementById('pos-tabs-wrap');
        els.tabsPrev = document.getElementById('pos-tabs-prev');
        els.tabsNext = document.getElementById('pos-tabs-next');
        els.grid = document.getElementById('pos-grid');
        els.empty = document.getElementById('pos-empty');
        els.types = document.getElementById('pos-types');
        els.delivery = document.getElementById('pos-delivery');
        els.deliveryModal = document.getElementById('pos-delivery-modal');
        els.deliveryConfirm = document.getElementById('pos-delivery-confirm');
        els.deliveryCancel = document.getElementById('pos-delivery-cancel');
        els.fee = document.getElementById('pos-del-fee');
        els.feeCurrency = document.getElementById('pos-del-fee-currency');
        els.lines = document.getElementById('pos-lines');
        els.totals = document.getElementById('pos-totals');
        els.pay = document.getElementById('pos-pay');
        els.discountWrap = document.getElementById('pos-discount-wrap');
        els.discount = document.getElementById('pos-discount');
        els.discountType = document.getElementById('pos-discount-type');
        els.place = document.getElementById('pos-place');
        els.clear = document.getElementById('pos-clear');
        els.toast = document.getElementById('pos-toast');
        els.successModal = document.getElementById('pos-success-modal');
        els.successNumber = document.getElementById('pos-success-number');
        els.successTotal = document.getElementById('pos-success-total');
        els.successPay = document.getElementById('pos-success-pay');
        els.successKitchen = document.getElementById('pos-success-kitchen');
        els.successReceipt = document.getElementById('pos-success-receipt');
        els.successClose = document.getElementById('pos-success-close');
        els.successDone = document.getElementById('pos-success-done');
        els.printFrame = document.getElementById('pos-print-frame');
        els.topTotal = document.getElementById('pos-top-total');
        els.queueList = document.getElementById('pos-queue-list');
        els.auth = document.getElementById('pos-auth');
        els.authLink = document.getElementById('pos-auth-link');
        els.ordersModal = document.getElementById('pos-orders-modal');
        els.ordersList = document.getElementById('pos-orders-list');
        els.ordersFilters = document.getElementById('pos-orders-filters');
        els.ordersPager = document.getElementById('pos-orders-pager');
        els.ordersMeta = document.getElementById('pos-orders-meta');
        els.ordersSearch = document.getElementById('pos-orders-search');
        els.cancelModal = document.getElementById('pos-cancel-modal');
        els.cancelReason = document.getElementById('pos-cancel-reason');
        els.cancelError = document.getElementById('pos-cancel-error');
        els.cancelDismiss = document.getElementById('pos-cancel-dismiss');
        els.cancelConfirm = document.getElementById('pos-cancel-confirm');

        document.getElementById('pos-search').addEventListener('input', function (ev) {
            state.searchDraft = ev.target.value;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                state.search = state.searchDraft;
                lastGridKey = '';
                scheduleRender();
            }, 150);
        });
        els.tabs.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-cat]');
            if (!btn) return;
            if (els.grid) categoryScroll[state.categoryId] = els.grid.scrollTop;
            state.categoryId = Number(btn.getAttribute('data-cat'));
            lastGridKey = '';
            scheduleRender();
            requestAnimationFrame(function () {
                if (els.grid) els.grid.scrollTop = categoryScroll[state.categoryId] || 0;
            });
        });
        if (els.tabsPrev) els.tabsPrev.addEventListener('click', function () { scrollTabs(-1); });
        if (els.tabsNext) els.tabsNext.addEventListener('click', function () { scrollTabs(1); });
        if (els.tabs) {
            els.tabs.addEventListener('scroll', updateTabArrows, { passive: true });
        }
        window.addEventListener('resize', updateTabArrows);
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(updateTabArrows);
        }
        els.grid.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-card-delta]');
            if (!btn || !els.grid.contains(btn)) return;
            adjustProductQty(Number(btn.getAttribute('data-product')), Number(btn.getAttribute('data-card-delta')));
        });
        els.types.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-type]');
            if (!btn) return;
            state.cart.orderType = btn.getAttribute('data-type');
            state.cart.lines = state.cart.lines.filter(function (line) {
                return productChannelAvailable(state.productMap[line.productId]);
            });
            lastGridKey = '';
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
                var productId = line.productId;
                line.quantity = Math.max(1, line.quantity + delta);
                persistCart();
                refreshCartUi(productId);
                return;
            }
            var remove = ev.target.closest('[data-remove]');
            if (remove) {
                var removed = state.cart.lines[Number(remove.getAttribute('data-remove'))];
                var removedId = removed && removed.productId;
                state.cart.lines.splice(Number(remove.getAttribute('data-remove')), 1);
                persistCart();
                refreshCartUi(removedId);
            }
        });
        els.pay.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-pay]');
            if (!btn) return;
            state.cart.payment = btn.getAttribute('data-pay');
            persistCart();
            scheduleRender();
        });
        bindSubmitControl(els.deliveryConfirm, confirmDeliveryAndPlace);
        if (els.deliveryCancel) els.deliveryCancel.addEventListener('click', closeDeliveryModal);
        if (els.deliveryModal) {
            els.deliveryModal.addEventListener('click', function (ev) {
                if (ev.target.id === 'pos-delivery-modal') closeDeliveryModal();
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
        bindSubmitControl(els.place, placeOrder);
        if (els.clear) els.clear.addEventListener('click', function () {
            if (successJob) return;
            clearCart();
        });
        if (els.successKitchen) els.successKitchen.addEventListener('click', function () { printPlacedTicket('kitchen'); });
        if (els.successReceipt) els.successReceipt.addEventListener('click', function () { printPlacedTicket('receipt'); });
        if (els.successClose) els.successClose.addEventListener('click', dismissPlacedOrder);
        if (els.successDone) els.successDone.addEventListener('click', dismissPlacedOrder);
        if (els.deliveryModal) {
            els.deliveryModal.addEventListener('keydown', function (ev) {
                if (ev.key !== 'Enter') return;
                var field = ev.target.closest('[data-del-field]');
                if (!field) return;
                if (field.tagName === 'TEXTAREA' && !ev.ctrlKey) return;
                ev.preventDefault();
                var fields = Array.prototype.slice.call(els.deliveryModal.querySelectorAll('[data-del-field]'));
                var idx = fields.indexOf(field);
                if (idx > -1 && idx < fields.length - 1) {
                    fields[idx + 1].focus();
                    return;
                }
                confirmDeliveryAndPlace();
            });
        }
        if (els.successModal) {
            els.successModal.addEventListener('click', function (ev) {
                if (ev.target.id === 'pos-success-modal') dismissPlacedOrder();
            });
        }
        document.getElementById('pos-modal').addEventListener('click', function (ev) {
            if (ev.target.id === 'pos-modal') ev.target.hidden = true;
        });
        var viewOrders = document.getElementById('pos-view-orders');
        if (viewOrders) viewOrders.addEventListener('click', openOrdersModal);
        var ordersClose = document.getElementById('pos-orders-close');
        if (ordersClose) ordersClose.addEventListener('click', closeOrdersModal);
        var ordersRefresh = document.getElementById('pos-orders-refresh');
        if (ordersRefresh) ordersRefresh.addEventListener('click', function () { fetchTodayOrders(); });
        if (els.ordersModal) {
            els.ordersModal.addEventListener('click', function (ev) {
                if (cancelUi.open) return;
                if (ev.target.id === 'pos-orders-modal') closeOrdersModal();
            });
        }
        if (els.ordersSearch) {
            els.ordersSearch.addEventListener('input', function (ev) {
                ordersUi.search = ev.target.value;
                ordersUi.page = 1;
                clearTimeout(ordersUi.searchTimer);
                ordersUi.searchTimer = setTimeout(fetchTodayOrders, 280);
            });
        }
        if (els.ordersFilters) {
            els.ordersFilters.addEventListener('click', function (ev) {
                var chip = ev.target.closest('[data-order-filter]');
                if (!chip) return;
                ordersUi.filter = chip.getAttribute('data-order-filter') || 'all';
                ordersUi.page = 1;
                renderOrderFilters();
                fetchTodayOrders();
            });
        }
        if (els.ordersList) {
            els.ordersList.addEventListener('click', function (ev) {
                var cancelBtn = ev.target.closest('[data-cancel-order]');
                if (cancelBtn) {
                    ev.preventDefault();
                    ev.stopPropagation();
                    var cancelId = Number(cancelBtn.getAttribute('data-cancel-order'));
                    var cancelOrder = ordersUi.orders.find(function (row) { return Number(row.id) === cancelId; });
                    if (orderAllowsCancel(cancelOrder)) openCancelModal(cancelOrder);
                    return;
                }
                var kitchenBtn = ev.target.closest('[data-print-kitchen]');
                var receiptBtn = ev.target.closest('[data-print-receipt]');
                if (kitchenBtn || receiptBtn) {
                    var printId = Number((kitchenBtn || receiptBtn).getAttribute(kitchenBtn ? 'data-print-kitchen' : 'data-print-receipt'));
                    var order = ordersUi.orders.find(function (row) { return Number(row.id) === printId; });
                    if (order) printOneTicket(printJobFromOrder(order), kitchenBtn ? 'kitchen' : 'receipt');
                    return;
                }
                var card = ev.target.closest('[data-order-id]');
                if (!card) return;
                var id = Number(card.getAttribute('data-order-id'));
                ordersUi.expandedId = ordersUi.expandedId === id ? 0 : id;
                renderOrdersList();
            });
        }
        if (els.ordersPager) {
            els.ordersPager.addEventListener('click', function (ev) {
                var btn = ev.target.closest('[data-orders-page]');
                if (!btn || btn.disabled) return;
                var next = Number(btn.getAttribute('data-orders-page'));
                if (!next || next < 1 || next > ordersUi.lastPage) return;
                ordersUi.page = next;
                fetchTodayOrders();
            });
        }
        if (els.cancelDismiss) {
            els.cancelDismiss.addEventListener('click', function () { closeCancelModal(); });
        }
        if (els.cancelConfirm) {
            els.cancelConfirm.addEventListener('click', function () { submitCancel(); });
        }
        if (els.cancelModal) {
            els.cancelModal.addEventListener('click', function (ev) {
                if (ev.target === els.cancelModal && !cancelUi.submitting) closeCancelModal();
            });
            els.cancelModal.addEventListener('keydown', trapCancelFocus);
        }
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                if (cancelUi.open) {
                    ev.preventDefault();
                    ev.stopPropagation();
                    if (!cancelUi.submitting) closeCancelModal();
                    return;
                }
                if (successJob && els.successModal && !els.successModal.hidden) {
                    dismissPlacedOrder();
                    return;
                }
                if (ordersUi.open) closeOrdersModal();
                return;
            }
            if (ev.key !== 'Enter') return;
            if (state.orderSubmitting || successJob) {
                ev.preventDefault();
                return;
            }
            if (els.deliveryModal && !els.deliveryModal.hidden) return;
            if (els.cancelModal && !els.cancelModal.hidden) return;
            if (els.ordersModal && !els.ordersModal.hidden) return;
            if (els.successModal && !els.successModal.hidden) return;
            var tag = ev.target && ev.target.tagName;
            if (tag === 'TEXTAREA' || tag === 'INPUT' || tag === 'SELECT') return;
            if (els.place && !els.place.disabled) {
                ev.preventDefault();
                placeOrder(ev);
            }
        }, true);
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

    function preloadCatalogMedia() {
        (state.catalog.categories || []).forEach(function () { /* tabs already in catalog */ });
        (state.catalog.products || []).slice(0, 80).forEach(function (product) {
            if (!product.image) return;
            var img = new Image();
            img.decoding = 'async';
            img.src = product.image;
        });
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
            if (results[1] && Array.isArray(results[1].lines)) {
                state.cart = Delivery
                    ? Delivery.hydrateCart(state.cart, results[1])
                    : Object.assign(state.cart, results[1]);
                if (!state.cart.address) state.cart.address = {};
            }
            resetDelivery();
            persistCart();
            return refreshQueueCount();
        }).then(function () {
            scheduleRender();
            preloadCatalogMedia();
            if (navigator.onLine) refreshHeartbeat().then(function () { syncQueue(); });
        }).catch(function () {
            scheduleRender();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
