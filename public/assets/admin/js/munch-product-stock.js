/**
 * Admin product list — Stock allocation popup.
 */
(function () {
    'use strict';

    var root = document.getElementById('munch-stock-root');
    if (!root) return;

    var CFG = {
        csrf: (document.querySelector('meta[name="csrf-token"]') || {}).content || '',
        showUrl: root.getAttribute('data-show-url') || '',
        updateUrl: root.getAttribute('data-update-url') || '',
    };

    var els = {
        backdrop: document.getElementById('munch-stock-backdrop'),
        modal: document.getElementById('munch-stock-modal'),
        title: document.getElementById('munch-stock-modal-title'),
        meta: document.getElementById('munch-stock-modal-meta'),
        rows: document.getElementById('munch-stock-rows'),
        empty: document.getElementById('munch-stock-empty'),
        save: document.getElementById('munch-stock-save'),
        add: document.getElementById('munch-stock-add-row'),
        status: document.getElementById('munch-stock-status'),
    };

    var state = {
        productId: null,
        stockItems: [],
        saving: false,
    };

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

    function optionHtml(selectedId) {
        var html = '<option value="">Select stock item</option>';
        state.stockItems.forEach(function (item) {
            var selected = Number(item.id) === Number(selectedId) ? ' selected' : '';
            html += '<option value="' + item.id + '"' + selected + '>' + escapeHtml(item.name) + '</option>';
        });
        return html;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function rowHtml(allocation) {
        allocation = allocation || {};
        var qty = allocation.quantity != null && allocation.quantity !== '' ? allocation.quantity : '';
        return (
            '<div class="munch-stock-row">' +
                '<div class="munch-stock-row__item">' +
                    '<label>Stock item</label>' +
                    '<select class="form-control" data-stock-item>' + optionHtml(allocation.stock_item_id) + '</select>' +
                '</div>' +
                '<div class="munch-stock-row__qty">' +
                    '<label>Qty per sale</label>' +
                    '<input type="number" class="form-control" min="0.001" step="0.001" placeholder="1" data-stock-qty value="' + escapeHtml(qty) + '">' +
                '</div>' +
                '<div class="munch-stock-row__remove">' +
                    '<button type="button" class="btn btn-outline-danger" data-stock-remove aria-label="Remove row">' +
                        '<i class="tio-clear"></i>' +
                    '</button>' +
                '</div>' +
            '</div>'
        );
    }

    function renderRows(allocations) {
        var list = Array.isArray(allocations) && allocations.length ? allocations : [{}];
        els.rows.innerHTML = list.map(rowHtml).join('');
        updateEmptyHint();
    }

    function updateEmptyHint() {
        if (!els.empty) return;
        var hasCatalog = state.stockItems.length > 0;
        els.empty.hidden = hasCatalog;
        if (els.add) els.add.disabled = !hasCatalog;
        if (els.save) els.save.disabled = state.saving || !hasCatalog;
    }

    function openModal(productId) {
        state.productId = Number(productId);
        state.stockItems = [];
        els.title.textContent = 'Stock';
        els.meta.textContent = 'Loading…';
        els.rows.innerHTML = '<div class="munch-stock-loading">Loading allocations…</div>';
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
                if (!res.ok) throw new Error('Failed to load stock allocations');
                return res.json();
            })
            .then(function (data) {
                state.stockItems = data.stock_items || [];
                els.title.textContent = 'Stock';
                els.meta.textContent = (data.product && data.product.name) ? data.product.name : ('#' + state.productId);
                renderRows(data.allocations || []);
                if (!state.stockItems.length) {
                    setStatus('Add stock items under Stocks first.');
                }
            })
            .catch(function (err) {
                els.rows.innerHTML = '';
                setStatus((err && err.message) || 'Could not load allocations');
                showToast(false, (err && err.message) || 'Could not load allocations');
            });
    }

    function closeModal() {
        if (state.saving) return;
        els.backdrop.hidden = true;
        els.modal.hidden = true;
        document.body.classList.remove('munch-stock-open');
        state.productId = null;
        setStatus('');
    }

    function collectRows() {
        var itemIds = [];
        var qtys = [];
        els.rows.querySelectorAll('.munch-stock-row').forEach(function (row) {
            var item = row.querySelector('[data-stock-item]');
            var qty = row.querySelector('[data-stock-qty]');
            itemIds.push(item ? item.value : '');
            qtys.push(qty ? qty.value : '');
        });
        return { itemIds: itemIds, qtys: qtys };
    }

    function save() {
        if (!state.productId || state.saving) return;
        var collected = collectRows();
        var body = new FormData();
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
                showToast(true, data.message || 'Stock allocations updated');
                if (data.payload) {
                    state.stockItems = data.payload.stock_items || state.stockItems;
                    renderRows(data.payload.allocations || []);
                }
                setStatus('');
                state.saving = false;
                closeModal();
            })
            .catch(function (err) {
                setStatus((err && err.message) || 'Could not save allocations');
                showToast(false, (err && err.message) || 'Could not save allocations');
                state.saving = false;
                updateEmptyHint();
            });
    }

    document.addEventListener('click', function (ev) {
        var stockBtn = ev.target.closest('[data-stock]');
        if (stockBtn) {
            ev.preventDefault();
            openModal(stockBtn.getAttribute('data-stock'));
            return;
        }

        if (ev.target.closest('[data-stock-close]')) {
            ev.preventDefault();
            closeModal();
            return;
        }

        if (ev.target.closest('#munch-stock-add-row')) {
            ev.preventDefault();
            if (!state.stockItems.length) return;
            els.rows.insertAdjacentHTML('beforeend', rowHtml({}));
            return;
        }

        if (ev.target.closest('#munch-stock-save')) {
            ev.preventDefault();
            save();
            return;
        }

        var removeBtn = ev.target.closest('[data-stock-remove]');
        if (removeBtn && els.rows.contains(removeBtn)) {
            ev.preventDefault();
            var row = removeBtn.closest('.munch-stock-row');
            if (!row) return;
            var all = els.rows.querySelectorAll('.munch-stock-row');
            if (all.length <= 1) {
                row.querySelectorAll('select, input').forEach(function (el) { el.value = ''; });
                return;
            }
            row.remove();
        }
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && els.modal && !els.modal.hidden) {
            closeModal();
        }
    });
})();
