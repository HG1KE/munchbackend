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
            <div class="munch-pos-tabs-wrap" id="pos-tabs-wrap">
                <button type="button" class="munch-pos-tabs__arrow" id="pos-tabs-prev" aria-label="Previous categories">◀</button>
                <div class="munch-pos-tabs" id="pos-tabs" role="tablist"></div>
                <button type="button" class="munch-pos-tabs__arrow" id="pos-tabs-next" aria-label="Next categories">▶</button>
            </div>
            <div class="munch-pos-grid" id="pos-grid"></div>
            <p class="munch-pos-empty" id="pos-empty" hidden>{{ translate('No Data Found') }}</p>
        </section>

        <aside class="munch-pos-cart" aria-label="{{ translate('Billing_Section') }}">
            <div class="munch-pos-cart__scroll">
                <div class="munch-pos-types" id="pos-types"></div>
                <ul class="munch-pos-lines" id="pos-lines"></ul>
                <div class="munch-pos-auth" id="pos-auth" hidden>
                    <p>{{ translate('Session expired. Please sign in again.') }}</p>
                    <a class="munch-pos-auth__btn" id="pos-auth-link" href="{{ route('branch.auth.login') }}">{{ translate('Sign in') }}</a>
                </div>
                <ul class="munch-pos-queue" id="pos-queue-list" hidden></ul>
            </div>
            <div class="munch-pos-footer">
                <div class="munch-pos-totals" id="pos-totals"></div>
                <div class="munch-pos-discount" id="pos-discount-wrap">
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
                <button type="button" class="munch-pos-place" id="pos-place" data-label="{{ translate('Place Order') }}">{{ translate('Place Order') }}</button>
                <button type="button" class="munch-pos-clear" id="pos-clear">{{ translate('Clear Cart') }}</button>
            </div>
        </aside>
    </div>
</div>

<div class="munch-pos-modal" id="pos-modal" hidden>
    <div class="munch-pos-modal__card" role="dialog" aria-modal="true" id="pos-modal-card"></div>
</div>

<div class="munch-pos-modal" id="pos-delivery-modal" hidden>
    <div class="munch-pos-modal__card munch-pos-delivery-modal" role="dialog" aria-modal="true" aria-labelledby="pos-delivery-title">
        <h2 id="pos-delivery-title">{{ translate('Delivery details') }}</h2>
        <p class="munch-pos-delivery-modal__error" id="pos-delivery-error" hidden></p>
        <label class="munch-pos-delivery-modal__field">
            <span>{{ translate('Customer Name') }}</span>
            <input type="text" id="pos-del-name" placeholder="{{ translate('Customer Name') }}" autocomplete="off" data-del-field>
        </label>
        <label class="munch-pos-delivery-modal__field">
            <span>{{ translate('Customer Phone') }}</span>
            <input type="tel" id="pos-del-phone" placeholder="{{ translate('Customer Phone') }}" autocomplete="off" inputmode="numeric" pattern="[0-9+]*" data-del-field>
        </label>
        <label class="munch-pos-delivery-modal__field">
            <span>{{ translate('Delivery Address') }}</span>
            <textarea id="pos-del-address" rows="2" placeholder="{{ translate('Delivery Address') }}" autocomplete="off" data-del-field></textarea>
        </label>
        <label class="munch-pos-delivery-modal__field">
            <span>{{ translate('Delivery Fee') }}</span>
            <span class="munch-pos-fee__field">
                <span class="munch-pos-fee__currency" id="pos-del-fee-currency">Ksh</span>
                <input type="number" id="pos-del-fee" min="0" step="1" inputmode="decimal" value="0" aria-label="{{ translate('Delivery Fee') }}" data-del-field>
            </span>
        </label>
        <h3>{{ translate('Who will deliver this order?') }}</h3>
        <label class="munch-pos-delivery-modal__field">
            <span>{{ translate('Rider Name') }}</span>
            <input type="text" id="pos-del-rider-name" placeholder="{{ translate('Rider Name') }}" autocomplete="off" data-del-field>
        </label>
        <label class="munch-pos-delivery-modal__field">
            <span>{{ translate('Rider Phone') }}</span>
            <input type="tel" id="pos-del-rider-phone" placeholder="{{ translate('Rider Phone') }}" autocomplete="off" inputmode="numeric" pattern="[0-9+]*" data-del-field>
        </label>
        <button type="button" class="munch-pos-place" id="pos-delivery-confirm">{{ translate('Confirm Delivery') }}</button>
        <button type="button" class="munch-pos-clear" id="pos-delivery-cancel">{{ translate('Close') }}</button>
    </div>
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
    <div class="munch-pos-orders__nested" id="pos-cancel-modal" hidden>
        <div class="munch-pos-modal__card munch-pos-cancel-modal" role="dialog" aria-modal="true" aria-labelledby="pos-cancel-title">
            <h2 id="pos-cancel-title">{{ translate('Cancel Order') }}</h2>
            <p class="munch-pos-cancel-modal__error" id="pos-cancel-error" hidden></p>
            <label class="munch-pos-cancel-modal__field">
                <span>{{ translate('Cancellation Reason') }}</span>
                <textarea id="pos-cancel-reason" rows="4" minlength="5" maxlength="500" required></textarea>
            </label>
            <p class="munch-pos-cancel-modal__warn">⚠️ {{ translate('This cancellation will be visible to the Master Admin.') }}</p>
            <p class="munch-pos-cancel-modal__confirm">{{ translate('Are you sure you want to continue?') }}</p>
            <div class="munch-pos-cancel-modal__actions">
                <button type="button" class="munch-pos-clear" id="pos-cancel-dismiss">{{ translate('Cancel') }}</button>
                <button type="button" class="munch-pos-cancel-modal__submit" id="pos-cancel-confirm">{{ translate('Confirm Cancellation') }}</button>
            </div>
        </div>
    </div>
</div>

<div class="munch-pos-success" id="pos-success-modal" hidden>
    <div class="munch-pos-success__card" role="dialog" aria-modal="true" aria-labelledby="pos-success-title">
        <p class="munch-pos-success__mark" aria-hidden="true">✅</p>
        <h2 id="pos-success-title">{{ translate('Order Placed Successfully') }}</h2>
        <dl class="munch-pos-success__meta">
            <div>
                <dt>{{ translate('Order') }} #</dt>
                <dd id="pos-success-number"></dd>
            </div>
            <div>
                <dt>{{ translate('Grand Total') }}</dt>
                <dd id="pos-success-total"></dd>
            </div>
            <div>
                <dt>{{ translate('Payment Method') }}</dt>
                <dd id="pos-success-pay"></dd>
            </div>
        </dl>
        <button type="button" class="munch-pos-place" id="pos-success-kitchen">{{ translate('Print Kitchen Order') }}</button>
        <button type="button" class="munch-pos-success__receipt" id="pos-success-receipt">{{ translate('Print Receipt') }}</button>
        <button type="button" class="munch-pos-place" id="pos-success-done">{{ translate('Done') }}</button>
        <button type="button" class="munch-pos-clear" id="pos-success-close">{{ translate('Close') }}</button>
    </div>
</div>

<iframe id="pos-print-frame" class="munch-pos-print-frame" title="{{ translate('Print') }}"></iframe>

<div class="munch-pos-toast" id="pos-toast" hidden></div>
@endsection

@push('script')
<script>
    window.MUNCH_POS = {
        catalog: @json($catalog),
        branchName: @json($branchName),
        cashierName: @json($branchName),
        restaurantName: @json(\App\CentralLogics\Helpers::get_business_settings('restaurant_name') ?: 'MUNCH'),
        csrf: @json(csrf_token()),
        urls: {
            catalog: @json(route('branch.pos.catalog')),
            heartbeat: @json(route('branch.pos.heartbeat')),
            todayOrders: @json(route('branch.pos.today-orders')),
            printTicket: @json(route('branch.pos.print-ticket')),
            cancelOrder: @json(route('branch.pos.cancel-order')),
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
            mpesa: @json(translate('M-PESA')),
            payAfter: @json(translate('pay_after_eating')),
            cod: @json(translate('Cash On Delivery')),
            deliveryDetails: @json(translate('Delivery details')),
            customerName: @json(translate('Customer Name')),
            customerPhone: @json(translate('Customer Phone')),
            deliveryAddress: @json(translate('Delivery Address')),
            riderName: @json(translate('Rider Name')),
            riderPhone: @json(translate('Rider Phone')),
            confirmDelivery: @json(translate('Confirm Delivery')),
            whoDelivers: @json(translate('Who will deliver this order?')),
            add: @json(translate('Add To Cart')),
            required: @json(translate('Required')),
            optional: @json(translate('optional')),
            placeOrder: @json(translate('Place Order')),
            placing: @json(translate('Placing...')),
            queueing: @json(translate('Queueing...')),
            queueFailed: @json(translate('Could not save offline. Please try again.')),
            emptyCart: @json(translate('Cart empty')),
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
            payment: @json(translate('Payment')),
            paymentStatus: @json(translate('Payment_Status')),
            invalidPhone: @json(translate('Invalid phone number')),
            done: @json(translate('Done')),
            salesChannel: @json(translate('Sales Channel')),
            receiptNumber: @json(translate('Receipt number')),
            order: @json(translate('Order')),
            placedSuccess: @json(translate('Order Placed Successfully')),
            printKitchen: @json(translate('Print Kitchen Order')),
            printReceipt: @json(translate('Print Receipt')),
            kitchenPrinted: @json(translate('Kitchen Order Printed')),
            receiptPrinted: @json(translate('Receipt Printed')),
            print: @json(translate('Print')),
            kitchenOrder: @json(translate('Kitchen Order')),
            orderType: @json(translate('Order Type')),
            date: @json(translate('Date')),
            time: @json(translate('Time')),
            branch: @json(translate('Branch')),
            deliveryNotes: @json(translate('Delivery Notes')),
            cashReceivedPrint: @json(translate('Cash Received')),
            balance: @json(translate('Balance')),
            thanks: @json(translate('Thank you for choosing Munch')),
            mpesaTill: @json(translate('M-PESA Till')),
            cancelOrder: @json(translate('Cancel Order')),
            cancellationReason: @json(translate('Cancellation Reason')),
            cancelWarning: @json(translate('This cancellation will be visible to the Master Admin.')),
            cancelConfirmQuestion: @json(translate('Are you sure you want to continue?')),
            confirmCancellation: @json(translate('Confirm Cancellation')),
            cancelling: @json(translate('Cancelling...')),
            cancelledBy: @json(translate('Cancelled by')),
            cancelledAt: @json(translate('Cancelled at')),
            cancelQueued: @json(translate('Cancellation saved offline')),
        }
    };
</script>
<script src="{{ asset('public/assets/admin/js/munch-receipt-ticket.js') }}?v=1.2"></script>
<script src="{{ asset('public/assets/admin/js/munch-pos-submit-guard.js') }}?v=1.0"></script>
<script src="{{ asset('public/assets/admin/js/munch-pos-delivery.js') }}?v=1.0"></script>
<script src="{{ asset('public/assets/admin/js/munch-pos-app.js') }}?v=3.4" defer></script>
@endpush
