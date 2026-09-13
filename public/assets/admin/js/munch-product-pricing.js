(function () {
    'use strict';

    var root = document.getElementById('munch-pricing-root');
    if (!root) return;

    var CFG = {
        csrf: document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        show: root.getAttribute('data-show-url'),
        update: root.getAttribute('data-update-url'),
        meta: root.getAttribute('data-meta-url'),
        products: root.getAttribute('data-products-url'),
        previewPrice: root.getAttribute('data-preview-price-url'),
        currentPrice: root.getAttribute('data-current-price-url'),
        applyPrice: root.getAttribute('data-apply-price-url'),
        previewAvail: root.getAttribute('data-preview-avail-url'),
        applyAvail: root.getAttribute('data-apply-avail-url'),
        copyPreview: root.getAttribute('data-copy-preview-url'),
        copyApply: root.getAttribute('data-copy-apply-url'),
        currency: root.getAttribute('data-currency') || ''
    };

    var CHANNELS = ['pos', 'uber', 'glovo', 'bolt_food'];
    var MARKETPLACE_CHANNELS = ['uber', 'glovo', 'bolt_food'];
    var CHANNEL_LABELS = { pos: 'POS', uber: 'Uber', glovo: 'Glovo', bolt_food: 'Bolt Food', default: 'Default' };
    var metaCache = null;
    var drawer = {
        productId: null,
        payload: null,
        defaultPrice: null,
        originalDefault: null,
        dirty: {},
        branchSearch: '',
        productSearch: '',
        channel: '',
        productResults: []
    };
    var bulk = {
        tab: 'price',
        productQuery: '',
        categoryId: '',
        products: [],
        selectedProducts: {},
        selectedBranches: {},
        channels: { pos: false, uber: true, glovo: true, bolt_food: true },
        advanced: false,
        action: 'set_exact',
        value: '',
        fillAll: '',
        productValues: {},
        variationValues: {},
        channelOps: {
            pos: { action: 'set_exact', value: '' },
            uber: { action: 'set_exact', value: '' },
            glovo: { action: 'set_exact', value: '' },
            bolt_food: { action: 'set_exact', value: '' }
        },
        availChannels: { pos: false, uber: true, glovo: true, bolt_food: true },
        availEnabled: false,
        preview: null,
        currentRows: [],
        perChannel: false,
        pricesLoading: false,
        applying: false
    };
    var bulkProductSeq = 0;
    var bulkPriceSeq = 0;
    var copy = {
        sourceId: '',
        dest: {},
        prices: { pos: true, uber: true, glovo: true, bolt_food: true },
        availability: { pos: true, uber: true, glovo: true, bolt_food: true },
        mode: 'overwrite',
        preview: null,
        summary: null
    };

    var els = {
        backdrop: document.getElementById('munch-pricing-backdrop'),
        drawer: document.getElementById('munch-pricing-drawer'),
        drawerBody: document.getElementById('munch-pricing-drawer-body'),
        drawerTitle: document.getElementById('munch-pricing-drawer-title'),
        drawerMeta: document.getElementById('munch-pricing-drawer-meta'),
        drawerImage: document.getElementById('munch-pricing-drawer-image'),
        defaultInput: document.getElementById('munch-pricing-default-price'),
        dirtyCount: document.getElementById('munch-pricing-dirty-count'),
        save: document.getElementById('munch-pricing-save'),
        branchSearch: document.getElementById('munch-pricing-branch-search'),
        productSearch: document.getElementById('munch-pricing-product-search'),
        productResults: document.getElementById('munch-pricing-product-results'),
        categoryFilter: document.getElementById('munch-pricing-category-filter'),
        channelFilter: document.getElementById('munch-pricing-channel-filter'),
        modal: document.getElementById('munch-pricing-modal'),
        modalBody: document.getElementById('munch-pricing-modal-body'),
        copyModal: document.getElementById('munch-pricing-copy-modal'),
        copyBody: document.getElementById('munch-pricing-copy-body')
    };

    function json(url, options) {
        options = options || {};
        var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CFG.csrf };
        if (options.body && !(options.body instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }
        return fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store', headers: headers }, options))
            .then(function (res) {
                return res.text().then(function (text) {
                    var data = {};
                    if (text) {
                        try {
                            data = JSON.parse(text);
                        } catch (e) {
                            throw { message: res.ok ? 'Unexpected response from server' : 'Request failed (' + res.status + ')' };
                        }
                    } else if (!res.ok) {
                        throw { message: 'Request failed (' + res.status + ')' };
                    }
                    if (!res.ok) {
                        throw data && typeof data === 'object' ? data : { message: 'Request failed (' + res.status + ')' };
                    }
                    return data;
                });
            });
    }

    function errorMessage(err, fallback) {
        if (!err) return fallback;
        if (typeof err === 'string' && err) return err;
        if (err.message) return err.message;
        if (err.errors && typeof err.errors === 'object') {
            var key = Object.keys(err.errors)[0];
            var first = key && err.errors[key];
            if (Array.isArray(first) && first[0]) return first[0];
            if (typeof first === 'string' && first) return first;
        }
        return fallback;
    }

    function showToast(ok, message) {
        if (window.toastr) {
            window.toastr[ok ? 'success' : 'error'](message);
            return;
        }
        window.alert(message);
    }

    function money(value) {
        var n = Number(value || 0);
        return CFG.currency + n.toLocaleString(undefined, { maximumFractionDigits: 2 });
    }

    function dirtyKey(branchId, channel, field) {
        return branchId + ':' + channel + ':' + field;
    }

    function openOverlay(el) {
        document.body.classList.add('munch-pricing-open');
        els.backdrop.hidden = false;
        el.hidden = false;
    }

    function closeAll() {
        closeCopy();
        els.drawer.hidden = true;
        els.modal.hidden = true;
        els.backdrop.hidden = true;
        document.body.classList.remove('munch-pricing-open');
    }

    function closeCopy() {
        if (els.copyModal) els.copyModal.hidden = true;
    }

    function showUrl(id) {
        return CFG.show.replace('__ID__', id);
    }

    function updateUrl(id) {
        return CFG.update.replace('__ID__', id);
    }

    function loadMeta() {
        if (metaCache) return Promise.resolve(metaCache);
        return json(CFG.meta).then(function (data) {
            metaCache = data;
            return data;
        });
    }

    function openDrawer(productId) {
        drawer.productId = productId;
        drawer.dirty = {};
        drawer.payload = null;
        openOverlay(els.drawer);
        els.drawerBody.innerHTML = '<div class="munch-pricing-loading">Loading pricing…</div>';
        loadMeta().then(function (meta) {
            if (els.categoryFilter && els.categoryFilter.options.length < 2) {
                (meta.categories || []).forEach(function (c) {
                    var opt = document.createElement('option');
                    opt.value = c.id;
                    opt.textContent = c.name;
                    els.categoryFilter.appendChild(opt);
                });
            }
        });
        fetchDrawer();
    }

    function fetchDrawer() {
        var url = showUrl(drawer.productId) + '?branch_search=' + encodeURIComponent(drawer.branchSearch || '') +
            '&channel=' + encodeURIComponent(drawer.channel || '') +
            '&t=' + Date.now();
        json(url).then(function (payload) {
            drawer.payload = payload;
            drawer.originalDefault = payload.product.default_price;
            drawer.defaultPrice = payload.product.default_selling_price != null
                ? payload.product.default_selling_price
                : payload.product.default_price;
            renderDrawer();
        }).catch(function () {
            els.drawerBody.innerHTML = '<div class="munch-pricing-empty">Could not load pricing.</div>';
        });
    }

    function renderDrawer() {
        var payload = drawer.payload;
        if (!payload) return;
        els.drawerTitle.textContent = payload.product.name;
        els.drawerMeta.textContent = (payload.product.category || 'Uncategorised');
        els.drawerImage.src = payload.product.image;
        els.defaultInput.value = drawer.defaultPrice;
        var channels = payload.channels || CHANNELS;
        var html = '';
        if (!payload.branches.length) {
            html = '<div class="munch-pricing-empty">No branches match this search.</div>';
        }
        payload.branches.forEach(function (branch) {
            html += '<article class="munch-pricing-branch"><div class="munch-pricing-branch__name">' +
                escapeHtml(branch.name) +
                (branch.active ? '' : ' <span class="badge badge-soft-secondary">Inactive</span>') +
                '</div><div class="munch-pricing-grid' + (channels.length === 1 ? ' munch-pricing-grid--filtered' : '') + '">';
            html += '<div></div>';
            channels.forEach(function (ch) {
                html += '<div class="munch-pricing-grid__head">' + escapeHtml(CHANNEL_LABELS[ch] || ch) + '</div>';
            });
            html += '<div class="munch-pricing-grid__label">Price</div>';
            channels.forEach(function (ch) {
                var cell = branch.cells[ch] || {};
                var key = dirtyKey(branch.id, ch, 'price');
                var reset = !!drawer.dirty[dirtyKey(branch.id, ch, 'reset')];
                var isOverride = reset ? false : (drawer.dirty[key] !== undefined ? true : cell.override);
                var value = drawer.dirty[key] !== undefined ? drawer.dirty[key] : cell.price;
                if (reset) {
                    value = cell.inherited_price != null ? cell.inherited_price : drawer.defaultPrice;
                }
                html += '<div class="munch-pricing-grid__cell">' +
                    '<input class="munch-pricing-input' + (isOverride ? ' is-override' : '') + '" type="number" min="0" step="0.01" data-price="' + branch.id + '" data-channel="' + ch + '" value="' + escapeAttr(value) + '">' +
                    (isOverride
                        ? '<button type="button" class="munch-pricing-reset" data-reset="' + branch.id + '" data-channel="' + ch + '">Reset to Default</button>'
                        : '<span class="munch-pricing-inherited">Uses ' + escapeHtml(inheritLabel(cell.inherited_from, ch)) + '</span>') +
                    '</div>';
            });
            html += '<div class="munch-pricing-grid__label">Available</div>';
            channels.forEach(function (ch) {
                var cell = branch.cells[ch] || {};
                var key = dirtyKey(branch.id, ch, 'available');
                var checked = drawer.dirty[key] !== undefined ? drawer.dirty[key] : cell.available;
                html += '<div class="munch-pricing-grid__cell"><label class="munch-pricing-switch">' +
                    '<input type="checkbox" data-avail="' + branch.id + '" data-channel="' + ch + '"' + (checked ? ' checked' : '') + '>' +
                    (checked ? 'On' : 'Off') +
                    '</label></div>';
            });
            html += '</div></article>';
        });
        els.drawerBody.innerHTML = html;
        updateDirtyCount();
    }

    function inheritLabel(from, channel) {
        if (from === 'pos') return 'POS selling price';
        if (from === 'default') return channel === 'pos' ? 'default price' : 'default selling price';
        return 'override';
    }

    function updateDirtyCount() {
        var count = Object.keys(drawer.dirty).length;
        els.dirtyCount.textContent = count ? (count + ' unsaved change' + (count === 1 ? '' : 's')) : 'No unsaved changes';
        els.save.disabled = count === 0;
    }

    function collectChanges() {
        var grouped = {};
        Object.keys(drawer.dirty).forEach(function (key) {
            var parts = key.split(':');
            var branchId = parts[0];
            var channel = parts[1];
            var field = parts[2];
            var mapKey = branchId + ':' + channel;
            grouped[mapKey] = grouped[mapKey] || { branch_id: Number(branchId), channel: channel };
            if (field === 'price') grouped[mapKey].price = drawer.dirty[key];
            if (field === 'reset') grouped[mapKey].reset_price = true;
            if (field === 'available') grouped[mapKey].is_available = drawer.dirty[key];
        });
        return Object.keys(grouped).map(function (k) { return grouped[k]; });
    }

    function saveDrawer() {
        els.save.disabled = true;
        json(updateUrl(drawer.productId), {
            method: 'POST',
            body: {
                changes: collectChanges()
            }
        }).then(function (data) {
            showToast(true, data.message || 'Pricing updated');
            drawer.dirty = {};
            drawer.payload = data.payload;
            drawer.originalDefault = data.payload.product.default_price;
            drawer.defaultPrice = data.payload.product.default_selling_price != null
                ? data.payload.product.default_selling_price
                : data.payload.product.default_price;
            renderDrawer();
        }).catch(function (err) {
            showToast(false, (err && err.message) || 'Could not save pricing');
            updateDirtyCount();
        });
    }

    function searchProducts(q, target) {
        if (!q || q.length < 1) {
            target.innerHTML = '';
            target.hidden = true;
            return;
        }
        var category = els.categoryFilter ? els.categoryFilter.value : '';
        json(CFG.products + '?search=' + encodeURIComponent(q) + '&category_id=' + encodeURIComponent(category)).then(function (data) {
            target.innerHTML = (data.data || []).map(function (p) {
                return '<button type="button" class="dropdown-item" data-jump="' + p.id + '">' + escapeHtml(p.name) + '</button>';
            }).join('') || '<div class="px-3 py-2 text-muted">No products</div>';
            target.hidden = false;
            target.classList.add('d-block');
        });
    }

    function openBulk() {
        bulk.preview = null;
        bulk.currentRows = [];
        bulk.products = [];
        bulk.selectedProducts = {};
        bulk.productQuery = '';
        bulk.categoryId = '';
        bulk.advanced = false;
        bulk.perChannel = false;
        bulk.action = 'set_exact';
        bulk.value = '';
        bulk.fillAll = '';
        bulk.productValues = {};
        bulk.variationValues = {};
        bulk.pricesLoading = false;
        bulkProductSeq += 1;
        bulkPriceSeq += 1;
        openOverlay(els.modal);
        loadMeta().then(function () {
            loadBulkProducts();
        });
    }

    function selectedChannels(map) {
        return CHANNELS.filter(function (ch) { return !!map[ch]; });
    }

    function channelChip(mapAttr, channel, selected) {
        return '<label class="munch-pricing-chip' + (selected ? ' is-on' : '') + '">' +
            '<input type="checkbox" data-bulk-channel-map="' + mapAttr + '" data-channel="' + channel + '"' +
            (selected ? ' checked' : '') + '> ' + escapeHtml(CHANNEL_LABELS[channel] || channel) + '</label>';
    }

    function channelChipRow(mapAttr, selectedMap) {
        return '<div class="munch-pricing-chips">' +
            CHANNELS.map(function (ch) { return channelChip(mapAttr, ch, !!selectedMap[ch]); }).join('') +
            '</div>' +
            '<div class="munch-pricing-chip-shortcuts">' +
            '<button type="button" class="btn btn-sm btn-outline-primary" data-bulk-channels="' + mapAttr + '" data-set="marketplace">Marketplace</button>' +
            '<button type="button" class="btn btn-sm btn-outline-primary" data-bulk-channels="' + mapAttr + '" data-set="all">All</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" data-bulk-channels="' + mapAttr + '" data-set="none">Clear</button>' +
            '</div>';
    }

    function actionOptions(selected) {
        return option('set_exact', 'Set Exact Price', selected) +
            option('increase_percent', 'Increase %', selected) +
            option('decrease_percent', 'Decrease %', selected) +
            option('increase_amount', 'Increase Amount', selected) +
            option('decrease_amount', 'Decrease Amount', selected);
    }

    function actionNeedsValue(action) {
        return action !== 'round_5' && action !== 'round_10';
    }

    function ensureChannelOp(channel) {
        if (!bulk.channelOps[channel]) {
            bulk.channelOps[channel] = { action: bulk.action, value: bulk.value };
        }
        return bulk.channelOps[channel];
    }

    function renderBulk() {
        var meta = metaCache || { branches: [], categories: [] };
        var productChecks = bulk.products.map(function (p) {
            return '<label><input type="checkbox" data-bulk-product="' + p.id + '"' + (bulk.selectedProducts[p.id] ? ' checked' : '') + '> ' +
                escapeHtml(p.name) + ' <span class="text-muted" data-bulk-product-price="' + p.id + '">' +
                escapeHtml(productCurrentPriceLabel(p)) + '</span></label>';
        }).join('');
        var branchChecks = (meta.branches || []).map(function (b) {
            return '<label><input type="checkbox" data-bulk-branch="' + b.id + '"' + (bulk.selectedBranches[b.id] ? ' checked' : '') + '> ' +
                escapeHtml(b.name) + '</label>';
        }).join('');
        var categoryOptions = '<option value="">All categories</option>' + (meta.categories || []).map(function (c) {
            return '<option value="' + c.id + '"' + (String(bulk.categoryId) === String(c.id) ? ' selected' : '') + '>' + escapeHtml(c.name) + '</option>';
        }).join('');
        var selected = selectedChannels(bulk.channels);
        var priceControls = '<label class="munch-pricing-field-label">Channels — select one or more</label>' +
            channelChipRow('channels', bulk.channels) +
            '<label class="munch-pricing-advanced-toggle">' +
            '<input type="checkbox" id="bulk-advanced"' + (bulk.advanced ? ' checked' : '') + '> Advanced adjustments</label>';
        if (!bulk.advanced) {
            priceControls += '<div class="mt-3"><label class="munch-pricing-field-label">Action</label>' +
                '<p class="munch-pricing-simple-action">Set Exact Price</p>' +
                '<div class="munch-pricing-fill-all">' +
                '<label class="munch-pricing-field-label">Fill All</label>' +
                '<div class="d-flex gap-2">' +
                '<input class="form-control" id="bulk-fill-all" type="number" min="0" step="0.01" value="' +
                escapeAttr(bulk.fillAll) + '" placeholder="e.g. 850">' +
                '<button type="button" class="btn btn-outline-primary" id="bulk-fill-all-apply">Fill</button>' +
                '</div>' +
                '<p class="munch-pricing-simple-hint">Fills every selected product. Marketplace prices can also be edited at variation level.</p></div>' +
                '<div id="bulk-product-editors" class="munch-pricing-product-editors"></div></div>';
        } else if (bulk.perChannel) {
            priceControls += '<label class="munch-pricing-advanced-toggle">' +
                '<input type="checkbox" id="bulk-per-channel" checked> Different action per channel</label>';
            priceControls += selected.length
                ? selected.map(function (ch) {
                    var op = ensureChannelOp(ch);
                    var showValue = actionNeedsValue(op.action);
                    return '<div class="munch-pricing-advanced-row">' +
                        '<strong>' + escapeHtml(CHANNEL_LABELS[ch] || ch) + '</strong>' +
                        '<select class="custom-select" data-bulk-op-action="' + ch + '">' + actionOptions(op.action) + '</select>' +
                        '<input class="form-control" data-bulk-op-value="' + ch + '" type="number" step="0.01" value="' +
                        escapeAttr(op.value) + '"' + (showValue ? '' : ' hidden') + '>' +
                        '</div>';
                }).join('')
                : '<p class="text-muted mb-0 mt-2">Select at least one channel.</p>';
        } else {
            var needsValue = actionNeedsValue(bulk.action);
            priceControls += '<label class="munch-pricing-advanced-toggle">' +
                '<input type="checkbox" id="bulk-per-channel"> Different action per channel</label>' +
                '<div class="row g-2 mt-2"><div class="col-md-6"><label>Action</label>' +
                '<select class="custom-select" id="bulk-action">' + actionOptions(bulk.action) + '</select></div>' +
                '<div class="col-md-6"' + (needsValue ? '' : ' hidden') + '><label>Value</label>' +
                '<input class="form-control" id="bulk-value" type="number" step="0.01" value="' + escapeAttr(bulk.value) + '"></div></div>';
        }

        var availControls = '<label class="munch-pricing-field-label">Channels</label>' +
            channelChipRow('availChannels', bulk.availChannels) +
            '<div class="munch-pricing-avail-action mt-3">' +
            '<label class="munch-pricing-chip' + (bulk.availEnabled ? ' is-on' : '') + '"><input type="radio" name="bulk-avail-enabled" value="1"' +
            (bulk.availEnabled ? ' checked' : '') + '> Enable</label>' +
            '<label class="munch-pricing-chip' + (!bulk.availEnabled ? ' is-on' : '') + '"><input type="radio" name="bulk-avail-enabled" value="0"' +
            (!bulk.availEnabled ? ' checked' : '') + '> Disable</label>' +
            '</div>';

        els.modalBody.innerHTML =
            '<div class="munch-pricing-modal__tabs">' +
            '<button type="button" data-tab="price"' + (bulk.tab === 'price' ? ' class="is-active"' : '') + '>Bulk Price Edit</button>' +
            '<button type="button" data-tab="availability"' + (bulk.tab === 'availability' ? ' class="is-active"' : '') + '>Bulk Availability</button>' +
            '</div>' +
            '<div class="munch-pricing-split mt-3">' +
            '<div class="munch-pricing-card"><h3>Choose Products</h3>' +
            '<div class="d-flex gap-2 mb-2"><input class="form-control" id="bulk-product-search" placeholder="Search products" value="' + escapeAttr(bulk.productQuery) + '">' +
            '<select class="custom-select" id="bulk-category">' + categoryOptions + '</select></div>' +
            '<div class="mb-2"><button type="button" class="btn btn-sm btn-outline-primary" id="bulk-select-visible">Select visible</button></div>' +
            '<div class="munch-pricing-checklist" id="bulk-product-list">' + (productChecks || '<div class="text-muted">Search to load products.</div>') + '</div></div>' +
            '<div class="munch-pricing-card"><h3>Choose Branches</h3>' +
            '<div class="mb-2"><button type="button" class="btn btn-sm btn-outline-primary" id="bulk-select-branches">Select all branches</button></div>' +
            '<div class="munch-pricing-checklist">' + branchChecks + '</div></div>' +
            '</div>' +
            '<div class="munch-pricing-card mt-3">' +
            (bulk.tab === 'price' ? priceControls : availControls) +
            '</div>' +
            '<div class="munch-pricing-card mt-3"><h3>Preview Changes</h3><div id="bulk-preview-panel">' + renderPreview(bulk.preview) + '</div></div>' +
            '<div class="munch-pricing-modal__footer px-0">' +
            '<span class="text-muted">Nothing is saved until you tap Apply.</span>' +
            '<div class="d-flex gap-2">' +
            '<button type="button" class="btn btn-outline-primary" id="bulk-preview">Preview Changes</button>' +
            '<button type="button" class="btn btn-primary" id="bulk-apply"' + (bulkApplyShouldDisable() ? ' disabled' : '') + '>Apply</button>' +
            '</div></div>';
        if (bulk.tab === 'price') renderProductEditors();
    }

    function option(value, label, selected) {
        return '<option value="' + value + '"' + (selected === value ? ' selected' : '') + '>' + label + '</option>';
    }

    function rowsForCurrentSelection(rows) {
        var ids = {};
        var selected = selectedIds(bulk.selectedProducts);
        if (selected.length) {
            selected.forEach(function (id) { ids[String(id)] = true; });
        } else {
            bulk.products.forEach(function (p) { ids[String(p.id)] = true; });
        }
        var channels = selectedChannels(bulk.channels);
        return (rows || []).filter(function (row) {
            if (!ids[String(row.product_id)]) return false;
            if (!channels.length) return row.channel === 'default' || !row.channel;
            return channels.indexOf(row.channel) !== -1 || row.channel === 'default';
        });
    }

    function renderPreview(preview) {
        var rows = preview && preview.rows && preview.rows.length ? preview.rows : null;
        var fromCurrent = false;
        if (rows && bulk.tab === 'price') {
            rows = rowsForCurrentSelection(rows);
            if (!rows.length) rows = null;
        }
        if (!rows && bulk.tab === 'price') {
            if (bulk.pricesLoading) return '<p class="text-muted mb-0">Loading current selling prices…</p>';
            if (!selectedIds(bulk.selectedProducts).length) {
                return '<p class="text-muted mb-0">Preview current vs new selling prices before saving.</p>';
            }
            rows = rowsForCurrentSelection(bulk.currentRows);
            fromCurrent = !!rows.length;
        }
        if (!rows || !rows.length) {
            if (bulk.tab === 'price' && bulk.pricesLoading) {
                return '<p class="text-muted mb-0">Loading current selling prices…</p>';
            }
            return '<p class="text-muted mb-0">Preview current vs new selling prices before saving.</p>';
        }
        var isPrice = rows[0].current_price !== undefined;
        var groups = [];
        var index = {};
        rows.forEach(function (row) {
            var key = row.product_id + ':' + (row.branch_id || 0);
            if (index[key] === undefined) {
                index[key] = groups.length;
                groups.push({
                    product_name: row.product_name,
                    branch_name: row.branch_name,
                    rows: []
                });
            }
            groups[index[key]].rows.push(row);
        });
        var body = groups.slice(0, 80).map(function (group) {
            var title = escapeHtml(group.product_name) +
                (group.branch_name && group.branch_name !== 'All branches'
                    ? ' <span class="text-muted">· ' + escapeHtml(group.branch_name) + '</span>'
                    : '');
            var lines = group.rows.map(function (row) {
                if (isPrice) {
                    var cls = !fromCurrent && row.difference > 0 ? 'is-up' : (!fromCurrent && row.difference < 0 ? 'is-down' : '');
                    var amount = fromCurrent || row.new_price == null
                        ? money(row.current_price)
                        : money(row.current_price) + ' → ' + money(row.new_price);
                    return '<div class="munch-pricing-preview-line">' +
                        '<span class="munch-pricing-preview-channel">' + escapeHtml(CHANNEL_LABELS[row.channel] || row.channel) +
                        (row.variation_name ? ' · ' + escapeHtml(row.variation_name) : '') + '</span>' +
                        '<span class="' + cls + '">' + amount + '</span></div>';
                }
                return '<div class="munch-pricing-preview-line">' +
                    '<span class="munch-pricing-preview-channel">' + escapeHtml(CHANNEL_LABELS[row.channel] || row.channel) + '</span>' +
                    '<span>' + (row.current_available ? 'On' : 'Off') + ' → ' + (row.new_available ? 'On' : 'Off') + '</span></div>';
            }).join('');
            return '<article class="munch-pricing-preview-group"><header>' + title + '</header>' + lines + '</article>';
        }).join('');
        var count = preview && preview.count != null ? preview.count : rows.length;
        var truncated = preview && preview.truncated;
        return '<p class="mb-2">' + count + (fromCurrent ? ' current selling price' : ' change') + (count === 1 ? '' : 's') +
            (truncated ? ' (showing first ' + rows.length + ')' : '') + '</p>' +
            '<div class="munch-pricing-preview-groups">' + body + '</div>';
    }

    function selectedIds(map) {
        return Object.keys(map).filter(function (id) { return map[id]; }).map(Number);
    }

    function usesPerProductPrices() {
        if (bulk.tab !== 'price') return false;
        if (!bulk.advanced) return true;
        return !bulk.perChannel && bulk.action === 'set_exact';
    }

    function productValuesPayload() {
        var out = {};
        selectedIds(bulk.selectedProducts).forEach(function (id) {
            var value = Number(bulk.productValues[id] || 0);
            if (value > 0) out[id] = value;
        });
        return out;
    }

    function selectedMarketplaceChannels() {
        return selectedChannels(bulk.channels).filter(function (ch) {
            return MARKETPLACE_CHANNELS.indexOf(ch) !== -1;
        });
    }

    function variationValueKey(productId, variationId, channel) {
        return productId + '|' + variationId + '|' + channel;
    }

    function variationValuesPayload() {
        var out = [];
        selectedIds(bulk.selectedProducts).forEach(function (productId) {
            selectedMarketplaceChannels().forEach(function (channel) {
                (productVariationOptions(productId) || []).forEach(function (option) {
                    var key = variationValueKey(productId, option.id, channel);
                    var raw = bulk.variationValues[key];
                    if (raw == null || String(raw).trim() === '') return;
                    var value = Number(raw);
                    if (isFinite(value) && value > 0) {
                        out.push({
                            product_id: productId,
                            variation_id: option.id,
                            channel: channel,
                            value: value
                        });
                    }
                });
            });
        });
        return out;
    }

    function productVariationOptions(productId) {
        var product = (bulk.products || []).find(function (p) { return Number(p.id) === Number(productId); });
        if (product && product.variations && product.variations.length) return product.variations;
        var seen = {};
        var options = [];
        (bulk.currentRows || []).forEach(function (row) {
            if (Number(row.product_id) !== Number(productId) || !row.variation_id || seen[row.variation_id]) return;
            seen[row.variation_id] = true;
            options.push({
                id: row.variation_id,
                group: row.variation_group || '',
                label: row.variation_name || row.variation_id
            });
        });
        return options;
    }

    function bulkApplyShouldDisable() {
        if (bulk.applying) return true;
        return bulk.tab === 'availability' && !(bulk.preview && bulk.preview.count);
    }

    function syncBulkApplyButton() {
        var apply = document.getElementById('bulk-apply');
        if (!apply) return;
        if (bulk.applying) {
            if (!apply.getAttribute('data-label')) apply.setAttribute('data-label', 'Apply');
            apply.disabled = true;
            apply.textContent = 'Applying…';
            return;
        }
        apply.textContent = apply.getAttribute('data-label') || 'Apply';
        apply.removeAttribute('data-label');
        apply.disabled = bulkApplyShouldDisable();
    }

    function setBulkApplyBusy(busy) {
        bulk.applying = !!busy;
        syncBulkApplyButton();
    }

    function clearBulkPriceRowErrors() {
        if (!els.modalBody) return;
        els.modalBody.querySelectorAll('.munch-pricing-product-editor.is-invalid').forEach(function (el) {
            el.classList.remove('is-invalid');
        });
        els.modalBody.querySelectorAll('[data-bulk-product-value].is-invalid, [data-bulk-variation-value].is-invalid, #bulk-value.is-invalid').forEach(function (el) {
            el.classList.remove('is-invalid');
        });
    }

    function markProductEditorInvalid(id) {
        var input = els.modalBody.querySelector('[data-bulk-product-value="' + id + '"]');
        if (!input) return;
        input.classList.add('is-invalid');
        var row = input.closest('.munch-pricing-product-editor');
        if (row) row.classList.add('is-invalid');
    }

    function hasAdvancedPriceEdits() {
        if (bulk.advanced && bulk.perChannel) {
            return selectedChannels(bulk.channels).some(function (ch) {
                var op = ensureChannelOp(ch);
                if (op.action === 'round_5' || op.action === 'round_10') return true;
                return String(op.value == null ? '' : op.value).trim() !== '';
            });
        }
        if (bulk.action === 'round_5' || bulk.action === 'round_10') return true;
        return String(bulk.value == null ? '' : bulk.value).trim() !== '';
    }

    function validateBulkPriceApply() {
        clearBulkPriceRowErrors();
        if (!selectedIds(bulk.selectedProducts).length) {
            return { message: 'Choose products first' };
        }
        if (!selectedChannels(bulk.channels).length) {
            return { message: 'Choose at least one channel' };
        }
        if (!selectedIds(bulk.selectedBranches).length) {
            return { message: 'Choose branches first' };
        }
        if (usesPerProductPrices()) {
            var invalid = false;
            selectedIds(bulk.selectedProducts).forEach(function (id) {
                var raw = bulk.productValues[id];
                if (raw == null || String(raw).trim() === '') return;
                var value = Number(raw);
                if (!isFinite(value) || value <= 0) {
                    invalid = true;
                    markProductEditorInvalid(id);
                }
            });
            els.modalBody.querySelectorAll('[data-bulk-variation-value]').forEach(function (input) {
                var raw = input.value;
                if (raw == null || String(raw).trim() === '') return;
                var value = Number(raw);
                if (!isFinite(value) || value <= 0) {
                    invalid = true;
                    input.classList.add('is-invalid');
                    var row = input.closest('.munch-pricing-product-editor');
                    if (row) row.classList.add('is-invalid');
                }
            });
            if (invalid) {
                return { message: 'Enter a valid new price for the highlighted products' };
            }
            if (!Object.keys(productValuesPayload()).length && !variationValuesPayload().length) {
                return { message: 'No price changes to apply.' };
            }
            return null;
        }
        if (actionNeedsValue(bulk.action) && !bulk.perChannel && String(bulk.value == null ? '' : bulk.value).trim() !== '') {
            var shared = Number(bulk.value);
            if (!isFinite(shared) || (bulk.action === 'set_exact' && shared <= 0)) {
                var sharedInput = document.getElementById('bulk-value');
                if (sharedInput) sharedInput.classList.add('is-invalid');
                return { message: 'Enter a valid new price' };
            }
        }
        if (!hasAdvancedPriceEdits()) {
            return { message: 'No price changes to apply.' };
        }
        return null;
    }

    function selectedProductModels() {
        return bulk.products.filter(function (p) { return !!bulk.selectedProducts[p.id]; });
    }

    function renderProductEditors() {
        var mount = document.getElementById('bulk-product-editors');
        if (!mount) return;
        if (!usesPerProductPrices()) {
            mount.innerHTML = '';
            return;
        }
        var selected = selectedProductModels();
        if (!selected.length) {
            mount.innerHTML = '<p class="text-muted mb-0">Select products to set a selling price for each one.</p>';
            return;
        }
        mount.innerHTML = selected.map(function (p) {
            var current = productCurrentPriceLabel(p);
            return '<article class="munch-pricing-product-editor">' +
                '<h4>' + escapeHtml(p.name) + '</h4>' +
                '<p class="munch-pricing-level-label">Product level</p>' +
                '<div class="munch-pricing-current-selling"><span>Current Selling Price</span>' +
                '<strong data-bulk-editor-price="' + p.id + '">' + escapeHtml(current) + '</strong></div>' +
                '<label>New Price</label>' +
                '<input class="form-control" data-bulk-product-value="' + p.id + '" type="number" min="0" step="0.01" value="' +
                escapeAttr(bulk.productValues[p.id] != null ? bulk.productValues[p.id] : '') + '" placeholder="e.g. 900">' +
                renderVariationEditors(p) +
                '</article>';
        }).join('');
    }

    function variationCurrentPrice(productId, variationId, channel) {
        var match = (bulk.currentRows || []).find(function (row) {
            return Number(row.product_id) === Number(productId)
                && row.variation_id === variationId
                && row.channel === channel;
        });
        return match ? money(match.current_price) : '—';
    }

    function renderVariationEditors(product) {
        var options = productVariationOptions(product.id);
        if (!options.length) return '';
        var channels = selectedMarketplaceChannels();
        if (!channels.length) {
            return '<div class="munch-pricing-variations"><p class="munch-pricing-level-label">Variation level</p>' +
                '<p class="text-muted mb-0">Select Uber, Glovo or Bolt Food to edit variation marketplace prices.</p></div>';
        }
        var head = '<th>Variation</th>' + channels.map(function (ch) {
            return '<th>' + escapeHtml(CHANNEL_LABELS[ch] || ch) + '</th>';
        }).join('');
        var body = options.map(function (option) {
            var cells = channels.map(function (ch) {
                var key = variationValueKey(product.id, option.id, ch);
                var current = variationCurrentPrice(product.id, option.id, ch);
                return '<td><span class="munch-pricing-variation-current">' + escapeHtml(current) + '</span>' +
                    '<input class="form-control" data-bulk-variation-value="' + escapeAttr(key) + '" data-product-id="' + product.id +
                    '" data-variation-id="' + escapeAttr(option.id) + '" data-channel="' + ch +
                    '" type="number" min="0" step="0.01" value="' +
                    escapeAttr(bulk.variationValues[key] != null ? bulk.variationValues[key] : '') +
                    '" placeholder="—"></td>';
            }).join('');
            var name = (option.group ? option.group + ' · ' : '') + (option.label || option.id);
            return '<tr><th>' + escapeHtml(name) + '</th>' + cells + '</tr>';
        }).join('');
        return '<div class="munch-pricing-variations"><p class="munch-pricing-level-label">Variation level</p>' +
            '<div class="table-responsive"><table class="munch-pricing-variation-table"><thead><tr>' + head +
            '</tr></thead><tbody>' + body + '</tbody></table></div></div>';
    }

    function fillAllSelectedProductValues() {
        selectedIds(bulk.selectedProducts).forEach(function (id) {
            bulk.productValues[id] = bulk.fillAll;
        });
        renderProductEditors();
        bulk.preview = null;
        schedulePriceRefresh();
    }

    function bulkPricePayload() {
        var channels = selectedChannels(bulk.channels);
        var values = usesPerProductPrices() ? productValuesPayload() : {};
        var variations = usesPerProductPrices() ? variationValuesPayload() : [];
        var productIds = selectedIds(bulk.selectedProducts);
        if (!productIds.length && Object.keys(values).length) {
            productIds = Object.keys(values).map(Number);
        }
        var body = {
            product_ids: productIds,
            branch_ids: selectedIds(bulk.selectedBranches)
        };
        if (Object.keys(values).length) body.product_values = values;
        if (variations.length) body.variation_values = variations;
        if (usesPerProductPrices() && !Object.keys(values).length) {
            return body;
        }
        if (bulk.advanced && bulk.perChannel) {
            body.operations = channels.map(function (ch) {
                var op = ensureChannelOp(ch);
                return { channel: ch, action: op.action, value: Number(op.value || 0) };
            });
        } else {
            body.channels = channels;
            body.action = bulk.advanced ? bulk.action : 'set_exact';
            body.value = Number(bulk.value || 0);
        }
        return body;
    }

    function applyChannelSet(target, set) {
        CHANNELS.forEach(function (ch) {
            if (set === 'all') target[ch] = true;
            else if (set === 'none') target[ch] = false;
            else target[ch] = ch !== 'pos';
        });
    }

    function runPreview() {
        var body;
        var url;
        if (bulk.tab === 'price') {
            body = bulkPricePayload();
            if (usesPerProductPrices() && !Object.keys(productValuesPayload()).length && !variationValuesPayload().length) {
                return showToast(false, 'Enter a new price for at least one product or variation');
            }
            if (!body.product_ids.length) return showToast(false, 'Choose products first');
            if (!(body.channels || []).length && !(body.operations || []).length && !(body.variation_values || []).length) {
                return showToast(false, 'Choose at least one channel');
            }
            if (!body.branch_ids.length) return showToast(false, 'Choose branches first');
            url = CFG.previewPrice;
        } else {
            body = {
                product_ids: selectedIds(bulk.selectedProducts),
                branch_ids: selectedIds(bulk.selectedBranches),
                channels: selectedChannels(bulk.availChannels),
                enabled: !!bulk.availEnabled
            };
            if (!body.product_ids.length) return showToast(false, 'Choose products first');
            if (!body.channels.length) return showToast(false, 'Choose at least one channel');
            if (!body.branch_ids.length) return showToast(false, 'Choose branches first');
            url = CFG.previewAvail;
        }
        var seq = bulkPriceSeq;
        json(url, { method: 'POST', body: body }).then(function (data) {
            if (seq !== bulkPriceSeq) return;
            bulk.preview = data;
            if (bulk.tab === 'price') patchBulkPriceUi();
            else renderBulk();
        }).catch(function (err) {
            showToast(false, errorMessage(err, 'Preview failed'));
        });
    }

    function runApply() {
        if (bulk.applying) return;
        if (bulk.tab === 'availability') {
            if (!bulk.preview || !bulk.preview.count) {
                showToast(false, 'Preview changes before applying');
                return;
            }
            setBulkApplyBusy(true);
            json(CFG.applyAvail, {
                method: 'POST',
                body: {
                    product_ids: selectedIds(bulk.selectedProducts),
                    branch_ids: selectedIds(bulk.selectedBranches),
                    channels: selectedChannels(bulk.availChannels),
                    enabled: !!bulk.availEnabled,
                    confirmed: true
                }
            }).then(function (data) {
                showToast(true, data.message || 'Updated');
                resetAfterBulkAvailApply();
            }).catch(function (err) {
                showToast(false, errorMessage(err, 'Apply failed'));
            }).then(function () {
                setBulkApplyBusy(false);
            });
            return;
        }
        var invalid = validateBulkPriceApply();
        if (invalid) {
            showToast(false, invalid.message);
            return;
        }
        setBulkApplyBusy(true);
        json(CFG.applyPrice, { method: 'POST', body: Object.assign({ confirmed: true }, bulkPricePayload()) })
            .then(function () {
                showToast(true, 'Changes applied successfully');
                resetAfterBulkPriceApply();
            })
            .catch(function (err) {
                showToast(false, errorMessage(err, 'Apply failed'));
            })
            .then(function () {
                setBulkApplyBusy(false);
            });
    }

    function uncheckBulkProducts() {
        bulk.selectedProducts = {};
        if (!els.modalBody) return;
        els.modalBody.querySelectorAll('[data-bulk-product]').forEach(function (el) {
            el.checked = false;
        });
    }

    function resetAfterBulkPriceApply() {
        uncheckBulkProducts();
        bulk.value = '';
        bulk.fillAll = '';
        bulk.productValues = {};
        bulk.variationValues = {};
        bulk.preview = null;
        CHANNELS.forEach(function (ch) {
            if (bulk.channelOps[ch]) bulk.channelOps[ch].value = '';
        });
        var valueInput = document.getElementById('bulk-value');
        if (valueInput) valueInput.value = '';
        var fillInput = document.getElementById('bulk-fill-all');
        if (fillInput) fillInput.value = '';
        els.modalBody.querySelectorAll('[data-bulk-op-value]').forEach(function (el) {
            el.value = '';
        });
        renderProductEditors();
        refreshBulkPrices({ keepCurrent: true, refetchCurrent: true });
    }

    function resetAfterBulkAvailApply() {
        uncheckBulkProducts();
        bulk.preview = null;
        var panel = document.getElementById('bulk-preview-panel');
        if (panel) panel.innerHTML = renderPreview(null);
        syncBulkApplyButton();
    }

    function openCopy() {
        copy.preview = null;
        copy.summary = null;
        if (els.copyModal) els.copyModal.hidden = false;
        loadMeta().then(renderCopy);
    }

    function copyPayload() {
        return {
            source_branch_id: Number(copy.sourceId || 0),
            destination_branch_ids: selectedIds(copy.dest),
            price_channels: CHANNELS.filter(function (ch) { return !!copy.prices[ch]; }),
            availability_channels: CHANNELS.filter(function (ch) { return !!copy.availability[ch]; }),
            mode: copy.mode
        };
    }

    function renderCopy() {
        if (!els.copyBody) return;
        var meta = metaCache || { branches: [] };
        var branches = meta.branches || [];
        if (copy.summary) {
            els.copyBody.innerHTML =
                '<div class="munch-pricing-card"><h3>Copied</h3>' +
                '<div class="munch-pricing-copy-summary">' +
                '<div>Products updated<strong>' + copy.summary.products_updated + '</strong></div>' +
                '<div>Branches updated<strong>' + copy.summary.branches_updated + '</strong></div>' +
                '<div>Rows affected<strong>' + copy.summary.rows_affected + '</strong></div>' +
                '<div>Copied<strong>Yes</strong></div></div>' +
                '<div class="munch-pricing-modal__footer px-0"><span></span>' +
                '<button type="button" class="btn btn-primary" data-copy-close>Done</button></div></div>';
            return;
        }
        var sourceOptions = '<option value="">Select source branch</option>' + branches.map(function (b) {
            return '<option value="' + b.id + '"' + (String(copy.sourceId) === String(b.id) ? ' selected' : '') + '>' + escapeHtml(b.name) + '</option>';
        }).join('');
        var destChecks = branches.map(function (b) {
            var disabled = String(b.id) === String(copy.sourceId);
            return '<label class="' + (disabled ? 'text-muted' : '') + '"><input type="checkbox" data-copy-dest="' + b.id + '"' +
                (copy.dest[b.id] && !disabled ? ' checked' : '') + (disabled ? ' disabled' : '') + '> ' + escapeHtml(b.name) + '</label>';
        }).join('');
        var field = function (group, ch, label) {
            return '<label class="munch-pricing-chip' + (copy[group][ch] ? ' is-on' : '') + '">' +
                '<input type="checkbox" data-copy-field="' + group + '" data-channel="' + ch + '"' +
                (copy[group][ch] ? ' checked' : '') + '> ' + label + '</label>';
        };
        var previewHtml = '<p class="text-muted mb-0">Nothing is written yet. Preview first.</p>';
        if (copy.preview && copy.preview.destinations) {
            previewHtml = '<p class="mb-2">Source: <strong>' + escapeHtml(copy.preview.source_branch_name) + '</strong></p>' +
                '<div class="table-responsive"><table class="munch-pricing-preview"><thead><tr>' +
                '<th>Destination Branch</th><th>Products affected</th><th>Rows affected</th><th>Prices changing</th><th>Availability changing</th>' +
                '</tr></thead><tbody>' + copy.preview.destinations.map(function (row) {
                    return '<tr><td>' + escapeHtml(row.branch_name) + '</td><td>' + row.products_affected + '</td><td>' +
                        row.rows_affected + '</td><td>' + row.prices_changing + '</td><td>' + row.availability_changing + '</td></tr>';
                }).join('') + '</tbody></table></div>';
        }
        els.copyBody.innerHTML =
            '<p class="text-muted">Copy only the channels you select. Inheritance is left in place unless you overwrite it.</p>' +
            '<div class="munch-pricing-card"><h3>1. Source Branch</h3><select class="custom-select" id="copy-source">' + sourceOptions + '</select></div>' +
            '<div class="munch-pricing-card mt-3"><h3>2. Destination Branch(es)</h3>' +
            '<div class="munch-pricing-checklist">' + destChecks + '</div></div>' +
            '<div class="munch-pricing-card mt-3"><h3>3. Choose what to copy</h3>' +
            '<div class="munch-pricing-chip-shortcuts mb-2">' +
            '<button type="button" class="btn btn-sm btn-outline-primary" id="copy-select-all">Select All</button>' +
            '<button type="button" class="btn btn-sm btn-outline-primary" id="copy-marketplace">Marketplace</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" id="copy-clear-all">Clear All</button></div>' +
            '<div class="munch-pricing-copy-section"><span>Prices</span><div class="munch-pricing-chips">' +
            field('prices', 'pos', 'POS') +
            field('prices', 'uber', 'Uber') +
            field('prices', 'glovo', 'Glovo') +
            field('prices', 'bolt_food', 'Bolt Food') +
            '</div></div>' +
            '<div class="munch-pricing-copy-section mt-3"><span>Availability</span><div class="munch-pricing-chips">' +
            field('availability', 'pos', 'POS') +
            field('availability', 'uber', 'Uber') +
            field('availability', 'glovo', 'Glovo') +
            field('availability', 'bolt_food', 'Bolt Food') +
            '</div></div></div>' +
            '<div class="munch-pricing-card mt-3"><h3>4. Conflict handling</h3>' +
            '<label><input type="radio" name="copy-mode" value="overwrite"' + (copy.mode === 'overwrite' ? ' checked' : '') + '> Overwrite everything</label>' +
            '<label><input type="radio" name="copy-mode" value="fill_missing"' + (copy.mode === 'fill_missing' ? ' checked' : '') + '> Only fill missing overrides</label>' +
            '<label><input type="radio" name="copy-mode" value="skip_existing"' + (copy.mode === 'skip_existing' ? ' checked' : '') + '> Skip existing overrides</label>' +
            '</div>' +
            '<div class="munch-pricing-card mt-3"><h3>5. Preview</h3>' + previewHtml + '</div>' +
            '<div class="munch-pricing-modal__footer px-0">' +
            '<span class="text-muted">Apply writes the previewed rows in one transaction.</span>' +
            '<div class="d-flex gap-2">' +
            '<button type="button" class="btn btn-outline-primary" id="copy-preview">Preview</button>' +
            '<button type="button" class="btn btn-primary" id="copy-apply" ' + (copy.preview && copy.preview.rows_affected ? '' : 'disabled') + '>Apply</button>' +
            '</div></div>';
    }

    function runCopyPreview() {
        json(CFG.copyPreview, { method: 'POST', body: copyPayload() }).then(function (data) {
            copy.preview = data;
            copy.summary = null;
            renderCopy();
        }).catch(function (err) {
            copy.preview = null;
            showToast(false, (err && err.message) || 'Preview failed');
            renderCopy();
        });
    }

    function runCopyApply() {
        if (!copy.preview || !copy.preview.rows_affected) return;
        var body = Object.assign({ confirmed: true }, copyPayload());
        json(CFG.copyApply, { method: 'POST', body: body }).then(function (data) {
            copy.summary = data;
            copy.preview = null;
            renderCopy();
            if (drawer.productId) fetchDrawer();
        }).catch(function (err) {
            showToast(false, (err && err.message) || 'Copy failed');
        });
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    function escapeAttr(value) {
        return escapeHtml(value);
    }

    document.addEventListener('click', function (ev) {
        var pricingBtn = ev.target.closest('[data-pricing]');
        if (pricingBtn) {
            ev.preventDefault();
            openDrawer(Number(pricingBtn.getAttribute('data-pricing')));
            return;
        }
        if (ev.target.closest('[data-bulk-pricing]')) {
            ev.preventDefault();
            openBulk();
            return;
        }
        if (ev.target.closest('[data-copy-from-branch]')) {
            ev.preventDefault();
            openCopy();
            return;
        }
        if (ev.target.closest('[data-copy-close]')) {
            ev.preventDefault();
            closeCopy();
            return;
        }
        if (ev.target.closest('[data-pricing-close]')) {
            closeAll();
            return;
        }
        var reset = ev.target.closest('[data-reset]');
        if (reset && els.drawer.contains(reset)) {
            var b = reset.getAttribute('data-reset');
            var ch = reset.getAttribute('data-channel');
            drawer.dirty[dirtyKey(b, ch, 'reset')] = true;
            delete drawer.dirty[dirtyKey(b, ch, 'price')];
            renderDrawer();
            return;
        }
        var jump = ev.target.closest('[data-jump]');
        if (jump) {
            drawer.productSearch = '';
            els.productSearch.value = '';
            els.productResults.innerHTML = '';
            els.productResults.hidden = true;
            openDrawer(Number(jump.getAttribute('data-jump')));
        }
    });

    els.backdrop.addEventListener('click', function () {
        if (els.copyModal && !els.copyModal.hidden) {
            closeCopy();
            return;
        }
        closeAll();
    });
    els.save.addEventListener('click', saveDrawer);
    els.branchSearch.addEventListener('input', debounce(function () {
        drawer.branchSearch = els.branchSearch.value;
        fetchDrawer();
    }, 250));
    els.productSearch.addEventListener('input', debounce(function () {
        searchProducts(els.productSearch.value, els.productResults);
    }, 250));
    els.channelFilter.addEventListener('change', function () {
        drawer.channel = els.channelFilter.value;
        fetchDrawer();
    });
    if (els.categoryFilter) {
        els.categoryFilter.addEventListener('change', function () {
            if (els.productSearch.value) searchProducts(els.productSearch.value, els.productResults);
        });
    }
    els.drawerBody.addEventListener('input', function (ev) {
        var price = ev.target.closest('[data-price]');
        if (price) {
            drawer.dirty[dirtyKey(price.getAttribute('data-price'), price.getAttribute('data-channel'), 'price')] = Number(price.value || 0);
            delete drawer.dirty[dirtyKey(price.getAttribute('data-price'), price.getAttribute('data-channel'), 'reset')];
            price.classList.add('is-override');
            updateDirtyCount();
        }
    });
    els.drawerBody.addEventListener('change', function (ev) {
        var avail = ev.target.closest('[data-avail]');
        if (!avail) return;
        drawer.dirty[dirtyKey(avail.getAttribute('data-avail'), avail.getAttribute('data-channel'), 'available')] = avail.checked;
        var label = avail.closest('label');
        if (label) label.lastChild.textContent = avail.checked ? ' On' : ' Off';
        updateDirtyCount();
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') return;
        if (els.copyModal && !els.copyModal.hidden) {
            closeCopy();
            return;
        }
        if (!els.drawer.hidden) closeAll();
    });

    els.modal.addEventListener('input', function (ev) {
        if (ev.target.id === 'bulk-product-search') bulk.productQuery = ev.target.value;
        if (ev.target.id === 'bulk-value') bulk.value = ev.target.value;
        if (ev.target.id === 'bulk-fill-all') bulk.fillAll = ev.target.value;
        var opValue = ev.target.getAttribute && ev.target.getAttribute('data-bulk-op-value');
        if (opValue) ensureChannelOp(opValue).value = ev.target.value;
        var productValue = ev.target.getAttribute && ev.target.getAttribute('data-bulk-product-value');
        if (productValue) {
            bulk.productValues[productValue] = ev.target.value;
            ev.target.classList.remove('is-invalid');
            var editor = ev.target.closest('.munch-pricing-product-editor');
            if (editor) editor.classList.remove('is-invalid');
        }
        var variationValue = ev.target.getAttribute && ev.target.getAttribute('data-bulk-variation-value');
        if (variationValue) {
            bulk.variationValues[variationValue] = ev.target.value;
            ev.target.classList.remove('is-invalid');
            var variationEditor = ev.target.closest('.munch-pricing-product-editor');
            if (variationEditor) variationEditor.classList.remove('is-invalid');
        }
        if (ev.target.id === 'bulk-value') ev.target.classList.remove('is-invalid');
        if (ev.target.id === 'bulk-value' || opValue || productValue || variationValue) {
            bulk.preview = null;
            schedulePriceRefresh();
        }
    });
    els.modal.addEventListener('change', function (ev) {
        if (ev.target.id === 'bulk-category') {
            bulk.categoryId = ev.target.value;
            loadBulkProducts();
            return;
        }
        if (ev.target.id === 'bulk-action') {
            bulk.action = ev.target.value;
            bulk.preview = null;
            var valueWrap = document.getElementById('bulk-value');
            if (valueWrap && valueWrap.parentElement) {
                valueWrap.parentElement.hidden = !actionNeedsValue(bulk.action);
            }
            refreshBulkPrices({ keepCurrent: true });
            return;
        }
        if (ev.target.id === 'bulk-advanced') {
            bulk.advanced = ev.target.checked;
            if (!bulk.advanced) {
                bulk.action = 'set_exact';
                bulk.perChannel = false;
            }
            bulk.preview = null;
            renderBulk();
            refreshBulkPrices({ keepCurrent: true });
            return;
        }
        if (ev.target.id === 'bulk-per-channel') {
            bulk.perChannel = ev.target.checked;
            if (bulk.perChannel) {
                selectedChannels(bulk.channels).forEach(function (ch) {
                    bulk.channelOps[ch] = { action: bulk.action, value: bulk.value };
                });
            }
            bulk.preview = null;
            renderBulk();
            refreshBulkPrices({ keepCurrent: true });
            return;
        }
        if (ev.target.name === 'bulk-avail-enabled') {
            bulk.availEnabled = ev.target.value === '1';
            bulk.preview = null;
            renderBulk();
            return;
        }
        var opAction = ev.target.getAttribute && ev.target.getAttribute('data-bulk-op-action');
        if (opAction) {
            ensureChannelOp(opAction).action = ev.target.value;
            bulk.preview = null;
            var opValue = els.modalBody.querySelector('[data-bulk-op-value="' + opAction + '"]');
            if (opValue) opValue.hidden = !actionNeedsValue(ev.target.value);
            refreshBulkPrices({ keepCurrent: true });
            return;
        }
        var channelMap = ev.target.getAttribute && ev.target.getAttribute('data-bulk-channel-map');
        if (channelMap && ev.target.getAttribute('data-channel')) {
            bulk[channelMap][ev.target.getAttribute('data-channel')] = ev.target.checked;
            bulk.preview = null;
            var chip = ev.target.closest('.munch-pricing-chip');
            if (chip) chip.classList.toggle('is-on', ev.target.checked);
            if (channelMap === 'channels') {
                if (bulk.advanced && bulk.perChannel) renderBulk();
                else if (!bulk.advanced) renderProductEditors();
                refreshBulkPrices();
            }
            return;
        }
        if (ev.target.matches('[data-bulk-product]')) {
            var productId = ev.target.getAttribute('data-bulk-product');
            bulk.selectedProducts[productId] = ev.target.checked;
            if (ev.target.checked) {
                if (bulk.productValues[productId] == null || bulk.productValues[productId] === '') {
                    bulk.productValues[productId] = bulk.fillAll || '';
                }
            } else {
                delete bulk.productValues[productId];
                Object.keys(bulk.variationValues).forEach(function (key) {
                    if (key.indexOf(productId + '|') === 0) delete bulk.variationValues[key];
                });
            }
            dropUnselectedCurrentRows();
            bulk.preview = null;
            renderProductEditors();
            patchBulkPriceUi();
            refreshBulkPrices({ keepCurrent: true, refetchCurrent: true });
            return;
        }
        if (ev.target.matches('[data-bulk-branch]')) {
            bulk.selectedBranches[ev.target.getAttribute('data-bulk-branch')] = ev.target.checked;
            refreshBulkPrices();
            return;
        }
        bulk.preview = null;
    });
    els.modal.addEventListener('click', function (ev) {
        var tab = ev.target.closest('[data-tab]');
        if (tab) {
            bulk.tab = tab.getAttribute('data-tab');
            bulk.preview = null;
            renderBulk();
            if (bulk.tab === 'price') refreshBulkPrices();
            return;
        }
        var setBtn = ev.target.closest('[data-bulk-channels]');
        if (setBtn) {
            var mapName = setBtn.getAttribute('data-bulk-channels');
            applyChannelSet(bulk[mapName], setBtn.getAttribute('data-set'));
            bulk.preview = null;
            if (mapName === 'channels' && bulk.advanced && bulk.perChannel) {
                renderBulk();
            } else {
                syncChannelChipDom(mapName);
            }
            if (mapName === 'channels') refreshBulkPrices();
            return;
        }
        if (ev.target.id === 'bulk-preview') runPreview();
        if (ev.target.closest('#bulk-apply')) {
            ev.preventDefault();
            runApply();
        }
        if (ev.target.id === 'bulk-fill-all-apply') fillAllSelectedProductValues();
        if (ev.target.id === 'bulk-select-visible') {
            bulk.products.forEach(function (p) {
                bulk.selectedProducts[p.id] = true;
                if (bulk.productValues[p.id] == null || bulk.productValues[p.id] === '') {
                    bulk.productValues[p.id] = bulk.fillAll || '';
                }
            });
            els.modalBody.querySelectorAll('[data-bulk-product]').forEach(function (el) { el.checked = true; });
            renderProductEditors();
            refreshBulkPrices({ keepCurrent: true, refetchCurrent: true });
        }
        if (ev.target.id === 'bulk-select-branches') {
            (metaCache.branches || []).forEach(function (b) { bulk.selectedBranches[b.id] = true; });
            els.modalBody.querySelectorAll('[data-bulk-branch]').forEach(function (el) { el.checked = true; });
            refreshBulkPrices();
        }
    });
    if (els.copyModal) {
        els.copyModal.addEventListener('change', function (ev) {
            if (ev.target.id === 'copy-source') {
                copy.sourceId = ev.target.value;
                delete copy.dest[copy.sourceId];
                copy.preview = null;
                renderCopy();
                return;
            }
            if (ev.target.matches('[data-copy-dest]')) {
                copy.dest[ev.target.getAttribute('data-copy-dest')] = ev.target.checked;
                copy.preview = null;
                return;
            }
            if (ev.target.matches('[data-copy-field]')) {
                copy[ev.target.getAttribute('data-copy-field')][ev.target.getAttribute('data-channel')] = ev.target.checked;
                copy.preview = null;
                renderCopy();
                return;
            }
            if (ev.target.name === 'copy-mode') {
                copy.mode = ev.target.value;
                copy.preview = null;
            }
        });
        els.copyModal.addEventListener('click', function (ev) {
            if (ev.target === els.copyModal) {
                closeCopy();
                return;
            }
            if (ev.target.id === 'copy-select-all') {
                CHANNELS.forEach(function (ch) {
                    copy.prices[ch] = true;
                    copy.availability[ch] = true;
                });
                copy.preview = null;
                renderCopy();
                return;
            }
            if (ev.target.id === 'copy-marketplace') {
                CHANNELS.forEach(function (ch) {
                    var on = ch !== 'pos';
                    copy.prices[ch] = on;
                    copy.availability[ch] = false;
                });
                copy.preview = null;
                renderCopy();
                return;
            }
            if (ev.target.id === 'copy-clear-all') {
                CHANNELS.forEach(function (ch) {
                    copy.prices[ch] = false;
                    copy.availability[ch] = false;
                });
                copy.preview = null;
                renderCopy();
                return;
            }
            if (ev.target.id === 'copy-preview') runCopyPreview();
            if (ev.target.id === 'copy-apply') runCopyApply();
        });
    }
    els.modal.addEventListener('keydown', function (ev) {
        if (ev.target.id === 'bulk-fill-all' && ev.key === 'Enter') {
            ev.preventDefault();
            fillAllSelectedProductValues();
        }
    });
    els.modal.addEventListener('keyup', debounce(function (ev) {
        if (ev.target.id === 'bulk-product-search') loadBulkProducts();
    }, 250));

    function productCurrentPriceLabel(product) {
        var rows = (bulk.currentRows || []).filter(function (row) {
            return Number(row.product_id) === Number(product.id) && !row.variation_id;
        });
        var channels = selectedChannels(bulk.channels);
        if (channels.length) {
            rows = rows.filter(function (row) {
                return channels.indexOf(row.channel) !== -1 || row.channel === 'default';
            });
        }
        if (!rows.length) {
            if (bulk.pricesLoading) return '…';
            return money(product.selling_price != null ? product.selling_price : 0);
        }
        var byChannel = {};
        rows.forEach(function (row) {
            var ch = row.channel || 'default';
            var price = Number(row.current_price);
            if (!byChannel[ch]) byChannel[ch] = { min: price, max: price };
            else {
                byChannel[ch].min = Math.min(byChannel[ch].min, price);
                byChannel[ch].max = Math.max(byChannel[ch].max, price);
            }
        });
        return Object.keys(byChannel).map(function (ch) {
            var range = byChannel[ch];
            var amount = range.min === range.max ? money(range.min) : money(range.min) + '–' + money(range.max);
            return (CHANNEL_LABELS[ch] ? CHANNEL_LABELS[ch] + ' ' : '') + amount;
        }).join(' · ');
    }

    function pruneInvisibleProductSelections() {
        var visible = {};
        bulk.products.forEach(function (p) { visible[String(p.id)] = true; });
        Object.keys(bulk.selectedProducts).forEach(function (id) {
            if (!visible[id]) delete bulk.selectedProducts[id];
        });
        Object.keys(bulk.productValues).forEach(function (id) {
            if (!visible[id] || !bulk.selectedProducts[id]) delete bulk.productValues[id];
        });
        Object.keys(bulk.variationValues).forEach(function (key) {
            var productId = key.split('|')[0];
            if (!visible[productId] || !bulk.selectedProducts[productId]) delete bulk.variationValues[key];
        });
    }

    function priceProductIds() {
        var selected = selectedIds(bulk.selectedProducts);
        if (selected.length) return selected;
        return bulk.products.map(function (p) { return Number(p.id); });
    }

    function dropUnselectedCurrentRows() {
        var selected = selectedIds(bulk.selectedProducts);
        if (!selected.length) return;
        var keep = {};
        selected.forEach(function (id) { keep[String(id)] = true; });
        bulk.currentRows = (bulk.currentRows || []).filter(function (row) {
            return keep[String(row.product_id)];
        });
    }

    function mergeCurrentRows(rows) {
        var fetched = {};
        (rows || []).forEach(function (row) { fetched[String(row.product_id)] = true; });
        var kept = (bulk.currentRows || []).filter(function (row) {
            return !fetched[String(row.product_id)];
        });
        bulk.currentRows = kept.concat(rows || []);
    }

    function syncChannelChipDom(mapName) {
        var selectedMap = bulk[mapName] || {};
        els.modalBody.querySelectorAll('[data-bulk-channel-map="' + mapName + '"]').forEach(function (input) {
            var ch = input.getAttribute('data-channel');
            input.checked = !!selectedMap[ch];
            var chip = input.closest('.munch-pricing-chip');
            if (chip) chip.classList.toggle('is-on', !!selectedMap[ch]);
        });
    }

    function patchBulkPriceUi() {
        bulk.products.forEach(function (p) {
            var label = productCurrentPriceLabel(p);
            els.modalBody.querySelectorAll('[data-bulk-product-price="' + p.id + '"], [data-bulk-editor-price="' + p.id + '"]').forEach(function (el) {
                el.textContent = label;
            });
        });
        if (els.modalBody && !els.modalBody.querySelector('.munch-pricing-variation-table') && selectedProductModels().some(function (p) {
            return productVariationOptions(p.id).length;
        })) {
            renderProductEditors();
        }
        if (els.modalBody) {
            els.modalBody.querySelectorAll('[data-bulk-variation-value]').forEach(function (input) {
                var label = input.previousElementSibling;
                if (!label || !label.classList.contains('munch-pricing-variation-current')) return;
                label.textContent = variationCurrentPrice(
                    input.getAttribute('data-product-id'),
                    input.getAttribute('data-variation-id'),
                    input.getAttribute('data-channel')
                );
            });
        }
        var panel = document.getElementById('bulk-preview-panel');
        if (panel) panel.innerHTML = renderPreview(bulk.preview);
        syncBulkApplyButton();
    }

    function invalidateBulkPrices() {
        bulkPriceSeq += 1;
        bulk.preview = null;
        bulk.currentRows = [];
        bulk.pricesLoading = true;
    }

    function refreshBulkPrices(options) {
        options = options || {};
        if (bulk.tab !== 'price') return;
        var seq = ++bulkPriceSeq;
        var productIds = priceProductIds();
        var branchIds = selectedIds(bulk.selectedBranches);
        var channels = selectedChannels(bulk.channels);
        bulk.preview = null;
        var fetchCurrent = !options.keepCurrent || options.refetchCurrent;
        if (fetchCurrent && !options.keepCurrent) {
            bulk.pricesLoading = true;
            bulk.currentRows = [];
        }
        patchBulkPriceUi();
        if (!productIds.length) {
            bulk.currentRows = [];
            bulk.pricesLoading = false;
            patchBulkPriceUi();
            return;
        }
        if (fetchCurrent && CFG.currentPrice) {
            json(CFG.currentPrice, {
                method: 'POST',
                body: {
                    product_ids: productIds,
                    branch_ids: branchIds,
                    channels: channels
                }
            }).then(function (data) {
                if (seq !== bulkPriceSeq) return;
                bulk.pricesLoading = false;
                if (options.keepCurrent && options.refetchCurrent) mergeCurrentRows(data.rows || []);
                else bulk.currentRows = data.rows || [];
                patchBulkPriceUi();
            }).catch(function () {
                if (seq !== bulkPriceSeq) return;
                bulk.pricesLoading = false;
                if (!options.keepCurrent) bulk.currentRows = [];
                patchBulkPriceUi();
            });
        }
        if (canAutoPreview()) {
            json(CFG.previewPrice, { method: 'POST', body: bulkPricePayload() }).then(function (data) {
                if (seq !== bulkPriceSeq) return;
                bulk.preview = data;
                patchBulkPriceUi();
            }).catch(function () {
                if (seq !== bulkPriceSeq) return;
                bulk.preview = null;
                patchBulkPriceUi();
            });
        }
    }

    function canAutoPreview() {
        if (!selectedIds(bulk.selectedProducts).length) return false;
        if (!selectedIds(bulk.selectedBranches).length) return false;
        if (!selectedChannels(bulk.channels).length) return false;
        if (usesPerProductPrices()) {
            return Object.keys(productValuesPayload()).length > 0 || variationValuesPayload().length > 0;
        }
        if (bulk.advanced && bulk.perChannel) {
            return selectedChannels(bulk.channels).some(function (ch) {
                var op = ensureChannelOp(ch);
                return op.action === 'set_exact' ? Number(op.value) > 0 : actionNeedsValue(op.action) ? String(op.value) !== '' : true;
            });
        }
        if (bulk.action === 'set_exact' || !bulk.advanced) return Number(bulk.value) > 0;
        return actionNeedsValue(bulk.action) ? String(bulk.value) !== '' : true;
    }

    function loadBulkProducts() {
        var seq = ++bulkProductSeq;
        invalidateBulkPrices();
        var list = document.getElementById('bulk-product-list');
        if (list) list.innerHTML = '<div class="text-muted">Loading products…</div>';
        var panel = document.getElementById('bulk-preview-panel');
        if (panel) panel.innerHTML = '<p class="text-muted mb-0">Loading current selling prices…</p>';
        var url = CFG.products + '?search=' + encodeURIComponent(bulk.productQuery || '') +
            '&category_id=' + encodeURIComponent(bulk.categoryId || '') +
            '&t=' + Date.now();
        json(url).then(function (data) {
            if (seq !== bulkProductSeq) return;
            bulk.products = data.data || [];
            pruneInvisibleProductSelections();
            renderBulk();
            refreshBulkPrices();
        }).catch(function () {
            if (seq !== bulkProductSeq) return;
            bulk.products = [];
            bulk.pricesLoading = false;
            renderBulk();
        });
    }

    function debounce(fn, wait) {
        var t;
        return function () {
            var args = arguments;
            var ctx = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, wait);
        };
    }

    var schedulePriceRefresh = debounce(function () {
        refreshBulkPrices({ keepCurrent: true });
    }, 150);
})();
