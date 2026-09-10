(function () {
    'use strict';

    var root = document.getElementById('munch-receipt-root');
    if (!root || !window.MunchReceiptTicket) return;

    var CFG = window.MUNCH_RECEIPT_EDITOR || {};
    var state = JSON.parse(JSON.stringify(CFG.payload || {}));
    state.kind = 'customer';

    var els = {
        scope: document.getElementById('receipt-scope'),
        modeCompany: document.getElementById('receipt-mode-company'),
        modeCustom: document.getElementById('receipt-mode-custom'),
        modeWrap: document.getElementById('receipt-mode-wrap'),
        editor: document.getElementById('receipt-editor'),
        preview: document.getElementById('receipt-preview-frame'),
        paper: document.getElementById('receipt-paper'),
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
        state = JSON.parse(JSON.stringify(payload || {}));
        state.kind = kind;
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
        refreshPreview();
    }

    function catalog() {
        return (state.section_catalog && state.section_catalog[currentKind()]) || {};
    }

    function groupTitle(key) {
        return ({
            header: 'Header',
            order: 'Order Information',
            items: 'Items',
            summary: 'Summary',
            payment: 'Payment',
            footer: 'Footer',
            marketing: 'Branch Marketing'
        })[key] || key;
    }

    function radioRow(name, value, options) {
        return options.map(function (opt) {
            var checked = String(value) === String(opt.value) ? ' checked' : '';
            return '<label><input type="radio" name="' + name + '" value="' + opt.value + '"' + checked + '> ' + opt.label + '</label>';
        }).join('');
    }

    function renderEditor() {
        if (!els.editor) return;
        var tmpl = currentTemplate();
        var groups = catalog();
        var html = '';
        Object.keys(groups).forEach(function (group) {
            html += '<div class="card mb-3 munch-receipt-card" data-section="' + group + '"><div class="card-body">';
            html += '<h3>' + groupTitle(group) + '</h3><div class="munch-receipt-checks">';
            groups[group].forEach(function (field) {
                var checked = tmpl.sections && tmpl.sections[group] && tmpl.sections[group][field.key] ? ' checked' : '';
                html += '<label><input type="checkbox" data-section-group="' + group + '" data-section-key="' + field.key + '"' + checked + '> ' +
                    field.label + '</label>';
            });
            html += '</div>';
            if (group === 'footer' && currentKind() === 'customer') {
                html += '<div class="mt-3"><label class="font-weight-bold">Thank You Message</label>' +
                    '<input class="form-control" data-text="thank_you_message" value="' + escapeAttr((tmpl.texts || {}).thank_you_message) + '"></div>';
                html += '<div class="mt-3"><label class="font-weight-bold">Footer Text</label>' +
                    '<textarea class="form-control" rows="7" data-text="footer_text">' + escapeHtml((tmpl.texts || {}).footer_text) + '</textarea>' +
                    '<p class="munch-receipt-hint mt-1 mb-0">Placeholders: ' + ((state.placeholders || []).join(' ')) + '</p></div>';
                html += '<div class="mt-3"><label class="font-weight-bold">Return Policy</label>' +
                    '<textarea class="form-control" rows="3" data-text="return_policy">' + escapeHtml((tmpl.texts || {}).return_policy) + '</textarea></div>';
            }
            if (group === 'header') {
                html += '<div class="mt-3"><label class="font-weight-bold">Receipt Title</label>' +
                    '<input class="form-control" data-text="receipt_title" value="' + escapeAttr((tmpl.texts || {}).receipt_title) + '"></div>';
                if (currentKind() === 'customer') {
                    html += '<div class="mt-3"><label class="font-weight-bold">Tax PIN</label>' +
                        '<input class="form-control" data-text="tax_pin" value="' + escapeAttr((tmpl.texts || {}).tax_pin) + '"></div>';
                }
                html += '<div class="mt-3"><label class="font-weight-bold">Logo</label>' +
                    '<div class="munch-receipt-checks">' +
                    radioRow('logo-mode', (tmpl.logo && tmpl.logo.mode) || 'none', [
                        { value: 'none', label: 'No logo' },
                        { value: 'company', label: 'Use company logo' },
                        { value: 'upload', label: 'Upload logo' }
                    ]) + '</div>' +
                    '<input class="form-control mt-2" id="receipt-logo-file" type="file" accept="image/*">' +
                    (tmpl.logo_url ? '<img class="munch-receipt-logo-preview" src="' + escapeAttr(tmpl.logo_url) + '" alt="">' : '') +
                    '</div>';
            }
            if (group === 'marketing') {
                html += '<div class="mt-3"><label class="font-weight-bold">Promotion Banner</label>' +
                    '<textarea class="form-control" rows="3" data-text="promotion_banner">' + escapeHtml((tmpl.texts || {}).promotion_banner) + '</textarea></div>';
            }
            if (group === 'footer' && currentKind() === 'kitchen') {
                html += '<div class="mt-3"><label class="font-weight-bold">Footer Text</label>' +
                    '<textarea class="form-control" rows="4" data-text="footer_text">' + escapeHtml((tmpl.texts || {}).footer_text) + '</textarea></div>';
            }
            html += '</div></div>';
        });

        html += '<div class="card mb-3 munch-receipt-card" data-section="style"><div class="card-body"><h3>Fonts</h3>';
        html += '<p class="font-weight-bold mb-1">Font Size</p><div class="munch-receipt-checks">' +
            radioRow('font-size', tmpl.style.font_size, [
                { value: 'small', label: 'Small' }, { value: 'medium', label: 'Medium' }, { value: 'large', label: 'Large' }
            ]) + '</div>';
        html += '<p class="font-weight-bold mb-1 mt-3">Weight</p><div class="munch-receipt-checks">' +
            radioRow('font-weight', tmpl.style.font_weight, [
                { value: 'normal', label: 'Normal' }, { value: 'bold', label: 'Bold' }
            ]) + '</div>';
        html += '<p class="font-weight-bold mb-1 mt-3">Section spacing</p><div class="munch-receipt-checks">' +
            radioRow('section-spacing', tmpl.style.section_spacing, [
                { value: 'compact', label: 'Compact' }, { value: 'normal', label: 'Normal' }, { value: 'wide', label: 'Wide' }
            ]) + '</div>';
        html += '<p class="font-weight-bold mb-1 mt-3">Divider style</p><div class="munch-receipt-checks">' +
            radioRow('divider', tmpl.style.divider, [
                { value: 'solid', label: 'Solid' }, { value: 'dashed', label: 'Dashed' }, { value: 'none', label: 'None' }
            ]) + '</div></div></div>';

        html += '<div class="card mb-3 munch-receipt-card" data-section="print"><div class="card-body"><h3>Paper & Print Settings</h3>';
        html += '<p class="font-weight-bold mb-1">Paper</p><div class="munch-receipt-checks">' +
            radioRow('paper', state.print.paper, [
                { value: '58mm', label: '58mm' }, { value: '80mm', label: '80mm' }
            ]) + '</div>';
        html += '<div class="row mt-3"><div class="col-md-6"><label>Number of receipt copies</label>' +
            '<input class="form-control" type="number" min="1" max="5" data-print="receipt_copies" value="' + escapeAttr(state.print.receipt_copies) + '"></div>';
        html += '<div class="col-md-6"><label>Number of kitchen ticket copies</label>' +
            '<input class="form-control" type="number" min="1" max="5" data-print="kitchen_copies" value="' + escapeAttr(state.print.kitchen_copies) + '"></div></div>';
        html += '<div class="munch-receipt-checks mt-3">' +
            '<label><input type="checkbox" data-print-bool="auto_cut"' + (state.print.auto_cut ? ' checked' : '') + '> Auto Cut</label>' +
            '<label><input type="checkbox" data-print-bool="drawer_kick"' + (state.print.drawer_kick ? ' checked' : '') + '> Drawer Kick</label>' +
            '</div><p class="munch-receipt-hint mb-0 mt-2">Auto Cut and Drawer Kick are stored with the template. Browser print uses the printer driver for hardware commands.</p>';
        html += '</div></div>';

        els.editor.innerHTML = html;
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
            btn.classList.toggle('is-active', btn.getAttribute('data-receipt-tab') === currentKind());
        });
        renderEditor();
        refreshPreview();
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
            state.kind = tab.getAttribute('data-receipt-tab');
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

    els.editor.addEventListener('change', function (ev) {
        var target = ev.target;
        var group = target.getAttribute('data-section-group');
        if (group) {
            setFlag(group, target.getAttribute('data-section-key'), target.checked);
            return;
        }
        if (target.name === 'logo-mode') {
            currentTemplate().logo.mode = target.value;
            refreshPreview();
            return;
        }
        if (target.name === 'font-size') currentTemplate().style.font_size = target.value;
        if (target.name === 'font-weight') currentTemplate().style.font_weight = target.value;
        if (target.name === 'section-spacing') currentTemplate().style.section_spacing = target.value;
        if (target.name === 'divider') currentTemplate().style.divider = target.value;
        if (target.name === 'paper') state.print.paper = target.value;
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
                    currentTemplate().logo.mode = 'upload';
                    currentTemplate().logo.path = data.path;
                    currentTemplate().logo_url = data.url;
                    render();
                    toast(true, 'Logo uploaded');
                }).catch(function (err) {
                    toast(false, (err && err.message) || 'Logo upload failed');
                });
            return;
        }
        refreshPreview();
    });

    els.editor.addEventListener('input', function (ev) {
        var textKey = ev.target.getAttribute('data-text');
        if (textKey) {
            if (!currentTemplate().texts) currentTemplate().texts = {};
            currentTemplate().texts[textKey] = ev.target.value;
            refreshPreview();
        }
        var printKey = ev.target.getAttribute('data-print');
        if (printKey) {
            state.print[printKey] = Number(ev.target.value || 1);
        }
    });

    var previewChannel = document.getElementById('receipt-preview-channel');
    if (previewChannel) previewChannel.addEventListener('change', refreshPreview);

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
            var key = section && section.getAttribute('data-section');
            if (!key || key === 'style' || key === 'print') {
                toast(false, 'Hover a receipt section, then tap Reset Section.');
                return;
            }
            var source = (state.scope === 'branch' && !state.use_company ? state.company : state.factory)[currentKind()];
            if (source && source.sections && source.sections[key]) {
                currentTemplate().sections[key] = JSON.parse(JSON.stringify(source.sections[key]));
                render();
                toast(true, 'Section reset');
            }
        });
    }

    if (els.testReceipt) els.testReceipt.addEventListener('click', function () { printKind('customer'); });
    if (els.testKitchen) els.testKitchen.addEventListener('click', function () { printKind('kitchen'); });

    render();
})();
