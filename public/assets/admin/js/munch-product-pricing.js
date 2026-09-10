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
        channel: 'pos',
        action: 'increase_percent',
        availAction: 'enable_pos',
        value: 0,
        preview: null
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
        modalBody: document.getElementById('munch-pricing-modal-body')
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
        els.drawer.hidden = true;
        els.modal.hidden = true;
        els.backdrop.hidden = true;
        document.body.classList.remove('munch-pricing-open');
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
            if (drawer.defaultPrice === null || Number(els.defaultInput.value) === Number(drawer.originalDefault)) {
                drawer.defaultPrice = payload.product.default_price;
            }
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
                    value = ch === 'pos' ? drawer.defaultPrice : ((branch.cells.pos || {}).price || drawer.defaultPrice);
                }
                html += '<div class="munch-pricing-grid__cell">' +
                    '<input class="munch-pricing-input' + (isOverride ? ' is-override' : '') + '" type="number" min="0" step="0.01" data-price="' + branch.id + '" data-channel="' + ch + '" value="' + escapeAttr(value) + '">' +
                    (isOverride
                        ? '<button type="button" class="munch-pricing-reset" data-reset="' + branch.id + '" data-channel="' + ch + '">Reset to Default</button>'
                        : '<span class="munch-pricing-inherited">Uses ' + escapeHtml(inheritLabel(cell.inherited_from)) + '</span>') +
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

    function inheritLabel(from) {
        if (from === 'pos') return 'POS price';
        if (from === 'default') return 'default price';
        return 'override';
    }

    function updateDirtyCount() {
        var count = Object.keys(drawer.dirty).length;
        if (drawer.defaultPrice !== null && drawer.originalDefault !== null && Number(drawer.defaultPrice) !== Number(drawer.originalDefault)) {
            count += 1;
        }
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
                default_price: Number(drawer.defaultPrice),
                changes: collectChanges()
            }
        }).then(function (data) {
            showToast(true, data.message || 'Pricing updated');
            drawer.dirty = {};
            drawer.payload = data.payload;
            drawer.originalDefault = data.payload.product.default_price;
            drawer.defaultPrice = data.payload.product.default_price;
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

    function renderBulk() {
        var meta = metaCache || { branches: [], categories: [] };
        var productChecks = bulk.products.map(function (p) {
            return '<label><input type="checkbox" data-bulk-product="' + p.id + '"' + (bulk.selectedProducts[p.id] ? ' checked' : '') + '> ' +
                escapeHtml(p.name) + ' <span class="text-muted">' + money(p.price) + '</span></label>';
        }).join('');
        var branchChecks = (meta.branches || []).map(function (b) {
            return '<label><input type="checkbox" data-bulk-branch="' + b.id + '"' + (bulk.selectedBranches[b.id] ? ' checked' : '') + '> ' +
                escapeHtml(b.name) + '</label>';
        }).join('');
        var categoryOptions = '<option value="">All categories</option>' + (meta.categories || []).map(function (c) {
            return '<option value="' + c.id + '"' + (String(bulk.categoryId) === String(c.id) ? ' selected' : '') + '>' + escapeHtml(c.name) + '</option>';
        }).join('');
        var needsValue = bulk.action !== 'round_5' && bulk.action !== 'round_10';
        var previewHtml = renderPreview(bulk.preview);

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
            (bulk.tab === 'price'
                ? '<div class="row g-2"><div class="col-md-4"><label>Channel</label><select class="custom-select" id="bulk-channel">' +
                    option('default', 'Default', bulk.channel) + option('pos', 'POS', bulk.channel) + option('uber', 'Uber', bulk.channel) +
                    option('glovo', 'Glovo', bulk.channel) + option('bolt_food', 'Bolt Food', bulk.channel) +
                    '</select></div><div class="col-md-4"><label>Action</label><select class="custom-select" id="bulk-action">' +
                    option('increase_percent', 'Increase %', bulk.action) +
                    option('decrease_percent', 'Decrease %', bulk.action) +
                    option('increase_amount', 'Increase amount', bulk.action) +
                    option('decrease_amount', 'Decrease amount', bulk.action) +
                    option('set_exact', 'Set exact price', bulk.action) +
                    option('round_5', 'Round to nearest 5', bulk.action) +
                    option('round_10', 'Round to nearest 10', bulk.action) +
                    '</select></div><div class="col-md-4"' + (needsValue ? '' : ' hidden') + '><label>Value</label>' +
                    '<input class="form-control" id="bulk-value" type="number" step="0.01" value="' + escapeAttr(bulk.value) + '"></div></div>'
                : '<label>Action</label><select class="custom-select" id="bulk-avail-action">' +
                    option('enable_pos', 'Enable POS', bulk.availAction) +
                    option('disable_pos', 'Disable POS', bulk.availAction) +
                    option('enable_uber', 'Enable Uber', bulk.availAction) +
                    option('disable_uber', 'Disable Uber', bulk.availAction) +
                    option('enable_glovo', 'Enable Glovo', bulk.availAction) +
                    option('disable_glovo', 'Disable Glovo', bulk.availAction) +
                    option('enable_bolt_food', 'Enable Bolt Food', bulk.availAction) +
                    option('disable_bolt_food', 'Disable Bolt Food', bulk.availAction) +
                    '</select>') +
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
        var head = isPrice
            ? '<tr><th>Product</th><th>Branch</th><th>Current Price</th><th>New Price</th><th>Difference</th></tr>'
            : '<tr><th>Product</th><th>Branch</th><th>Channel</th><th>Current</th><th>New</th></tr>';
        var body = preview.rows.slice(0, 200).map(function (row) {
            if (isPrice) {
                var cls = row.difference > 0 ? 'is-up' : (row.difference < 0 ? 'is-down' : '');
                return '<tr><td>' + escapeHtml(row.product_name) + '</td><td>' + escapeHtml(row.branch_name) + '</td><td>' +
                    money(row.current_price) + '</td><td>' + money(row.new_price) + '</td><td class="' + cls + '">' +
                    (row.difference > 0 ? '+' : '') + money(row.difference) + '</td></tr>';
            }
            return '<tr><td>' + escapeHtml(row.product_name) + '</td><td>' + escapeHtml(row.branch_name) + '</td><td>' +
                escapeHtml(CHANNEL_LABELS[row.channel] || row.channel) + '</td><td>' + (row.current_available ? 'On' : 'Off') +
                '</td><td>' + (row.new_available ? 'On' : 'Off') + '</td></tr>';
        }).join('');
        return '<p class="mb-2">' + preview.count + ' change' + (preview.count === 1 ? '' : 's') +
            (preview.truncated ? ' (showing first ' + preview.rows.length + ')' : '') + '</p>' +
            '<div class="table-responsive"><table class="munch-pricing-preview"><thead>' + head + '</thead><tbody>' + body + '</tbody></table></div>';
    }

    function selectedIds(map) {
        return Object.keys(map).filter(function (id) { return map[id]; }).map(Number);
    }

    function runPreview() {
        var body = {
            product_ids: selectedIds(bulk.selectedProducts),
            branch_ids: selectedIds(bulk.selectedBranches)
        };
        if (!body.product_ids.length) return showToast(false, 'Choose products first');
        if (bulk.tab === 'price' && bulk.channel !== 'default' && !body.branch_ids.length) return showToast(false, 'Choose branches first');
        var url = bulk.tab === 'price' ? CFG.previewPrice : CFG.previewAvail;
        if (bulk.tab === 'price') {
            body.channel = bulk.channel;
            body.action = bulk.action;
            body.value = Number(bulk.value || 0);
        } else {
            body.action = bulk.availAction;
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
        var body = {
            product_ids: selectedIds(bulk.selectedProducts),
            branch_ids: selectedIds(bulk.selectedBranches),
            confirmed: true
        };
        var url = bulk.tab === 'price' ? CFG.applyPrice : CFG.applyAvail;
        if (bulk.tab === 'price') {
            body.channel = bulk.channel;
            body.action = bulk.action;
            body.value = Number(bulk.value || 0);
        } else {
            body.action = bulk.availAction;
        }
        json(url, { method: 'POST', body: body }).then(function (data) {
            showToast(true, data.message || 'Updated');
            bulk.preview = null;
            closeAll();
        }).catch(function (err) {
            showToast(false, (err && err.message) || 'Apply failed');
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

    els.backdrop.addEventListener('click', closeAll);
    els.save.addEventListener('click', saveDrawer);
    els.defaultInput.addEventListener('input', function () {
        drawer.defaultPrice = Number(els.defaultInput.value || 0);
        updateDirtyCount();
    });
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
        if (ev.key === 'Escape' && !els.drawer.hidden) closeAll();
    });

    els.modal.addEventListener('input', function (ev) {
        if (ev.target.id === 'bulk-product-search') bulk.productQuery = ev.target.value;
        if (ev.target.id === 'bulk-value') bulk.value = ev.target.value;
    });
    els.modal.addEventListener('change', function (ev) {
        if (ev.target.id === 'bulk-category') {
            bulk.categoryId = ev.target.value;
            loadBulkProducts();
        }
        if (ev.target.id === 'bulk-channel') bulk.channel = ev.target.value;
        if (ev.target.id === 'bulk-action') {
            bulk.action = ev.target.value;
            renderBulk();
        }
        if (ev.target.id === 'bulk-avail-action') bulk.availAction = ev.target.value;
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
