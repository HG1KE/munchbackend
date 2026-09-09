@extends('layouts.branch.pos')

@section('title', translate('POS') . ' · ' . $branchName)

@section('content')
<div class="munch-pos-app" id="munch-pos-root">
    <header class="munch-pos-topbar">
        <a class="munch-pos-topbar__back" href="{{ route('branch.dashboard') }}">{{ translate('Dashboard') }}</a>
        <div class="munch-pos-topbar__title">
            <strong>{{ translate('POS') }}</strong>
            <span>{{ $branchName }}</span>
        </div>
        <div class="munch-pos-status" aria-live="polite">
            <button type="button" class="munch-pos-topbar__orders" id="pos-view-orders">{{ translate('View Orders') }}</button>
            <span class="munch-pos-badge munch-pos-badge--online" id="pos-conn-badge">{{ translate('Online') }}</span>
            <span class="munch-pos-badge munch-pos-badge--queue" id="pos-queue-badge" hidden>0</span>
            <span class="munch-pos-badge munch-pos-badge--sync" id="pos-sync-badge" hidden></span>
        </div>
        <div class="munch-pos-topbar__total" id="pos-top-total"></div>
        <a class="munch-pos-topbar__link" href="{{ route('branch.pos.orders') }}">{{ translate('orders') }}</a>
    </header>

    <div class="munch-pos-shell">
        <section class="munch-pos-catalog" aria-label="{{ translate('Product_Section') }}">
            <div class="munch-pos-catalog__toolbar">
                <label class="munch-pos-search">
                    <span class="sr-only">{{ translate('Search_here') }}</span>
                    <input type="search" id="pos-search" placeholder="{{ translate('Search_here') }}" autocomplete="off">
                </label>
            </div>
            <div class="munch-pos-tabs" id="pos-tabs" role="tablist"></div>
            <div class="munch-pos-grid" id="pos-grid"></div>
            <p class="munch-pos-empty" id="pos-empty" hidden>{{ translate('No Data Found') }}</p>
        </section>

        <aside class="munch-pos-cart" aria-label="{{ translate('Billing_Section') }}">
            <div class="munch-pos-cart__scroll">
                <div class="munch-pos-types" id="pos-types"></div>
                <div class="munch-pos-extra" id="pos-dine-in" hidden>
                    <select id="pos-table" aria-label="{{ translate('Select Table') }}"></select>
                    <input type="number" id="pos-people" min="1" max="99" placeholder="{{ translate('Number Of People') }}">
                </div>
                <div class="munch-pos-extra" id="pos-delivery" hidden>
                    <input type="text" id="pos-del-name" placeholder="{{ translate('Name') }}" autocomplete="name">
                    <input type="tel" id="pos-del-phone" placeholder="{{ translate('Phone') }}" autocomplete="tel">
                    <textarea id="pos-del-address" rows="2" placeholder="{{ translate('Address') }}"></textarea>
                    <label class="munch-pos-fee">
                        <span>{{ translate('Delivery Fee') }}</span>
                        <span class="munch-pos-fee__field">
                            <span class="munch-pos-fee__currency" id="pos-del-fee-currency">Ksh</span>
                            <input type="number" id="pos-del-fee" min="0" step="1" inputmode="decimal" value="0" aria-label="{{ translate('Delivery Fee') }}">
                        </span>
                    </label>
                </div>
                <ul class="munch-pos-lines" id="pos-lines"></ul>
                <div class="munch-pos-auth" id="pos-auth" hidden>
                    <p>{{ translate('Session expired. Please sign in again.') }}</p>
                    <a class="munch-pos-auth__btn" id="pos-auth-link" href="{{ route('branch.auth.login') }}">{{ translate('Sign in') }}</a>
                </div>
                <ul class="munch-pos-queue" id="pos-queue-list" hidden></ul>
            </div>
            <div class="munch-pos-footer">
                <div class="munch-pos-totals" id="pos-totals"></div>
                <div class="munch-pos-discount">
                    <input type="number" id="pos-discount" min="0" step="1" placeholder="{{ translate('Discount') }}">
                    <select id="pos-discount-type">
                        <option value="amount">{{ translate('Amount') }}</option>
                        <option value="percent">%</option>
                    </select>
                </div>
                <div class="munch-pos-pay" id="pos-pay"></div>
                <div class="munch-pos-paid" id="pos-paid-wrap">
                    <input type="number" id="pos-paid" min="0" step="1" placeholder="{{ translate('Paid Amount') }}">
                    <span id="pos-change"></span>
                </div>
                <button type="button" class="munch-pos-place" id="pos-place">{{ translate('Place Order') }}</button>
                <button type="button" class="munch-pos-clear" id="pos-clear">{{ translate('Clear Cart') }}</button>
            </div>
        </aside>
    </div>
</div>

<div class="munch-pos-modal" id="pos-modal" hidden>
    <div class="munch-pos-modal__card" role="dialog" aria-modal="true" id="pos-modal-card"></div>
</div>

<div class="munch-pos-orders" id="pos-orders-modal" hidden>
    <div class="munch-pos-orders__card" role="dialog" aria-modal="true" aria-labelledby="pos-orders-title">
        <header class="munch-pos-orders__head">
            <div>
                <h2 id="pos-orders-title">{{ translate("Today's POS Orders") }}</h2>
                <p class="munch-pos-orders__meta" id="pos-orders-meta"></p>
            </div>
            <div class="munch-pos-orders__actions">
                <button type="button" class="munch-pos-orders__refresh" id="pos-orders-refresh">{{ translate('Refresh') }}</button>
                <button type="button" class="munch-pos-orders__close" id="pos-orders-close">{{ translate('Close') }}</button>
            </div>
        </header>
        <label class="munch-pos-orders__search">
            <span class="sr-only">{{ translate('Search_here') }}</span>
            <input type="search" id="pos-orders-search" placeholder="{{ translate('Order Number') }}, {{ translate('Name') }}, {{ translate('Phone') }}" autocomplete="off">
        </label>
        <div class="munch-pos-orders__filters" id="pos-orders-filters"></div>
        <div class="munch-pos-orders__list" id="pos-orders-list"></div>
        <footer class="munch-pos-orders__pager" id="pos-orders-pager"></footer>
    </div>
</div>

<div class="munch-pos-toast" id="pos-toast" hidden></div>
@endsection

@push('script')
<script>
    window.MUNCH_POS = {
        catalog: @json($catalog),
        csrf: @json(csrf_token()),
        urls: {
            catalog: @json(route('branch.pos.catalog')),
            heartbeat: @json(route('branch.pos.heartbeat')),
            todayOrders: @json(route('branch.pos.today-orders')),
            order: @json(route('branch.pos.order')),
            invoice: @json(url('branch/pos/invoice')),
            sw: @json(route('branch.pos.service-worker')),
            login: @json(route('branch.auth.login')),
        },
        labels: {
            all: @json(translate('All Categories')),
            delivery: @json(translate('Delivery')),
            takeAway: @json(translate('Take Away')),
            dineIn: @json(translate('Dine In')),
            glovo: 'Glovo',
            uber: 'Uber',
            boltFood: 'Bolt Food',
            online: @json(translate('Online')),
            offline: @json(translate('Offline')),
            queued: @json(translate('Queued')),
            syncing: @json(translate('Syncing...')),
            synced: @json(translate('Synced successfully')),
            syncFailed: @json(translate('Failed to sync')),
            subtotal: @json(translate('Subtotal')),
            deliveryCharge: @json(translate('Delivery Charge')),
            deliveryFee: @json(translate('Delivery Fee')),
            discount: @json(translate('Discount')),
            grandTotal: @json(translate('Grand Total')),
            cash: @json(translate('Cash')),
            card: @json(translate('Card')),
            payAfter: @json(translate('pay_after_eating')),
            cod: @json(translate('Cash On Delivery')),
            add: @json(translate('Add To Cart')),
            required: @json(translate('Required')),
            optional: @json(translate('optional')),
            addons: @json(translate('addon')),
            emptyCart: @json(translate('cart_empty_warning')),
            table: @json(translate('please select a table number')),
            people: @json(translate('please enter people number')),
            address: @json(translate('please select a delivery address')),
            placed: @json(translate('order_placed_successfully')),
            queuedSaved: @json(translate('Order saved offline')),
            signIn: @json(translate('Sign in')),
            sessionExpired: @json(translate('Session expired. Please sign in again.')),
            retrying: @json(translate('Waiting to retry')),
            validationFailed: @json(translate('Needs correction')),
            viewOrders: @json(translate('View Orders')),
            todayOrders: @json(translate("Today's POS Orders")),
            refresh: @json(translate('Refresh')),
            close: @json(translate('Close')),
            allOrders: @json(translate('All')),
            completed: @json(translate('Completed')),
            cancelled: @json(translate('Cancelled')),
            active: @json(translate('Active')),
            paid: @json(translate('Paid')),
            unpaid: @json(translate('Unpaid')),
            walkIn: @json(translate('walk_in_customer')),
            customer: @json(translate('Customer')),
            phone: @json(translate('Phone')),
            addressLabel: @json(translate('Address')),
            items: @json(translate('Items')),
            quantity: @json(translate('Quantity')),
            unitPrice: @json(translate('Price')),
            cashReceived: @json(translate('Paid Amount')),
            change: @json(translate('Change')),
            cashier: @json(translate('Cashier')),
            createdTime: @json(translate('Created at')),
            completedTime: @json(translate('Delivered')),
            noOrders: @json(translate('No Data Found')),
            page: @json(translate('Page')),
            paymentMethod: @json(translate('Payment Method')),
        }
    };
</script>
<script src="{{ asset('public/assets/admin/js/munch-pos-app.js') }}?v=1.5" defer></script>
@endpush
