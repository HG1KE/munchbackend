@php
    $salesKpis = $salesKpis ?? [];
    $kpiBranches = $kpiBranches ?? collect();
@endphp

<section class="munch-dash-kpis mb-3{{ ($salesKpis['timeframe'] ?? 'today') === 'custom' ? ' is-custom' : '' }}" id="munch-dash-kpis" data-url="{{ route('admin.dashboard.sales-kpis') }}">
    <div class="munch-dash-kpis__filters">
        <div class="munch-dash-kpis__field">
            <label for="kpi-branch">{{ translate('Select Branch') }}</label>
            <select class="custom-select" name="kpi_branch" id="kpi-branch">
                <option value="all" selected>{{ translate('All Branches') }}</option>
                @foreach($kpiBranches as $branch)
                    <option value="{{ $branch['id'] }}">{{ $branch['name'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="munch-dash-kpis__field">
            <label for="kpi-timeframe">{{ translate('Timeframe') }}</label>
            <select class="custom-select" name="kpi_timeframe" id="kpi-timeframe">
                <option value="today" selected>{{ translate('Today') }}</option>
                <option value="yesterday">{{ translate('Yesterday') }}</option>
                <option value="last_7_days">{{ translate('Last 7 Days') }}</option>
                <option value="this_week">{{ translate('This Week') }}</option>
                <option value="last_week">{{ translate('Last Week') }}</option>
                <option value="this_month">{{ translate('This Month') }}</option>
                <option value="last_month">{{ translate('Last Month') }}</option>
                <option value="custom">{{ translate('Custom Range') }}</option>
            </select>
        </div>
        <div class="munch-dash-kpis__field munch-dash-kpis__field--dates">
            <div class="flex-grow-1">
                <label for="kpi-from">{{ translate('from') }}</label>
                <input type="date" class="form-control" id="kpi-from">
            </div>
            <div class="flex-grow-1">
                <label for="kpi-to">{{ translate('to') }}</label>
                <input type="date" class="form-control" id="kpi-to">
            </div>
        </div>
    </div>

    <div class="munch-dash-kpis__grid" aria-live="polite">
        <article class="munch-dash-kpis__card munch-dash-kpis__card--munch">
            <span class="munch-dash-kpis__label">{{ translate('Total Munch Sales') }}</span>
            <p class="munch-dash-kpis__value" id="kpi-munch-sales">{{ $salesKpis['munch_sales'] ?? '—' }}</p>
            <ul class="munch-dash-kpis__split">
                <li><span>{{ translate('Cash') }}</span><strong id="kpi-cash">{{ $salesKpis['cash'] ?? '—' }}</strong></li>
                <li><span>{{ translate('Card') }}</span><strong id="kpi-card">{{ $salesKpis['card'] ?? '—' }}</strong></li>
                <li><span>{{ translate('M-PESA') }}</span><strong id="kpi-mpesa">{{ $salesKpis['mpesa'] ?? '—' }}</strong></li>
            </ul>
        </article>
        <article class="munch-dash-kpis__card munch-dash-kpis__card--glovo">
            <span class="munch-dash-kpis__label">{{ translate('Glovo') }}</span>
            <p class="munch-dash-kpis__value" id="kpi-glovo">{{ $salesKpis['glovo'] ?? '—' }}</p>
        </article>
        <article class="munch-dash-kpis__card munch-dash-kpis__card--uber">
            <span class="munch-dash-kpis__label">{{ translate('Uber') }}</span>
            <p class="munch-dash-kpis__value" id="kpi-uber">{{ $salesKpis['uber'] ?? '—' }}</p>
        </article>
        <article class="munch-dash-kpis__card munch-dash-kpis__card--bolt">
            <span class="munch-dash-kpis__label">{{ translate('Bolt Food') }}</span>
            <p class="munch-dash-kpis__value" id="kpi-bolt-food">{{ $salesKpis['bolt_food'] ?? '—' }}</p>
        </article>
    </div>
</section>
