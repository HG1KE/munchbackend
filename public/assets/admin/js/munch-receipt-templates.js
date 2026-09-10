(function () {
    'use strict';

    var root = document.getElementById('munch-receipt-root');
    if (!root || !window.MunchReceiptTicket) return;

    var CFG = window.MUNCH_RECEIPT_EDITOR || {};
    var state = JSON.parse(JSON.stringify(CFG.payload || {}));
    state.kind = 'customer';
    state.uiTab = 'customer';

    var qrCache = {};
    var qrTimer = null;
    var dragging = null;
    var lastDropIndex = -1;

    var els = {
        scope: document.getElementById('receipt-scope'),
        modeCompany: document.getElementById('receipt-mode-company'),
        modeCustom: document.getElementById('receipt-mode-custom'),
        modeWrap: document.getElementById('receipt-mode-wrap'),
        editor: document.getElementById('receipt-editor'),
        preview: document.getElementById('receipt-preview-frame'),
        paper: document.getElementById('receipt-paper'),
        previewTitle: document.getElementById('receipt-preview-title'),
        save: document.getElementById('receipt-save'),
        testReceipt: document.getElementById('receipt-test-print'),
        testKitchen: document.getElementById('receipt-test-kitchen'),
        resetCompany: document.getElementById('receipt-reset-company'),
        resetBranch: document.getElementById('receipt-reset-branch'),
        resetSection: document.getElementById('receipt-reset-section'),
        printFrame: document.getElementById('receipt-print-frame'),
        hint: document.getElementById('receipt-lock-hint')
    };

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return (CFG.csrf || (meta && meta.getAttribute('content')) || '');
    }

    function toast(ok, message) {
        if (window.toastr) {
            window.toastr[ok ? 'success' : 'error'](message);
            return;
        }
        window.alert(message);
    }

    function currentKind() {
        return state.kind === 'kitchen' ? 'kitchen' : 'customer';
    }

    function currentTemplate() {
        return state[currentKind()];
    }

    function locked() {
        if (state.scope === 'company') return !state.can_edit_company;
        return !!state.use_company;
    }

    function json(url, options) {
        options = options || {};
        var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() };
        if (options.body && !(options.body instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }
        return fetch(url, Object.assign({ credentials: 'same-origin', headers: headers }, options))
            .then(function (res) {
                return res.text().then(function (text) {
                    var data = {};
                    if (text) {
                        try { data = JSON.parse(text); }
                        catch (e) { throw { message: 'Request failed (' + res.status + ')' }; }
                    }
                    if (!res.ok) throw data && data.message ? data : { message: 'Request failed (' + res.status + ')' };
                    return data;
                });
            });
    }

    function applyPayload(payload) {
        var kind = state.kind;
        var uiTab = state.uiTab;
        state = JSON.parse(JSON.stringify(payload || {}));
        state.kind = kind;
        state.uiTab = uiTab;
        render();
    }

    function sampleJob() {
        var job = JSON.parse(JSON.stringify(state.sample_job || {}));
        var previewAs = document.getElementById('receipt-preview-channel');
        if (previewAs && previewAs.value) {
            job.salesChannel = previewAs.value;
            var labels = window.MunchReceiptTicket.CHANNEL_LABELS || {};
            job.orderType = labels[previewAs.value] || previewAs.value;
            job.isDelivery = previewAs.value === 'delivery' || previewAs.value === 'glovo' || previewAs.value === 'uber' || previewAs.value === 'bolt_food';
        }
        return job;
    }

    function qrUrl(tmpl) {
        var qr = (tmpl && tmpl.qr) || {};
        if (qr.url) return String(qr.url).trim();
        if ((qr.type || 'website') === 'website') return String((state.context && state.context.website) || '').trim();
        return '';
    }

    function previewHtml(kind) {
        return window.MunchReceiptTicket.renderDocument(kind || currentKind(), currentTemplate(), sampleJob(), {
            context: state.context || {},
            print: state.print || { paper: '80mm' },
            currency: CFG.currency || ''
        });
    }

    function refreshPreview() {
        var html = previewHtml();
        if (els.preview) els.preview.srcdoc = html;
        if (els.paper) els.paper.classList.toggle('is-58', (state.print && state.print.paper) === '58mm');
        if (els.previewTitle) {
            var paper = (state.print && state.print.paper) || '80mm';
            els.previewTitle.textContent = 'Live ' + paper + ' preview';
        }
    }

    function schedulePreview() {
        var tmpl = currentTemplate();
        if (currentKind() !== 'kitchen' && tmpl.sections && tmpl.sections.footer && tmpl.sections.footer.qr_code) {
            var url = qrUrl(tmpl);
            var size = (tmpl.qr && tmpl.qr.size) || 'medium';
            var key = url + '|' + size;
            if (url && qrCache[key]) {
                tmpl.qr_data_uri = qrCache[key];
                tmpl.qr_payload = url;
            } else if (url && (!tmpl.qr_data_uri || tmpl.qr_payload !== url)) {
                tmpl.qr_payload = url;
                if (CFG.urls && CFG.urls.qr) {
                    clearTimeout(qrTimer);
                    qrTimer = setTimeout(function () {
                        json(CFG.urls.qr + '?url=' + encodeURIComponent(url) + '&size=' + encodeURIComponent(size))
                            .then(function (res) {
                                if (!res.data_uri) return;
                                qrCache[key] = res.data_uri;
                                var live = currentTemplate();
                                live.qr_data_uri = res.data_uri;
                                live.qr_payload = url;
                                refreshPreview();
                            })
                            .catch(function () {});
                    }, 250);
                }
            }
        }
        refreshPreview();
    }

    function printKind(kind) {
        var html = previewHtml(kind);
        var copies = window.MunchReceiptTicket.copies(kind, state.print);
        var frame = els.printFrame;
        if (!frame) {
            window.alert('Print frame missing');
            return;
        }
        var remaining = copies;
        function next() {
            if (remaining < 1) return;
            remaining -= 1;
            frame.onload = function () {
                try {
                    frame.contentWindow.focus();
                    frame.contentWindow.print();
                } catch (err) {}
                if (remaining > 0) setTimeout(next, 400);
            };
            frame.srcdoc = html;
        }
        next();
    }

    function setFlag(group, key, value) {
        var tmpl = currentTemplate();
        if (!tmpl.sections) tmpl.sections = {};
        if (!tmpl.sections[group]) tmpl.sections[group] = {};
        tmpl.sections[group][key] = !!value;
        schedulePreview();
    }

    function catalog() {
        return (state.section_catalog && state.section_catalog[currentKind()]) || {};
    }

    function blockCatalog() {
        var list = (state.block_catalog && state.block_catalog[currentKind()]) || [];
        if (list.length) return list;
        var ids = currentKind() === 'kitchen'
            ? (window.MunchReceiptTicket.KITCHEN_BLOCKS || [])
            : (window.MunchReceiptTicket.CUSTOMER_BLOCKS || []);
        return ids.map(function (id) { return { id: id, label: id }; });
    }

    function ensureOrder() {
        var tmpl = currentTemplate();
        tmpl.order = window.MunchReceiptTicket.normalizeTemplate(currentKind(), tmpl).order;
        return tmpl.order;
    }

    function ensureStyle(id) {
        var tmpl = currentTemplate();
        if (!tmpl.block_styles) tmpl.block_styles = {};
        if (!tmpl.block_styles[id]) {
            tmpl.block_styles[id] = {
                font_size: 'normal', bold: false, align: 'left',
                divider_before: false, divider_after: false, margin_top: false, margin_bottom: false
            };
        }
        return tmpl.block_styles[id];
    }

    function radioRow(name, value, options) {
        return options.map(function (opt) {
            var checked = String(value) === String(opt.value) ? ' checked' : '';
            return '<label><input type="radio" name="' + name + '" value="' + opt.value + '"' + checked + '> ' + opt.label + '</label>';
        }).join('');
    }

    function fieldsFor(blockId) {
        var groups = catalog();
        var map = {
            logo: (groups.header || []).filter(function (f) { return f.key === 'logo'; }),
            branch_name: (groups.header || []).filter(function (f) { return f.key === 'branch_name'; }),
            receipt_title: (groups.header || []).filter(function (f) { return f.key === 'receipt_title'; }),
            branch_details: (groups.header || []).filter(function (f) { return ['branch_address', 'branch_phone', 'tax_pin'].indexOf(f.key) !== -1; }),
            order_type: (groups.order || []).filter(function (f) { return f.key === 'order_type' || f.key === 'sales_channel'; }),
            order_number: (groups.order || []).filter(function (f) { return ['order_number', 'date', 'time', 'cashier'].indexOf(f.key) !== -1; }),
            customer: (groups.order || []).filter(function (f) { return ['customer_name', 'customer_phone', 'delivery_address', 'rider_name', 'rider_phone'].indexOf(f.key) !== -1; }),
            items: groups.items || [],
            totals: groups.summary || [],
            payment: (groups.payment || []).filter(function (f) { return f.key !== 'mpesa_till'; }),
            mpesa_till: (groups.payment || []).filter(function (f) { return f.key === 'mpesa_till'; }),
            promotion: groups.marketing || [],
            qr_code: (groups.footer || []).filter(function (f) { return f.key === 'qr_code'; }),
            barcode: (groups.footer || []).filter(function (f) { return f.key === 'barcode'; }),
            footer: (groups.footer || []).filter(function (f) { return ['thank_you_message', 'footer_text', 'return_policy', 'social_media'].indexOf(f.key) !== -1; })
        };
        return map[blockId] || [];
    }

    function fieldGroup(key) {
        var groups = catalog();
        var found = 'order';
        Object.keys(groups).forEach(function (group) {
            (groups[group] || []).forEach(function (field) {
                if (field.key === key) found = group;
            });
        });
        return found;
    }

    function typographyHtml(id) {
        var st = ensureStyle(id);
        return '<div class="munch-receipt-type">' +
            '<p class="font-weight-bold mb-1">Font size</p><div class="munch-receipt-checks">' +
            radioRow('fs-' + id, st.font_size, [
                { value: 'small', label: 'Small' },
                { value: 'normal', label: 'Normal' },
                { value: 'large', label: 'Large' },
                { value: 'extra_large', label: 'Extra Large' }
            ]) + '</div>' +
            '<div class="munch-receipt-checks mt-2">' +
            '<label><input type="checkbox" data-style-bool="bold" data-block="' + id + '"' + (st.bold ? ' checked' : '') + '> Bold</label>' +
            '<label><input type="checkbox" data-style-align="center" data-block="' + id + '"' + (st.align === 'center' ? ' checked' : '') + '> Center</label>' +
            '<label><input type="checkbox" data-style-align="right" data-block="' + id + '"' + (st.align === 'right' ? ' checked' : '') + '> Right Align</label>' +
            '<label><input type="checkbox" data-style-bool="divider_before" data-block="' + id + '"' + (st.divider_before ? ' checked' : '') + '> Divider Before</label>' +
            '<label><input type="checkbox" data-style-bool="divider_after" data-block="' + id + '"' + (st.divider_after ? ' checked' : '') + '> Divider After</label>' +
            '<label><input type="checkbox" data-style-bool="margin_top" data-block="' + id + '"' + (st.margin_top ? ' checked' : '') + '> Extra Top Margin</label>' +
            '<label><input type="checkbox" data-style-bool="margin_bottom" data-block="' + id + '"' + (st.margin_bottom ? ' checked' : '') + '> Extra Bottom Margin</label>' +
            '</div></div>';
    }

    function extraHtml(id, tmpl) {
        var html = '';
        if (id === 'logo') {
            html += '<div class="mt-3"><p class="font-weight-bold mb-1">Logo size</p><div class="munch-receipt-checks">' +
                radioRow('logo-size', (tmpl.logo && tmpl.logo.size) || 'medium', [
                    { value: 'hide', label: 'Hide Logo' },
                    { value: 'small', label: 'Small' },
                    { value: 'medium', label: 'Medium' },
                    { value: 'large', label: 'Large' }
                ]) + '</div></div>';
            html += '<div class="munch-receipt-checks mt-2">' +
                '<label><input type="checkbox" data-logo-thermal="1"' + ((tmpl.logo && tmpl.logo.optimize_thermal) ? ' checked' : '') + '> Optimize Logo For Thermal Printing</label></div>';
            html += '<div class="mt-3"><label class="font-weight-bold">Logo source</label>' +
                '<div class="munch-receipt-checks">' +
                radioRow('logo-mode', (tmpl.logo && tmpl.logo.mode) || 'none', [
                    { value: 'none', label: 'No logo' },
                    { value: 'company', label: 'Use company logo' },
                    { value: 'upload', label: 'Upload logo' }
                ]) + '</div>' +
                '<input class="form-control mt-2" id="receipt-logo-file" type="file" accept="image/*">' +
                (tmpl.logo_url ? '<img class="munch-receipt-logo-preview" src="' + escapeAttr((tmpl.logo && tmpl.logo.optimize_thermal && tmpl.logo_thermal_url) ? tmpl.logo_thermal_url : tmpl.logo_url) + '" alt="">' : '') +
                '</div>';
        }
        if (id === 'receipt_title') {
            html += '<div class="mt-3"><label class="font-weight-bold">Receipt Title</label>' +
                '<input class="form-control" data-text="receipt_title" value="' + escapeAttr((tmpl.texts || {}).receipt_title) + '"></div>';
            if (currentKind() === 'customer') {
                html += '<div class="mt-3"><label class="font-weight-bold">Tax PIN</label>' +
                    '<input class="form-control" data-text="tax_pin" value="' + escapeAttr((tmpl.texts || {}).tax_pin) + '"></div>';
            }
        }
        if (id === 'qr_code') {
            var qr = tmpl.qr || { type: 'website', url: '', size: 'medium' };
            html += '<div class="mt-3"><p class="font-weight-bold mb-1">QR type</p><div class="munch-receipt-checks">' +
                radioRow('qr-type', qr.type || 'website', [
                    { value: 'website', label: 'Website' },
                    { value: 'google_reviews', label: 'Google Reviews' },
                    { value: 'menu', label: 'Menu' },
                    { value: 'order_tracking', label: 'Order Tracking' },
                    { value: 'custom', label: 'Custom URL' }
                ]) + '</div></div>';
            html += '<div class="mt-3"><label class="font-weight-bold">URL</label>' +
                '<input class="form-control" data-qr-url="1" value="' + escapeAttr(qr.url || '') + '" placeholder="https://"></div>';
            html += '<div class="mt-3"><p class="font-weight-bold mb-1">QR size</p><div class="munch-receipt-checks">' +
                radioRow('qr-size', qr.size || 'medium', [
                    { value: 'small', label: 'Small' },
                    { value: 'medium', label: 'Medium' },
                    { value: 'large', label: 'Large' }
                ]) + '</div></div>';
        }
        if (id === 'footer') {
            if (currentKind() === 'customer') {
                html += '<div class="mt-3"><label class="font-weight-bold">Thank You Message</label>' +
                    '<input class="form-control" data-text="thank_you_message" value="' + escapeAttr((tmpl.texts || {}).thank_you_message) + '"></div>';
            }
            html += '<div class="mt-3"><label class="font-weight-bold">Footer Text</label>' +
                '<textarea class="form-control" rows="6" data-text="footer_text">' + escapeHtml((tmpl.texts || {}).footer_text) + '</textarea>' +
                '<p class="munch-receipt-hint mt-1 mb-0">Placeholders: ' + ((state.placeholders || []).join(' ')) + '</p></div>';
            if (currentKind() === 'customer') {
                html += '<div class="mt-3"><label class="font-weight-bold">Return Policy</label>' +
                    '<textarea class="form-control" rows="3" data-text="return_policy">' + escapeHtml((tmpl.texts || {}).return_policy) + '</textarea></div>';
            }
        }
        if (id === 'promotion') {
            html += '<div class="mt-3"><label class="font-weight-bold">Promotion Banner</label>' +
                '<textarea class="form-control" rows="3" data-text="promotion_banner">' + escapeHtml((tmpl.texts || {}).promotion_banner) + '</textarea></div>';
        }
        return html;
    }

    function printerHtml() {
        var print = state.print || {};
        return '<div class="card mb-3 munch-receipt-card" data-section="print"><div class="card-body"><h3>Printer</h3>' +
            '<p class="font-weight-bold mb-1">Paper Width</p><div class="munch-receipt-checks">' +
            radioRow('paper', print.paper || '80mm', [
                { value: '58mm', label: '58mm' }, { value: '80mm', label: '80mm' }
            ]) + '</div>' +
            '<p class="font-weight-bold mb-1 mt-3">Print Mode</p><div class="munch-receipt-checks">' +
            radioRow('print-mode', print.print_mode || 'normal', [
                { value: 'normal', label: 'Normal' },
                { value: 'dark', label: 'Dark' },
                { value: 'extra_dark', label: 'Extra Dark' }
            ]) + '</div>' +
            '<p class="munch-receipt-hint mt-1">Dark modes increase font weight, contrast, and black density. Browser print cannot send ESC/POS density commands.</p>' +
            '<p class="font-weight-bold mb-1 mt-3">Paper Saving</p><div class="munch-receipt-checks">' +
            radioRow('paper-saving', print.paper_saving || 'off', [
                { value: 'off', label: 'Off' },
                { value: 'normal', label: 'Normal' },
                { value: 'maximum', label: 'Maximum' }
            ]) + '</div>' +
            '<div class="row mt-3"><div class="col-md-6"><label>Number of receipt copies</label>' +
            '<input class="form-control" type="number" min="1" max="5" data-print="receipt_copies" value="' + escapeAttr(print.receipt_copies) + '"></div>' +
            '<div class="col-md-6"><label>Number of kitchen ticket copies</label>' +
            '<input class="form-control" type="number" min="1" max="5" data-print="kitchen_copies" value="' + escapeAttr(print.kitchen_copies) + '"></div></div>' +
            '<div class="munch-receipt-checks mt-3">' +
            '<label><input type="checkbox" data-print-bool="auto_cut"' + (print.auto_cut ? ' checked' : '') + '> Auto Cut</label>' +
            '<label><input type="checkbox" data-print-bool="drawer_kick"' + (print.drawer_kick ? ' checked' : '') + '> Drawer Kick</label>' +
            '</div><p class="munch-receipt-hint mb-0 mt-2">Auto Cut and Drawer Kick are stored with the template. Browser print uses the printer driver for hardware commands.</p>' +
            '</div></div>' +
            '<div class="card mb-3 munch-receipt-card" data-section="style"><div class="card-body"><h3>Default fonts</h3>' +
            '<p class="font-weight-bold mb-1">Font Size</p><div class="munch-receipt-checks">' +
            radioRow('font-size', currentTemplate().style.font_size, [
                { value: 'small', label: 'Small' }, { value: 'medium', label: 'Medium' }, { value: 'large', label: 'Large' }
            ]) + '</div>' +
            '<p class="font-weight-bold mb-1 mt-3">Weight</p><div class="munch-receipt-checks">' +
            radioRow('font-weight', currentTemplate().style.font_weight, [
                { value: 'normal', label: 'Normal' }, { value: 'bold', label: 'Bold' }
            ]) + '</div>' +
            '<p class="font-weight-bold mb-1 mt-3">Section spacing</p><div class="munch-receipt-checks">' +
            radioRow('section-spacing', currentTemplate().style.section_spacing, [
                { value: 'compact', label: 'Compact' }, { value: 'normal', label: 'Normal' }, { value: 'wide', label: 'Wide' }
            ]) + '</div>' +
            '<p class="font-weight-bold mb-1 mt-3">Divider style</p><div class="munch-receipt-checks">' +
            radioRow('divider', currentTemplate().style.divider, [
                { value: 'solid', label: 'Solid' }, { value: 'dashed', label: 'Dashed' }, { value: 'none', label: 'None' }
            ]) + '</div></div></div>';
    }

    function renderEditor() {
        if (!els.editor) return;
        if (state.uiTab === 'printer') {
            els.editor.innerHTML = printerHtml();
            afterEditorRender();
            return;
        }
        var tmpl = currentTemplate();
        var labels = {};
        blockCatalog().forEach(function (item) { labels[item.id] = item.label; });
        var order = ensureOrder();
        var html = '<div class="munch-receipt-blocks">';
        order.forEach(function (id) {
            html += '<div class="card mb-3 munch-receipt-card munch-receipt-block" data-block-id="' + id + '" data-section="' + id + '" draggable="' + (locked() ? 'false' : 'true') + '">';
            html += '<div class="card-body">';
            html += '<div class="munch-receipt-block__head"><span class="munch-receipt-handle" aria-hidden="true">☰</span><h3>' + escapeHtml(labels[id] || id) + '</h3></div>';
            html += '<div class="munch-receipt-checks">';
            fieldsFor(id).forEach(function (field) {
                var group = fieldGroup(field.key);
                var checked = tmpl.sections && tmpl.sections[group] && tmpl.sections[group][field.key] ? ' checked' : '';
                html += '<label><input type="checkbox" data-section-group="' + group + '" data-section-key="' + field.key + '"' + checked + '> ' +
                    field.label + '</label>';
            });
            html += '</div>';
            html += extraHtml(id, tmpl);
            html += typographyHtml(id);
            html += '</div></div>';
        });
        html += '</div>';
        els.editor.innerHTML = html;
        afterEditorRender();
    }

    function afterEditorRender() {
        root.setAttribute('data-locked', locked() ? '1' : '0');
        if (els.hint) {
            els.hint.hidden = !locked();
            els.hint.textContent = state.scope === 'company'
                ? 'Only Master Admin can edit the company template.'
                : 'This branch uses the company template. Switch to Custom Branch Template to edit.';
        }
        if (els.modeWrap) els.modeWrap.hidden = state.scope !== 'branch';
        if (els.modeCompany) els.modeCompany.checked = !!state.use_company;
        if (els.modeCustom) els.modeCustom.checked = !state.use_company;
        if (els.resetBranch) els.resetBranch.hidden = state.scope !== 'branch';
    }

    function persistOrderFromDom() {
        var list = els.editor.querySelector('.munch-receipt-blocks');
        if (!list) return;
        var next = Array.prototype.map.call(list.querySelectorAll('[data-block-id]'), function (el) {
            return el.getAttribute('data-block-id');
        });
        if (next.length) currentTemplate().order = next;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    function escapeAttr(value) {
        return escapeHtml(value);
    }

    function render() {
        document.querySelectorAll('[data-receipt-tab]').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-receipt-tab') === state.uiTab);
        });
        renderEditor();
        schedulePreview();
    }

    function savePayload() {
        return {
            branch_id: state.branch_id || 0,
            use_company: state.scope === 'company' ? true : !!state.use_company,
            customer: state.customer,
            kitchen: state.kitchen,
            print: state.print
        };
    }

    if (els.scope) {
        els.scope.addEventListener('change', function () {
            var id = els.scope.value;
            var url = CFG.urls.payload + (id ? ('?branch_id=' + encodeURIComponent(id)) : '');
            json(url).then(function (res) {
                applyPayload(res.data);
                if (window.history && window.history.replaceState) {
                    var next = CFG.urls.index + (id ? ('?branch_id=' + encodeURIComponent(id)) : '');
                    window.history.replaceState({}, '', next);
                }
            }).catch(function (err) {
                toast(false, err.message || 'Could not load template');
            });
        });
    }

    root.addEventListener('click', function (ev) {
        var tab = ev.target.closest('[data-receipt-tab]');
        if (tab) {
            var name = tab.getAttribute('data-receipt-tab');
            state.uiTab = name;
            if (name === 'customer' || name === 'kitchen') state.kind = name;
            render();
        }
    });

    if (els.modeCompany) {
        els.modeCompany.addEventListener('change', function () {
            if (!els.modeCompany.checked) return;
            state.use_company = true;
            state.customer = JSON.parse(JSON.stringify(state.company.customer));
            state.kitchen = JSON.parse(JSON.stringify(state.company.kitchen));
            render();
        });
    }
    if (els.modeCustom) {
        els.modeCustom.addEventListener('change', function () {
            if (!els.modeCustom.checked) return;
            state.use_company = false;
            render();
        });
    }

    els.editor.addEventListener('dragstart', function (ev) {
        if (locked()) {
            ev.preventDefault();
            return;
        }
        var card = ev.target.closest('[data-block-id]');
        if (!card) return;
        dragging = card;
        lastDropIndex = -1;
        card.classList.add('is-dragging');
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', card.getAttribute('data-block-id'));
    });

    els.editor.addEventListener('dragend', function () {
        if (dragging) dragging.classList.remove('is-dragging');
        dragging = null;
        lastDropIndex = -1;
    });

    els.editor.addEventListener('dragover', function (ev) {
        if (!dragging || locked()) return;
        ev.preventDefault();
        var card = ev.target.closest('[data-block-id]');
        var list = els.editor.querySelector('.munch-receipt-blocks');
        if (!list || !card || card === dragging) return;
        var cards = Array.prototype.slice.call(list.children);
        var from = cards.indexOf(dragging);
        var to = cards.indexOf(card);
        if (from < 0 || to < 0 || from === to || to === lastDropIndex) return;
        lastDropIndex = to;
        if (from < to) list.insertBefore(dragging, card.nextSibling);
        else list.insertBefore(dragging, card);
        persistOrderFromDom();
        refreshPreview();
    });

    els.editor.addEventListener('change', function (ev) {
        var target = ev.target;
        var group = target.getAttribute('data-section-group');
        if (group) {
            setFlag(group, target.getAttribute('data-section-key'), target.checked);
            return;
        }
        var styleBool = target.getAttribute('data-style-bool');
        if (styleBool) {
            ensureStyle(target.getAttribute('data-block'))[styleBool] = target.checked;
            schedulePreview();
            return;
        }
        var styleAlign = target.getAttribute('data-style-align');
        if (styleAlign) {
            var st = ensureStyle(target.getAttribute('data-block'));
            if (target.checked) {
                st.align = styleAlign;
                els.editor.querySelectorAll('[data-style-align][data-block="' + target.getAttribute('data-block') + '"]').forEach(function (box) {
                    if (box !== target) box.checked = false;
                });
            } else if (st.align === styleAlign) {
                st.align = 'left';
            }
            schedulePreview();
            return;
        }
        var fsName = target.name || '';
        if (fsName.indexOf('fs-') === 0) {
            ensureStyle(fsName.slice(3)).font_size = target.value;
            schedulePreview();
            return;
        }
        if (target.name === 'logo-mode') {
            currentTemplate().logo.mode = target.value;
            schedulePreview();
            return;
        }
        if (target.name === 'logo-size') {
            currentTemplate().logo.size = target.value;
            schedulePreview();
            return;
        }
        if (target.getAttribute('data-logo-thermal')) {
            currentTemplate().logo.optimize_thermal = target.checked;
            var tmpl = currentTemplate();
            tmpl.print_logo_url = tmpl.logo.optimize_thermal
                ? (tmpl.logo_thermal_url || tmpl.logo_url)
                : tmpl.logo_url;
            renderEditor();
            schedulePreview();
            return;
        }
        if (target.name === 'qr-type') {
            if (!currentTemplate().qr) currentTemplate().qr = { type: 'website', url: '', size: 'medium' };
            currentTemplate().qr.type = target.value;
            currentTemplate().qr_data_uri = null;
            schedulePreview();
            return;
        }
        if (target.name === 'qr-size') {
            if (!currentTemplate().qr) currentTemplate().qr = { type: 'website', url: '', size: 'medium' };
            currentTemplate().qr.size = target.value;
            schedulePreview();
            return;
        }
        if (target.name === 'font-size') currentTemplate().style.font_size = target.value;
        if (target.name === 'font-weight') currentTemplate().style.font_weight = target.value;
        if (target.name === 'section-spacing') currentTemplate().style.section_spacing = target.value;
        if (target.name === 'divider') currentTemplate().style.divider = target.value;
        if (target.name === 'paper') state.print.paper = target.value;
        if (target.name === 'print-mode') state.print.print_mode = target.value;
        if (target.name === 'paper-saving') state.print.paper_saving = target.value;
        if (target.getAttribute('data-print-bool')) {
            state.print[target.getAttribute('data-print-bool')] = target.checked;
        }
        if (target.id === 'receipt-logo-file' && target.files && target.files[0]) {
            var body = new FormData();
            body.append('logo', target.files[0]);
            body.append('branch_id', state.branch_id || 0);
            fetch(CFG.urls.logo, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: body
            }).then(function (res) { return res.json().then(function (data) { if (!res.ok) throw data; return data; }); })
                .then(function (data) {
                    var live = currentTemplate();
                    live.logo.mode = 'upload';
                    live.logo.path = data.path;
                    live.logo.thermal_path = data.thermal_path || null;
                    live.logo_url = data.url;
                    live.logo_thermal_url = data.thermal_url || null;
                    live.print_logo_url = live.logo.optimize_thermal ? (data.thermal_url || data.url) : data.url;
                    render();
                    toast(true, 'Logo uploaded');
                }).catch(function (err) {
                    toast(false, (err && err.message) || 'Logo upload failed');
                });
            return;
        }
        schedulePreview();
    });

    els.editor.addEventListener('input', function (ev) {
        var textKey = ev.target.getAttribute('data-text');
        if (textKey) {
            if (!currentTemplate().texts) currentTemplate().texts = {};
            currentTemplate().texts[textKey] = ev.target.value;
            refreshPreview();
        }
        if (ev.target.getAttribute('data-qr-url')) {
            if (!currentTemplate().qr) currentTemplate().qr = { type: 'website', url: '', size: 'medium' };
            currentTemplate().qr.url = ev.target.value;
            currentTemplate().qr_data_uri = null;
            schedulePreview();
        }
        var printKey = ev.target.getAttribute('data-print');
        if (printKey) {
            state.print[printKey] = Number(ev.target.value || 1);
        }
    });

    var previewChannel = document.getElementById('receipt-preview-channel');
    if (previewChannel) previewChannel.addEventListener('change', schedulePreview);

    if (els.save) {
        els.save.addEventListener('click', function () {
            if (locked() && state.scope === 'company') {
                toast(false, 'Only Master Admin can edit the company template');
                return;
            }
            json(CFG.urls.save, { method: 'POST', body: savePayload() }).then(function (res) {
                toast(true, res.message || 'Saved');
                if (res.data) applyPayload(res.data);
            }).catch(function (err) {
                toast(false, err.message || 'Save failed');
            });
        });
    }

    function reset(action) {
        json(CFG.urls.reset, { method: 'POST', body: { action: action, branch_id: state.branch_id || 0 } }).then(function (res) {
            toast(true, res.message || 'Restored');
            if (res.data) applyPayload(res.data);
        }).catch(function (err) {
            toast(false, err.message || 'Reset failed');
        });
    }

    if (els.resetCompany) els.resetCompany.addEventListener('click', function () { reset('company_default'); });
    if (els.resetBranch) els.resetBranch.addEventListener('click', function () { reset('branch_default'); });
    if (els.resetSection) {
        els.resetSection.addEventListener('click', function () {
            var section = document.querySelector('.munch-receipt-card:hover, .munch-receipt-card:focus-within');
            var key = section && (section.getAttribute('data-block-id') || section.getAttribute('data-section'));
            if (!key || key === 'style' || key === 'print') {
                toast(false, 'Hover a receipt section, then tap Reset Section.');
                return;
            }
            var source = (state.scope === 'branch' && !state.use_company ? state.company : state.factory)[currentKind()];
            if (source && source.block_styles && source.block_styles[key]) {
                currentTemplate().block_styles[key] = JSON.parse(JSON.stringify(source.block_styles[key]));
            }
            fieldsFor(key).forEach(function (field) {
                var group = fieldGroup(field.key);
                if (source && source.sections && source.sections[group] && Object.prototype.hasOwnProperty.call(source.sections[group], field.key)) {
                    currentTemplate().sections[group][field.key] = source.sections[group][field.key];
                }
            });
            render();
            toast(true, 'Section reset');
        });
    }

    if (els.testReceipt) els.testReceipt.addEventListener('click', function () { printKind('customer'); });
    if (els.testKitchen) els.testKitchen.addEventListener('click', function () { printKind('kitchen'); });

    render();
})();
