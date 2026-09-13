@php
    $addonMarketplaceCatalog = \App\Model\AddOn::query()
        ->orderBy('name')
        ->get(['id', 'name', 'price'])
        ->map(function ($addon) {
            return [
                'id' => (int) $addon->id,
                'name' => (string) ($addon->getRawOriginal('name') ?: $addon->name),
                'price' => (float) $addon->price,
            ];
        });
    $addonMarketplacePrices = \App\Support\AddonChannelPricing::mapForIds($addonMarketplaceCatalog->pluck('id')->all());
    $addonMarketplaceCatalog = $addonMarketplaceCatalog->map(function ($row) use ($addonMarketplacePrices) {
        $row['channel_prices'] = $addonMarketplacePrices[$row['id']] ?? [];

        return $row;
    })->values();
@endphp
<div id="addon-marketplace-prices" class="mt-3" hidden></div>
<script type="application/json" id="addon-marketplace-catalog">@json($addonMarketplaceCatalog)</script>
<script>
    (function () {
        var select = document.getElementById('choose_addons');
        var mount = document.getElementById('addon-marketplace-prices');
        var catalogEl = document.getElementById('addon-marketplace-catalog');
        if (!select || !mount || !catalogEl) return;
        var catalog = [];
        try { catalog = JSON.parse(catalogEl.textContent || '[]'); } catch (e) { catalog = []; }
        function escapeHtml(value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
            });
        }
        function selectedIds() {
            return Array.prototype.map.call(select.selectedOptions || [], function (option) {
                return Number(option.value);
            }).filter(function (id) { return id > 0; });
        }
        function currentValue(addon, channel) {
            var prices = addon.channel_prices || {};
            if (prices[channel] == null || prices[channel] === '') return '';
            return String(prices[channel]);
        }
        function render() {
            var selected = selectedIds();
            var rows = catalog.filter(function (addon) { return selected.indexOf(addon.id) !== -1; });
            if (!rows.length) {
                mount.hidden = true;
                mount.innerHTML = '';
                return;
            }
            var body = rows.map(function (addon) {
                return '<tr><th>' + escapeHtml(addon.name) + '</th>' +
                    '<td><input class="form-control" type="number" min="0" step="0.01" name="addon_channel_prices[' + addon.id + '][uber]" value="' + escapeHtml(currentValue(addon, 'uber')) + '" placeholder="—"></td>' +
                    '<td><input class="form-control" type="number" min="0" step="0.01" name="addon_channel_prices[' + addon.id + '][glovo]" value="' + escapeHtml(currentValue(addon, 'glovo')) + '" placeholder="—"></td>' +
                    '<td><input class="form-control" type="number" min="0" step="0.01" name="addon_channel_prices[' + addon.id + '][bolt_food]" value="' + escapeHtml(currentValue(addon, 'bolt_food')) + '" placeholder="—"></td></tr>';
            }).join('');
            mount.hidden = false;
            mount.innerHTML = '<p class="mb-2 font-weight-bold">{{ translate('Addon marketplace prices') }}</p>' +
                '<p class="text-muted small mb-2">{{ translate('These Uber, Glovo and Bolt Food prices apply to this addon everywhere it is used. Leave a field empty to keep the current marketplace price.') }}</p>' +
                '<div class="table-responsive"><table class="table table-sm table-borderless mb-0">' +
                '<thead><tr><th>{{ translate('Addon') }}</th><th>{{ translate('Uber Price') }}</th><th>{{ translate('Glovo Price') }}</th><th>{{ translate('Bolt Food Price') }}</th></tr></thead>' +
                '<tbody>' + body + '</tbody></table></div>';
        }
        if (window.jQuery) {
            window.jQuery(select).on('change', render);
        }
        select.addEventListener('change', render);
        render();
    })();
</script>
