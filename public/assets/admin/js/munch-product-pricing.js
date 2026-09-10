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
        applyPrice: root.getAttribute('data-apply-price-url'),
        previewAvail: root.getAttribute('data-preview-avail-url'),
        applyAvail: root.getAttribute('data-apply-avail-url'),
        copyPreview: root.getAttribute('data-copy-preview-url'),
        copyApply: root.getAttribute('data-copy-apply-url'),
        currency: root.getAttribute('data-currency') || ''
    };

    var CHANNELS = ['pos', 'uber', 'glovo', 'bolt_food'];
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
        action: 'increase_percent',
        value: 10,
        channelOps: {
            pos: { action: 'increase_percent', value: 10 },
            uber: { action: 'increase_percent', value: 10 },
            glovo: { action: 'increase_percent', value: 10 },
            bolt_food: { action: 'increase_percent', value: 10 }
        },
        availChannels: { pos: false, uber: true, glovo: true, bolt_food: true },
        availEnabled: false,
        preview: null
    };
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
        return fetch(url, Object.assign({ credentials: 'same-origin', headers: headers }, options))
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok) throw data;
                    return data;
                });
            });
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
            '&channel=' + encodeURIComponent(drawer.channel || '');
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
        return option('increase_percent', 'Increase %', selected) +
            option('decrease_percent', 'Decrease %', selected) +
            option('increase_amount', 'Increase amount', selected) +
            option('decrease_amount', 'Decrease amount', selected) +
            option('set_exact', 'Set exact price', selected) +
            option('round_5', 'Round to nearest 5', selected) +
            option('round_10', 'Round to nearest 10', selected);
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
                escapeHtml(p.name) + ' <span class="text-muted">' + money(p.selling_price != null ? p.selling_price : p.price) + '</span></label>';
        }).join('');
        var branchChecks = (meta.branches || []).map(function (b) {
            return '<label><input type="checkbox" data-bulk-branch="' + b.id + '"' + (bulk.selectedBranches[b.id] ? ' checked' : '') + '> ' +
                escapeHtml(b.name) + '</label>';
        }).join('');
        var categoryOptions = '<option value="">All categories</option>' + (meta.categories || []).map(function (c) {
            return '<option value="' + c.id + '"' + (String(bulk.categoryId) === String(c.id) ? ' selected' : '') + '>' + escapeHtml(c.name) + '</option>';
        }).join('');
        var needsValue = actionNeedsValue(bulk.action);
        var previewHtml = renderPreview(bulk.preview);
        var selected = selectedChannels(bulk.channels);
        var priceControls = '<label class="munch-pricing-field-label">Channels</label>' +
            channelChipRow('channels', bulk.channels) +
            '<label class="munch-pricing-advanced-toggle">' +
            '<input type="checkbox" id="bulk-advanced"' + (bulk.advanced ? ' checked' : '') + '> Advanced: different action per channel</label>';
        if (bulk.advanced) {
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
            priceControls += '<div class="row g-2 mt-2"><div class="col-md-6"><label>Action</label>' +
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
            '<div class="munch-pricing-card mt-3"><h3>Preview Changes</h3>' + previewHtml + '</div>' +
            '<div class="munch-pricing-modal__footer px-0">' +
            '<span class="text-muted">Nothing is saved until you apply the preview.</span>' +
            '<div class="d-flex gap-2">' +
            '<button type="button" class="btn btn-outline-primary" id="bulk-preview">Preview Changes</button>' +
            '<button type="button" class="btn btn-primary" id="bulk-apply" ' + (bulk.preview && bulk.preview.count ? '' : 'disabled') + '>Apply</button>' +
            '</div></div>';
    }

    function option(value, label, selected) {
        return '<option value="' + value + '"' + (selected === value ? ' selected' : '') + '>' + label + '</option>';
    }

    function renderPreview(preview) {
        if (!preview) return '<p class="text-muted mb-0">Preview current vs new values before saving.</p>';
        if (!preview.rows || !preview.rows.length) return '<p class="text-muted mb-0">No changes for this selection.</p>';
        var isPrice = preview.rows[0].current_price !== undefined;
        var groups = [];
        var index = {};
        preview.rows.forEach(function (row) {
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
                    var cls = row.difference > 0 ? 'is-up' : (row.difference < 0 ? 'is-down' : '');
                    return '<div class="munch-pricing-preview-line">' +
                        '<span class="munch-pricing-preview-channel">' + escapeHtml(CHANNEL_LABELS[row.channel] || row.channel) + '</span>' +
                        '<span class="' + cls + '">' + money(row.current_price) + ' → ' + money(row.new_price) + '</span></div>';
                }
                return '<div class="munch-pricing-preview-line">' +
                    '<span class="munch-pricing-preview-channel">' + escapeHtml(CHANNEL_LABELS[row.channel] || row.channel) + '</span>' +
                    '<span>' + (row.current_available ? 'On' : 'Off') + ' → ' + (row.new_available ? 'On' : 'Off') + '</span></div>';
            }).join('');
            return '<article class="munch-pricing-preview-group"><header>' + title + '</header>' + lines + '</article>';
        }).join('');
        return '<p class="mb-2">' + preview.count + ' change' + (preview.count === 1 ? '' : 's') +
            (preview.truncated ? ' (showing first ' + preview.rows.length + ')' : '') + '</p>' +
            '<div class="munch-pricing-preview-groups">' + body + '</div>';
    }

    function selectedIds(map) {
        return Object.keys(map).filter(function (id) { return map[id]; }).map(Number);
    }

    function bulkPricePayload() {
        var channels = selectedChannels(bulk.channels);
        var body = {
            product_ids: selectedIds(bulk.selectedProducts),
            branch_ids: selectedIds(bulk.selectedBranches)
        };
        if (bulk.advanced) {
            body.operations = channels.map(function (ch) {
                var op = ensureChannelOp(ch);
                return { channel: ch, action: op.action, value: Number(op.value || 0) };
            });
        } else {
            body.channels = channels;
            body.action = bulk.action;
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
            if (!body.product_ids.length) return showToast(false, 'Choose products first');
            if (!(body.channels || []).length && !(body.operations || []).length) return showToast(false, 'Choose at least one channel');
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
        json(url, { method: 'POST', body: body }).then(function (data) {
            bulk.preview = data;
            renderBulk();
        }).catch(function (err) {
            showToast(false, (err && err.message) || 'Preview failed');
        });
    }

    function runApply() {
        if (!bulk.preview || !bulk.preview.count) return;
        var body;
        var url;
        if (bulk.tab === 'price') {
            body = Object.assign({ confirmed: true }, bulkPricePayload());
            url = CFG.applyPrice;
        } else {
            body = {
                product_ids: selectedIds(bulk.selectedProducts),
                branch_ids: selectedIds(bulk.selectedBranches),
                channels: selectedChannels(bulk.availChannels),
                enabled: !!bulk.availEnabled,
                confirmed: true
            };
            url = CFG.applyAvail;
        }
        json(url, { method: 'POST', body: body }).then(function (data) {
            showToast(true, data.message || 'Updated');
            bulk.preview = null;
            closeAll();
        }).catch(function (err) {
            showToast(false, (err && err.message) || 'Apply failed');
        });
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
        var opValue = ev.target.getAttribute && ev.target.getAttribute('data-bulk-op-value');
        if (opValue) ensureChannelOp(opValue).value = ev.target.value;
    });
    els.modal.addEventListener('change', function (ev) {
        if (ev.target.id === 'bulk-category') {
            bulk.categoryId = ev.target.value;
            bulk.preview = null;
            loadBulkProducts();
            return;
        }
        if (ev.target.id === 'bulk-action') {
            bulk.action = ev.target.value;
            bulk.preview = null;
            renderBulk();
            return;
        }
        if (ev.target.id === 'bulk-advanced') {
            bulk.advanced = ev.target.checked;
            if (bulk.advanced) {
                selectedChannels(bulk.channels).forEach(function (ch) {
                    bulk.channelOps[ch] = { action: bulk.action, value: bulk.value };
                });
            }
            bulk.preview = null;
            renderBulk();
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
            renderBulk();
            return;
        }
        var channelMap = ev.target.getAttribute && ev.target.getAttribute('data-bulk-channel-map');
        if (channelMap && ev.target.getAttribute('data-channel')) {
            bulk[channelMap][ev.target.getAttribute('data-channel')] = ev.target.checked;
            bulk.preview = null;
            renderBulk();
            return;
        }
        if (ev.target.matches('[data-bulk-product]')) bulk.selectedProducts[ev.target.getAttribute('data-bulk-product')] = ev.target.checked;
        if (ev.target.matches('[data-bulk-branch]')) bulk.selectedBranches[ev.target.getAttribute('data-bulk-branch')] = ev.target.checked;
        bulk.preview = null;
    });
    els.modal.addEventListener('click', function (ev) {
        var tab = ev.target.closest('[data-tab]');
        if (tab) {
            bulk.tab = tab.getAttribute('data-tab');
            bulk.preview = null;
            renderBulk();
            return;
        }
        var setBtn = ev.target.closest('[data-bulk-channels]');
        if (setBtn) {
            var mapName = setBtn.getAttribute('data-bulk-channels');
            applyChannelSet(bulk[mapName], setBtn.getAttribute('data-set'));
            bulk.preview = null;
            renderBulk();
            return;
        }
        if (ev.target.id === 'bulk-preview') runPreview();
        if (ev.target.id === 'bulk-apply') runApply();
        if (ev.target.id === 'bulk-select-visible') {
            bulk.products.forEach(function (p) { bulk.selectedProducts[p.id] = true; });
            renderBulk();
        }
        if (ev.target.id === 'bulk-select-branches') {
            (metaCache.branches || []).forEach(function (b) { bulk.selectedBranches[b.id] = true; });
            renderBulk();
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
    els.modal.addEventListener('keyup', debounce(function (ev) {
        if (ev.target.id === 'bulk-product-search') loadBulkProducts();
    }, 250));

    function loadBulkProducts() {
        var url = CFG.products + '?search=' + encodeURIComponent(bulk.productQuery || '') + '&category_id=' + encodeURIComponent(bulk.categoryId || '');
        json(url).then(function (data) {
            bulk.products = data.data || [];
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
})();
