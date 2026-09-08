{{-- Live operational metrics — include once on admin dashboard after welcome header:
     @include('admin-views.partials._dashboard-live-stats')
--}}
<div class="card card-body mb-3 munch-live-stats" id="munch-live-stats">
    <div class="row align-items-center g-2 mb-3">
        <div class="col">
            <h4 class="d-flex align-items-center gap-2 mb-0">
                <span class="munch-live-stats__pulse" aria-hidden="true"></span>
                {{ translate('Live_now') }}
            </h4>
            <small class="text-muted">{{ translate('Storefront activity in the last few minutes') }}</small>
        </div>
        <div class="col-auto">
            <span class="munch-live-stats__updated text-muted small" id="munch-live-stats-updated" aria-live="polite"></span>
        </div>
    </div>

    <div class="row g-2 g-md-3">
        <div class="col-sm-4">
            <div class="munch-live-stats__card">
                <div class="munch-live-stats__label">{{ translate('Visitors on website') }}</div>
                <div class="munch-live-stats__value" id="munch-live-visitors" data-munch-live="visitors">—</div>
                <small class="text-muted">{{ translate('Last 5 minutes') }}</small>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="munch-live-stats__card">
                <div class="munch-live-stats__label">{{ translate('Active carts') }}</div>
                <div class="munch-live-stats__value" id="munch-live-carts" data-munch-live="carts">—</div>
                <small class="text-muted">{{ translate('Cart with items') }}</small>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="munch-live-stats__card">
                <div class="munch-live-stats__label">{{ translate('Checking out') }}</div>
                <div class="munch-live-stats__value" id="munch-live-checkouts" data-munch-live="checkouts">—</div>
                <small class="text-muted">{{ translate('On checkout screen') }}</small>
            </div>
        </div>
    </div>
</div>

@push('script_2')
<script>
    "use strict";
    (function () {
        var statsUrl = @json(route('admin.dashboard.live-stats'));
        var pollMs = 30000;
        var els = {
            visitors: document.getElementById('munch-live-visitors'),
            carts: document.getElementById('munch-live-carts'),
            checkouts: document.getElementById('munch-live-checkouts'),
            updated: document.getElementById('munch-live-stats-updated')
        };
        if (!els.visitors) return;

        function formatTime(iso) {
            try {
                var d = new Date(iso);
                return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            } catch (e) {
                return '';
            }
        }

        function render(data) {
            if (!data) return;
            els.visitors.textContent = String(data.active_visitors != null ? data.active_visitors : 0);
            els.carts.textContent = String(data.active_carts != null ? data.active_carts : 0);
            els.checkouts.textContent = String(data.active_checkouts != null ? data.active_checkouts : 0);
            if (els.updated && data.generated_at) {
                els.updated.textContent = @json(translate('Updated')) + ' ' + formatTime(data.generated_at);
            }
        }

        function fetchStats() {
            fetch(statsUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            })
                .then(function (res) {
                    if (!res.ok) throw new Error('live-stats');
                    return res.json();
                })
                .then(render)
                .catch(function () {});
        }

        fetchStats();
        setInterval(fetchStats, pollMs);
    })();
</script>
@endpush
