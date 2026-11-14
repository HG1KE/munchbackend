/**
 * Admin Variation stock — bulk allocation modal (all options for one product).
 */
(function () {
    'use strict';

    var root = document.getElementById('munch-var-stock-root');
    if (!root) return;

    var CFG = {
        csrf: (document.querySelector('meta[name="csrf-token"]') || {}).content || '',
        showUrl: root.getAttribute('data-show-url') || '',
        updateUrl: root.getAttribute('data-update-url') || '',
    };

    var els = {
        backdrop: document.getElementById('munch-var-stock-backdrop'),
        modal: document.getElementById('munch-var-stock-modal'),
        title: document.getElementById('munch-var-stock-modal-title'),
        meta: document.getElementById('munch-var-stock-modal-meta'),
        sections: document.getElementById('munch-var-stock-sections'),
        empty: document.getElementById('munch-var-stock-empty'),
        save: document.getElementById('munch-var-stock-save'),
        status: document.getElementById('munch-var-stock-status'),
    };

    var state = {
        productId: null,
        stockItems: [],
        options: [],
        saving: false,
    };

    function jq() {
        return window.jQuery || window.$ || null;
    }

    function urlFor(template, id) {
        return String(template || '').replace('__ID__', String(id));
    }

    function showToast(ok, message) {
        if (window.toastr) {
            window.toastr[ok ? 'success' : 'error'](message);
            return;
        }
        window.alert(message);
    }

    function setStatus(text) {
        if (els.status) els.status.textContent = text || '';
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function optionHtml(selectedId) {
        var html = '<option value="">Select stock item</option>';
        state.stockItems.forEach(function (item) {
            var selected = Number(item.id) === Number(selectedId) ? ' selected' : '';
            html += '<option value="' + item.id + '"' + selected + '>' + escapeHtml(item.name) + '</option>';
        });
        return html;
    }

    function destroySelect2(scope) {
        var $ = jq();
        if (!$ || !$.fn || !$.fn.select2) return;
        var rootEl = scope || els.sections;
        if (!rootEl) return;
        $(rootEl).find('select[data-stock-item]').each(function () {
            var $el = $(this);
            if ($el.hasClass('select2-hidden-accessible')) {
                $el.select2('destroy');
            }
        });
    }

    function initSelect2(scope) {
        var $ = jq();
        if (!$ || !$.fn || !$.fn.select2) return;
        var rootEl = scope || els.sections;
        if (!rootEl) return;
        var $parent = $(els.modal);
        $(rootEl).find('select[data-stock-item]').each(function () {
            var $el = $(this);
            if ($el.hasClass('select2-hidden-accessible')) {
                $el.select2('destroy');
            }
            $el.select2({
                placeholder: 'Select stock item',
                allowClear: true,
                width: '100%',
                dropdownParent: $parent.length ? $parent : $(document.body),
            });
        });
    }

    function rowHtml(optionKey, allocation) {
        allocation = allocation || {};
        var qty = allocation.quantity != null && allocation.quantity !== '' ? allocation.quantity : '';
        return (
            '<div class="munch-stock-row" data-option-key="' + escapeHtml(optionKey) + '">' +
                '<div class="munch-stock-row__item">' +
                    '<label>Stock item</label>' +
                    '<select class="form-control js-select2-custom" data-stock-item>' + optionHtml(allocation.stock_item_id) + '</select>' +
                '</div>' +
                '<div class="munch-stock-row__qty">' +
                    '<label>Qty per sale</label>' +
                    '<input type="number" class="form-control" min="0.001" step="0.001" inputmode="decimal" placeholder="0.5" data-stock-qty value="' + escapeHtml(qty) + '">' +
                '</div>' +
                '<div class="munch-stock-row__remove">' +
                    '<button type="button" class="btn btn-outline-danger" data-var-stock-remove aria-label="Remove row">' +
                        '<i class="tio-clear"></i>' +
                    '</button>' +
                '</div>' +
            '</div>'
        );
    }

    function sectionHtml(option) {
        var allocations = Array.isArray(option.allocations) && option.allocations.length ? option.allocations : [{}];
        var rows = allocations.map(function (allocation) {
            return rowHtml(option.id, allocation);
        }).join('');

        return (
            '<section class="munch-var-stock-section" data-option-section="' + escapeHtml(option.id) + '">' +
                '<div class="munch-var-stock-section__head">' +
                    '<h3 class="munch-var-stock-section__title">' +
                        '<span>' + escapeHtml(option.group) + '</span> · ' + escapeHtml(option.label) +
                    '</h3>' +
                    '<button type="button" class="btn btn-outline-primary btn-sm" data-var-stock-add-row data-option-key="' + escapeHtml(option.id) + '">' +
                        '<i class="tio-add"></i> Add item' +
                    '</button>' +
                '</div>' +
                '<div class="munch-var-stock-section__rows">' + rows + '</div>' +
            '</section>'
        );
    }

    function renderSections(options) {
        destroySelect2();
        state.options = Array.isArray(options) ? options : [];
        if (!state.options.length) {
            els.sections.innerHTML = '<div class="text-muted">This product has no variations.</div>';
            updateEmptyHint();
            return;
        }
        els.sections.innerHTML = state.options.map(sectionHtml).join('');
        initSelect2();
        updateEmptyHint();
    }

    function updateEmptyHint() {
        if (!els.empty) return;
        var hasCatalog = state.stockItems.length > 0;
        els.empty.hidden = hasCatalog || !state.options.length;
        if (els.save) els.save.disabled = state.saving || !hasCatalog || !state.options.length;
    }

    function openModal(productId) {
        state.productId = Number(productId);
        state.stockItems = [];
        state.options = [];
        els.title.textContent = 'Variation stock';
        els.meta.textContent = 'Loading…';
        destroySelect2();
        els.sections.innerHTML = '<div class="munch-stock-loading">Loading allocations…</div>';
        setStatus('');
        els.backdrop.hidden = false;
        els.modal.hidden = false;
        document.body.classList.add('munch-stock-open');

        fetch(urlFor(CFG.showUrl, state.productId), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': CFG.csrf,
            },
            credentials: 'same-origin',
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok) {
                        throw new Error((data && data.message) || 'Failed to load variation stock');
                    }
                    return data;
                });
            })
            .then(function (data) {
                state.stockItems = data.stock_items || [];
                els.title.textContent = 'Variation stock';
                els.meta.textContent = (data.product && data.product.name) ? data.product.name : ('#' + state.productId);
                renderSections(data.options || []);
                if (!state.stockItems.length) {
                    setStatus('Add stock items under Stocks first.');
                }
            })
            .catch(function (err) {
                destroySelect2();
                els.sections.innerHTML = '';
                setStatus((err && err.message) || 'Could not load allocations');
                showToast(false, (err && err.message) || 'Could not load allocations');
            });
    }

    function closeModal() {
        if (state.saving) return;
        destroySelect2();
        els.backdrop.hidden = true;
        els.modal.hidden = true;
        document.body.classList.remove('munch-stock-open');
        state.productId = null;
        setStatus('');
    }

    function collectRows() {
        var optionKeys = [];
        var itemIds = [];
        var qtys = [];
        els.sections.querySelectorAll('.munch-stock-row').forEach(function (row) {
            optionKeys.push(row.getAttribute('data-option-key') || '');
            var item = row.querySelector('[data-stock-item]');
            var qty = row.querySelector('[data-stock-qty]');
            itemIds.push(item ? item.value : '');
            qtys.push(qty ? qty.value : '');
        });
        return { optionKeys: optionKeys, itemIds: itemIds, qtys: qtys };
    }

    function save() {
        if (!state.productId || state.saving) return;
        var collected = collectRows();
        var body = new FormData();
        collected.optionKeys.forEach(function (key) {
            body.append('variation_option_key[]', key);
        });
        collected.itemIds.forEach(function (id) {
            body.append('stock_allocation_item_id[]', id);
        });
        collected.qtys.forEach(function (qty) {
            body.append('stock_allocation_qty[]', qty);
        });

        state.saving = true;
        if (els.save) els.save.disabled = true;
        setStatus('Saving…');

        fetch(urlFor(CFG.updateUrl, state.productId), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': CFG.csrf,
            },
            credentials: 'same-origin',
            body: body,
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok) {
                        var msg = (data && (data.message || data.error)) || 'Could not save allocations';
                        throw new Error(msg);
                    }
                    return data;
                });
            })
            .then(function (data) {
                showToast(true, data.message || 'Variation stock allocations updated');
                state.saving = false;
                closeModal();
                window.location.reload();
            })
            .catch(function (err) {
                setStatus((err && err.message) || 'Could not save allocations');
                showToast(false, (err && err.message) || 'Could not save allocations');
                state.saving = false;
                updateEmptyHint();
            });
    }

    document.addEventListener('click', function (ev) {
        var openBtn = ev.target.closest('[data-variation-stock]');
        if (openBtn) {
            ev.preventDefault();
            openModal(openBtn.getAttribute('data-variation-stock'));
            return;
        }

        if (ev.target.closest('[data-var-stock-close]')) {
            ev.preventDefault();
            closeModal();
            return;
        }

        var addBtn = ev.target.closest('[data-var-stock-add-row]');
        if (addBtn && els.sections.contains(addBtn)) {
            ev.preventDefault();
            if (!state.stockItems.length) return;
            var optionKey = addBtn.getAttribute('data-option-key') || '';
            var section = addBtn.closest('[data-option-section]');
            var rows = section ? section.querySelector('.munch-var-stock-section__rows') : null;
            if (!rows) return;
            rows.insertAdjacentHTML('beforeend', rowHtml(optionKey, {}));
            var added = rows.querySelector('.munch-stock-row:last-child');
            if (added) initSelect2(added);
            return;
        }

        if (ev.target.closest('#munch-var-stock-save')) {
            ev.preventDefault();
            save();
            return;
        }

        var removeBtn = ev.target.closest('[data-var-stock-remove]');
        if (removeBtn && els.sections.contains(removeBtn)) {
            ev.preventDefault();
            var row = removeBtn.closest('.munch-stock-row');
            if (!row) return;
            var rowsRoot = row.parentElement;
            var all = rowsRoot ? rowsRoot.querySelectorAll('.munch-stock-row') : [];
            if (all.length <= 1) {
                destroySelect2(row);
                row.querySelectorAll('select, input').forEach(function (el) { el.value = ''; });
                initSelect2(row);
                return;
            }
            destroySelect2(row);
            row.remove();
        }
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && els.modal && !els.modal.hidden) {
            closeModal();
        }
    });
})();
