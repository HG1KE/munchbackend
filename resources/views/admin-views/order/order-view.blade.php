@extends('layouts.admin.app')

@section('title', translate('Order Details'))

@push('css_or_js')
    <link rel="stylesheet" href="{{ asset('public/assets/admin/css/meatco-order-operations.css') }}?v=1.12">
@endpush

@section('content')
    @php
        $munchImgProduct = asset('public/assets/admin/img/160x160/img2.jpg');
        $munchImgPerson = asset('public/assets/admin/img/160x160/img1.jpg');
        $address = $order->address ?? [];
        if (is_object($address) && method_exists($address, 'toArray')) {
            $address = $address->toArray();
        }
        if (! is_array($address)) {
            $address = [];
        }
        $offlinePaymentData = [];
        if (! empty($order->offline_payment) && ! empty($order->offline_payment->payment_info)) {
            $decodedPayment = json_decode($order->offline_payment->payment_info, true);
            $offlinePaymentData = is_array($decodedPayment) ? $decodedPayment : [];
        }
        $offlinePaymentMethodInformation = $offlinePaymentData['method_information'] ?? [];
        $offlinePaymentMethodFields = $offlinePaymentData['method_fields'] ?? [];
        $googleMapStatus = \App\CentralLogics\Helpers::get_business_settings('google_map_status');
        $offlinePaymentStatus = optional($order->offline_payment)->status;
        $branchDeliveryChargeType = optional(optional($order->branch)->delivery_charge_setup)->delivery_charge_type;
        $orderAreaName = optional(optional($order->order_area)->area)->area_name;
        $orderChangePaidAmount = optional($order->order_change_amount)->paid_amount ?? 0;
        $orderChangeOrderAmount = optional($order->order_change_amount)->order_amount ?? 0;
        $orderEditApiEnabled = \Illuminate\Support\Facades\Route::has('admin.orders.edit');
        $orderEditApiRoutes = [
            'clear_session' => \Illuminate\Support\Facades\Route::has('admin.orders.clear-order-edit-session')
                ? route('admin.orders.clear-order-edit-session') : null,
            'search_product' => \Illuminate\Support\Facades\Route::has('admin.orders.search-product')
                ? route('admin.orders.search-product') : null,
            'product_variation' => \Illuminate\Support\Facades\Route::has('admin.orders.product.variation')
                ? route('admin.orders.product.variation') : null,
            'update_product_quantity' => \Illuminate\Support\Facades\Route::has('admin.orders.update-product-quantity')
                ? route('admin.orders.update-product-quantity') : null,
            'add_product_to_session' => \Illuminate\Support\Facades\Route::has('admin.orders.addProductToSession')
                ? route('admin.orders.addProductToSession') : null,
            'delete_product_from_session' => \Illuminate\Support\Facades\Route::has('admin.orders.delete-product-from-session')
                ? route('admin.orders.delete-product-from-session') : null,
            'update_edit_order' => \Illuminate\Support\Facades\Route::has('admin.orders.update-edit-order')
                ? route('admin.orders.update-edit-order') : null,
            'edit_order' => $orderEditApiEnabled
                ? route('admin.orders.edit', ['id' => $order->id]) : null,
        ];
        $showEditOrderButton = $orderEditApiEnabled
            && ($order->type ?? '') != 'pos'
            && ($order->payment_status ?? '') != 'paid'
            && in_array($order->order_status ?? '', ['pending', 'confirmed', 'processing'], true)
            && (($order->payment_method ?? '') == 'cash_on_delivery' && ! $order->order_partial_payments()->exists());
        $hasOrderChangeAmount = $order->order_change_amount()->exists();
        $showFoodPreparationControls = ($order->order_type ?? '') != 'pos'
            && ($order->order_type ?? '') != 'take_away'
            && ! in_array($order->order_status ?? '', [DELIVERED, RETURNED, CANCELED, FAILED, COMPLETED], true);
        $showPaymentReferenceFields = ! in_array($order->payment_method ?? '', ['cash_on_delivery', 'wallet_payment', 'offline_payment'], true);
        $hasOfflinePayment = ! empty($order->offline_payment);
        $showOfflinePaymentCard = $hasOfflinePayment && ! empty($offlinePaymentData);
        $hasBranch = ! empty($order->branch);
        $branchImage = optional($order->branch)->image;
        $branchName = optional($order->branch)->name;
        $branchPhone = optional($order->branch)->phone;
        $branchEmail = optional($order->branch)->email;
        $hasBranchPhone = $hasBranch && ! empty($order->branch['phone']);
        $hasOrderArea = ! empty($order->order_area);
        $hasAddressMap = isset($address['address'], $address['latitude'], $address['longitude']);
        $hasCustomer = ! empty($order->customer);
        $isGuestCustomer = (int) ($order->is_guest ?? 0) === 1;
        $isWalkingCustomer = $order->user_id === null;
        $isMissingCustomer = $order->user_id !== null && ! $hasCustomer;
        $subTotal = 0;
        $totalTax = 0;
        $totalDisOnPro = 0;
        $addOnsCost = 0;
        $addOnTax = 0;
        $addOnsTaxCost = 0;
        $orderDetailsCount = $order->details->count();
        $hasPartialPayments = $order->order_partial_payments->isNotEmpty();
        $deliveryFee = ($order->order_type ?? '') == 'take_away' ? 0 : (float) ($order->delivery_charge ?? 0);
        $selectedOrderAreaId = optional($order->order_area)->area_id;
        if (! isset($whatsappShareUrl)) {
            $whatsappMessage = \App\CentralLogics\AdminOrderWhatsAppMessage::build($order, $order->address ?? $address);
            $whatsappShareUrl = \App\CentralLogics\AdminOrderWhatsAppMessage::shareUrl($whatsappMessage);
        }
    @endphp
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2 flex-grow-1">
                <img width="20" class="avatar-img" src="{{asset('public/assets/admin/img/icons/order_details.png')}}" alt="">
                <span class="page-header-title">{{translate('Order_Details')}}</span>
                <span class="badge badge-soft-dark rounded-50 fz-14 mb-0">{{ $orderDetailsCount }}</span>
            </h2>
            @if($showEditOrderButton)
                <button class="btn btn-outline--info font-weight-semibold"
                        id="edit-order-button"
                        data-toggle="modal"
                        data-target="#confirmEditProductModal">
                    <i class="tio-edit"></i> {{translate('Edit_Order')}}
                </button>
            @endif
            <a id="send-whatsapp-order-btn"
               class="btn btn-success font-weight-semibold"
               href="{{ $whatsappShareUrl }}"
               target="_blank"
               rel="noopener noreferrer"
               title="{{ translate('Send order summary to WhatsApp') }}">
                <i class="tio-comment vs-1"></i>
                <span class="d-none d-sm-inline">{{ translate('Send to WhatsApp') }}</span>
                <span class="d-inline d-sm-none">{{ translate('WhatsApp') }}</span>
            </a>
            <a class="btn btn-primary" href={{route('admin.orders.generate-invoice',[$order['id']])}}>
                <i class="tio-print"></i> {{translate('Print_Invoice')}}
            </a>
        </div>
        <div class="row" id="printableArea">
            <div class="col-lg-8 mb-3 mb-lg-0">
                <div class="card mb-3 mb-lg-5">
                    <div class="px-card py-3">
                        <div class="row gy-2">
                            <div class="col-sm-7 d-flex flex-column justify-content-between">
                                <div>
                                    <h2 class="page-header-title h1 mb-3">{{translate('order')}} #{{ \App\CentralLogics\Helpers::order_display_id($order) }}</h2>
                                    <div class="d-flex gap-2 align-items-center flex-wrap mb-3">
                                        <h5 class="text-capitalize mb-0">
                                            <i class="tio-shop text-primary"></i>
                                            {{translate('branch')}} :
                                            <label class="badge-soft-success px-2 py-1 rounded mb-0">
                                                {{$order->branch?$order->branch->name:'Branch deleted!'}}
                                            </label>
                                        </h5>
                                    </div>

                                    <div class="mt-2 d-flex flex-column">
                                        @if($order['order_type'] == 'dine_in')
                                            <div class="hs-unfold">
                                                <h5 class="text-capitalize">
                                                    <i class="tio-table"></i>
                                                    {{translate('table no')}} : <label
                                                        class="badge badge-secondary">{{$order->table?$order->table->number:'Table deleted!'}}</label>
                                                </h5>
                                            </div>
                                            @if($order['number_of_people'] != null)
                                                <div class="hs-unfold">
                                                    <h5 class="text-capitalize">
                                                        <i class="tio-user"></i>
                                                        {{translate('number of people')}} : <label
                                                            class="badge badge-secondary">{{$order->number_of_people}}</label>
                                                    </h5>
                                                </div>
                                            @endif
                                        @endif
                                    </div>
                                    <div class="">
                                        {{translate('Order_Date_&_Time')}}: <i
                                            class="tio-date-range"></i>{{date('d M Y',strtotime($order['created_at']))}} {{ date(config('time_format'), strtotime($order['created_at'])) }}
                                    </div>
                                </div>
                                @if($order['order_note'] || $order['bring_change_amount'])
                                <div>
                                    @if($order['order_note'])
                                        <h5>{{translate('order')}} {{translate('note')}} : {{$order['order_note']}}</h5>
                                    @endif
                                    @if($order['bring_change_amount'])
                                        <h5>{{ translate('Bring Change Note') }}: <span
                                                class="badge-soft-success">{{translate('Please ensure the Deliveryman has '). \App\CentralLogics\Helpers::set_symbol($order['bring_change_amount']) . translate(' in change ready for the customer')}}</span>
                                        </h5>
                                    @endif
                                </div>
                                @endif
                            </div>
                            <div class="col-sm-5">
                                <div class="text-sm-right fz-12">
                                    <div class="d-flex gap-3 justify-content-sm-end my-3">
                                        <div class="text-dark font-weight-semibold">{{translate('Status')}} :</div>
                                        @if($order['order_status']=='pending')
                                            <span
                                                class="badge-soft-info px-2 rounded text-capitalize">{{translate('pending')}}</span>
                                        @elseif($order['order_status']=='confirmed')
                                            <span
                                                class="badge-soft-info px-2 rounded text-capitalize">{{translate('confirmed')}}</span>
                                        @elseif($order['order_status']=='processing')
                                            <span
                                                class="badge-soft-warning px-2 rounded text-capitalize">{{translate('processing')}}</span>
                                        @elseif($order['order_status']=='out_for_delivery')
                                            <span
                                                class="badge-soft-warning px-2 rounded text-capitalize">{{translate('out_for_delivery')}}</span>
                                        @elseif($order['order_status']=='delivered')
                                            <span
                                                class="badge-soft-success px-2 rounded text-capitalize">{{translate('delivered')}}</span>
                                        @elseif($order['order_status']=='failed')
                                            <span
                                                class="badge-soft-danger px-2 rounded text-capitalize">{{translate('failed_to_deliver')}}</span>
                                        @else
                                            <span
                                                class="badge-soft-danger px-2 rounded text-capitalize">{{str_replace('_',' ',$order['order_status'])}}</span>
                                        @endif
                                    </div>


                                    <div class="text-capitalize d-flex gap-3 justify-content-sm-end mb-3">
                                        <span>{{translate('payment')}} {{translate('method')}} :</span>
                                        <span class="text-dark">{{str_replace('_',' ',$order['payment_method'])}}</span>
                                    </div>

                                    @if($showPaymentReferenceFields)
                                        @if($order['transaction_reference']==null && $order['order_type']!='pos' && $order['order_type'] != 'dine_in')
                                            <div class="d-flex gap-3 justify-content-sm-end align-items-center mb-3">
                                                {{translate('reference')}} {{translate('code')}} :
                                                <button class="btn btn-outline-primary px-3 py-1" data-toggle="modal"
                                                        data-target=".bd-example-modal-sm">
                                                    {{translate('add')}}
                                                </button>
                                            </div>
                                        @elseif($order['order_type']!='pos' && $order['order_type'] != 'dine_in')
                                            <div class="d-flex gap-3 justify-content-sm-end align-items-center mb-3">
                                                {{translate('reference')}} {{translate('code')}}
                                                : {{$order['transaction_reference']}}
                                            </div>
                                        @endif
                                    @endif


                                    <div class="d-flex gap-3 justify-content-sm-end mb-3">
                                        <div>{{translate('Payment_Status')}} :</div>
                                        @if($order['payment_status']=='paid')
                                            <span
                                                class="badge-soft-success px-2 rounded text-capitalize">{{translate('paid')}}</span>
                                        @elseif($order['payment_status']=='partial_paid')
                                            <span
                                                class="badge-soft-success px-2 rounded text-capitalize">{{translate('partial_paid')}}</span>
                                        @else
                                            <span
                                                class="badge-soft-danger px-2 rounded text-capitalize">{{translate('unpaid')}}</span>
                                        @endif
                                    </div>

                                    <div class="d-flex gap-3 justify-content-sm-end mb-3 text-capitalize">
                                        {{translate('order')}} {{translate('type')}}
                                        : <label class="badge-soft-info px-2 rounded">
                                            {{str_replace('_',' ',$order['order_type'])}}
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="py-4 table-responsive">
                        <table
                            class="table table-hover table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                            <thead class="thead-light">
                            <tr>
                                <th>{{translate('SL')}}</th>
                                <th>{{translate('Item Details')}}</th>
                                <th>{{translate('Price')}}</th>
                                <th>{{translate('Discount')}}</th>
                                <th>{{translate('Tax')}}</th>
                                <th class="text-right">{{translate('Total_price')}}</th>
                            </tr>
                            </thead>

                            <tbody>
                            <tr>
                            </tr>
                            @foreach($order->details as $detail)
                                @php
                                    $productDetails = json_decode($detail['product_details'] ?? '{}', true);
                                    if (! is_array($productDetails)) {
                                        $productDetails = [];
                                    }
                                    $productImageFile = optional($detail->product)->image ?? ($productDetails['image'] ?? null);
                                    $productImageUrl = \App\CentralLogics\Helpers::public_storage_image_url(
                                        $productImageFile,
                                        'product',
                                        'public/assets/admin/img/160x160/img2.jpg'
                                    );
                                    $productDisplayName = $productDetails['name']
                                        ?? (optional($detail->product)->name ?? translate('Product unavailable'));
                                    $addOnQtys = json_decode($detail['add_on_qtys'] ?? '[]', true) ?: [];
                                    $addOnPrices = json_decode($detail['add_on_prices'] ?? '[]', true) ?: [];
                                    $addOnTaxes = json_decode($detail['add_on_taxes'] ?? '[]', true) ?: [];
                                    $variations = json_decode($detail['variation'] ?? '[]', true);
                                    if (! is_array($variations)) {
                                        $variations = [];
                                    }
                                    $addon_ids = json_decode($detail['add_on_ids'] ?? '[]', true);
                                    if (! is_array($addon_ids)) {
                                        $addon_ids = [];
                                    }
                                    $hasVariations = count($variations) > 0;
                                    $hasLegacyVariationShape = ! empty($variations[0]);
                                    $hasAddonIds = count($addon_ids) > 0;
                                    $amount = $detail['price'] * $detail['quantity'];
                                    $totDiscount = $detail['discount_on_product'] * $detail['quantity'];
                                    $productTax = $detail['tax_amount'] * $detail['quantity'];
                                    $totalDisOnPro += $totDiscount;
                                    $subTotal += $amount;
                                    $totalTax += $productTax;
                                @endphp

                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>
                                        <div class="media gap-3 w-max-content">

                                            <img class="img-fluid avatar avatar-lg"
                                                 src="{{ $productImageUrl }}"
                                                 onerror="this.src='{{ $munchImgProduct }}'"
                                                 alt="Image Description">

                                            <div class="media-body text-dark fz-12">
                                                <h6 class="text-capitalize">{{ $productDisplayName }}</h6>
                                                <div class="d-flex gap-2">
                                                    @if($hasVariations)
                                                        @foreach($variations as $variation)
                                                            @php
                                                                $hasNamedVariationValues = ! empty($variation['name']) && ! empty($variation['values']);
                                                            @endphp
                                                            @if($hasNamedVariationValues)
                                                                <span class="d-block text-capitalize">
                                                                <strong>{{  $variation['name']}} -</strong>
                                                            </span>
                                                                @php
                                                                    $variationValues = is_array($variation['values'] ?? null) ? $variation['values'] : [];
                                                                @endphp
                                                                @foreach($variationValues as $value)

                                                                    <span class="d-block text-capitalize">
                                                                     {{ $value['label']}} :
                                                                    <strong>{{Helpers::set_symbol( $value['optionPrice'])}}</strong>
                                                                </span>
                                                                @endforeach
                                                            @else
                                                                @if($hasLegacyVariationShape)
                                                                    <strong><u> {{  translate('Variation') }}
                                                                            : </u></strong>
                                                                    @foreach($variations[0] as $key1 =>$variation)
                                                                        <div class="font-size-sm text-body">
                                                                            <span>{{$key1}} :  </span>
                                                                            <span
                                                                                class="font-weight-bold">{{$variation}}</span>
                                                                        </div>
                                                                    @endforeach
                                                                @endif
                                                            @endif
                                                        @endforeach
                                                    @else
                                                        <div class="font-size-sm text-body">
                                                            <span
                                                                class="text-dark">{{translate('price')}}  : {{Helpers::set_symbol($detail['price'])}}</span>
                                                        </div>
                                                    @endif

                                                    <div class="d-flex gap-2">
                                                        <span class="">{{translate('Qty')}} :  </span>
                                                        <span>{{$detail['quantity']}}</span>
                                                    </div>

                                                    <br>
                                                    @if($hasAddonIds)
                                                        <span>
                                                        <u><strong>{{translate('addons')}}</strong></u>
                                                        @foreach($addon_ids as $key2 =>$id)
                                                                @php
                                                                    $addon = \App\Model\AddOn::find($id);
                                                                    $add_on_qty = $addOnQtys[$key2] ?? 1;
                                                                @endphp

                                                                <div class="font-size-sm text-body">
                                                                    <span>{{$addon ? $addon['name'] : translate('addon deleted')}} :  </span>
                                                                    <span class="font-weight-semibold">
                                                                        {{$add_on_qty}} x {{ Helpers::set_symbol($addOnPrices[$key2] ?? 0) }} <br>
                                                                    </span>
                                                                </div>
                                                                @php
                                                                    $addOnsCost += ($addOnPrices[$key2] ?? 0) * $add_on_qty;
                                                                    $addOnsTaxCost += ($addOnTaxes[$key2] ?? 0) * $add_on_qty;
                                                                @endphp
                                                            @endforeach
                                                    </span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        {{Helpers::set_symbol($amount)}}
                                    </td>
                                    <td>
                                        {{Helpers::set_symbol($totDiscount)}}
                                    </td>
                                    <td>
                                        {{Helpers::set_symbol($productTax + $detail['add_on_tax_amount'])}}
                                    </td>
                                    <td class="text-right">{{Helpers::set_symbol($amount-$totDiscount + $productTax)}}</td>
                                </tr>

                            @endforeach
                            </tbody>
                        </table>
                    </div>


                    <div class="card-body pt-0">
                        <hr>
                        <div class="row justify-content-md-end mb-3">
                            <div class="col-md-9 col-lg-8">
                                <dl class="row">
                                    <dt class="col-6">
                                        <div class="d-flex max-w220 ml-auto">
                                            {{translate('items')}} {{translate('price')}} <span>:</span>
                                        </div>
                                    </dt>
                                    <dd class="col-6 text-dark text-right">{{ Helpers::set_symbol($subTotal) }}</dd>

                                    <dt class="col-6">
                                        <div class="d-flex max-w220 ml-auto">
                                            <span>{{translate('item')}} {{translate('discount')}}</span>
                                            <span>:</span>
                                        </div>
                                    </dt>
                                    <dd class="col-6 text-dark text-right">
                                        - {{ Helpers::set_symbol($totalDisOnPro) }}</dd>

                                    <dt class="col-6">
                                        <div class="d-flex max-w220 ml-auto">
                                            <span>{{translate('addon')}} {{translate('cost')}}</span>
                                            <span>:</span>
                                        </div>
                                    </dt>
                                    <dd class="col-6 text-dark text-right">
                                        {{ Helpers::set_symbol($addOnsCost) }}
                                    </dd>

                                    <dt class="col-6">
                                        <div class="d-flex max-w220 ml-auto">
                                            <span>{{translate('coupon')}} {{translate('discount')}}</span>
                                            <span>:</span>
                                        </div>
                                    </dt>
                                    <dd class="col-6 text-dark text-right">
                                        - {{ Helpers::set_symbol($order['coupon_discount_amount']) }}</dd>

                                    <dt class="col-6">
                                        <div class="d-flex max-w220 ml-auto">
                                            <span>{{translate('extra discount')}} </span>
                                            <span>:</span>
                                        </div>
                                    </dt>
                                    <dd class="col-6 text-dark text-right">
                                        - {{ Helpers::set_symbol($order['extra_discount']) }}</dd>

                                    <dt class="col-6">
                                        <div class="d-flex max-w220 ml-auto">
                                            <span>{{translate('referral discount')}} </span>
                                            <span>:</span>
                                        </div>
                                    </dt>
                                    <dd class="col-6 text-dark text-right">
                                        - {{ Helpers::set_symbol($order['referral_discount']) }}</dd>

                                    <dt class="col-6">
                                        <div class="d-flex max-w220 ml-auto">
                                            <span>{{translate('tax')}} / {{translate('vat')}}</span>
                                            <span>:</span>
                                        </div>
                                    </dt>
                                    <dd class="col-6 text-dark text-right">{{ Helpers::set_symbol($totalTax + $addOnsTaxCost) }}</dd>

                                    <dt class="col-6">
                                        <div class="d-flex max-w220 ml-auto">
                                            <span>
                                        {{translate('subtotal')}}</span>
                                            <span>:</span>
                                        </div>
                                    </dt>
                                    <dd class="col-6 text-dark text-right">
                                        {{ Helpers::set_symbol($subTotal = $subTotal+$totalTax+$addOnsCost-$totalDisOnPro + $addOnsTaxCost - $order['coupon_discount_amount'] - $order['extra_discount'] - $order['referral_discount']) }}</dd>

                                    <dt class="col-6">
                                        <div class="d-flex max-w220 ml-auto">
                                            <span>
                                                {{translate('delivery')}} {{translate('fee')}}</span>
                                            <span>:</span>
                                        </div>
                                    </dt>
                                    <dd class="col-6 text-dark text-right">
                                        {{ Helpers::set_symbol($deliveryFee) }}
                                    </dd>

                                    <dt class="col-6 border-top pt-2 fz-16 font-weight-bold">
                                        <div class="d-flex max-w220 ml-auto">
                                            <span>{{translate('total')}}</span>
                                            <span>:</span>
                                        </div>
                                    </dt>
                                    <dd class="col-6 border-top pt-2 fz-16 font-weight-bold text-dark text-right">{{ Helpers::set_symbol($subTotal + $deliveryFee) }}</dd>

                                    @if($hasPartialPayments)
                                        @foreach($order->order_partial_payments as $partial)
                                            <dt class="col-6">
                                                <div class="d-flex max-w220 ml-auto">
                                            <span>
                                                {{translate('Paid By')}} ({{str_replace('_', ' ',$partial->paid_with)}})</span>
                                                    <span>:</span>
                                                </div>
                                            </dt>
                                            <dd class="col-6 text-dark text-right">
                                                {{ Helpers::set_symbol($partial->paid_amount) }}
                                            </dd>
                                        @endforeach
                                            @php
                                                $due_amount = optional($order->order_partial_payments->first())->due_amount ?? 0;
                                            @endphp
                                        <dt class="col-6">
                                            <div class="d-flex max-w220 ml-auto">
                                            <span>
                                                {{translate('Due Amount')}}</span>
                                                <span>:</span>
                                            </div>
                                        </dt>
                                        <dd class="col-6 text-dark text-right">
                                            {{ Helpers::set_symbol($due_amount) }}
                                        </dd>
                                    @endif

                                    @if($hasOrderChangeAmount)
                                        <dt class="col-6">
                                            <div class="d-flex max-w220 ml-auto">
                                                <span>{{ translate('paid_amount') }}</span><span>:</span>
                                            </div>
                                        </dt>
                                        <dd class="col-6 text-dark text-right">{{ Helpers::set_symbol($orderChangePaidAmount) }}</dd>

                                        @php
                                            $changeOrDueAmount = $orderChangePaidAmount - $orderChangeOrderAmount;
                                        @endphp
                                        <dt class="col-6">
                                            <div class="d-flex max-w220 ml-auto">
                                                <span>{{$changeOrDueAmount < 0 ? translate('due_amount') : translate('change_amount') }}</span><span>:</span>
                                            </div>
                                        </dt>
                                        <dd class="col-6 text-dark text-right">{{ Helpers::set_symbol($changeOrDueAmount) }}</dd>
                                    @endif
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                @if($order['order_type'] != 'pos')
                    <div class="card mb-3">
                        <div class="card-body text-capitalize d-flex flex-column gap-4">
                            <h4 class="mb-0 text-center">{{translate('Order_Setup')}}</h4>

                            @if($hasOfflinePayment)
                                <div class="card mt-3">
                                    <div class="card-body text-center">
                                        @if($offlinePaymentStatus == 1)
                                            <h4 class="">{{ translate('Payment_verified') }}</h4>
                                        @else
                                            <h4 class="">{{ translate('Payment_verification') }}</h4>
                                            <p class="text-danger">{{ translate('please verify the payment before confirm order') }}</p>
                                            <div class="mt-3">
                                                <button class="btn btn-primary" type="button"
                                                        data-id="{{ $order['id'] }}"
                                                        data-target="#payment_verify_modal"
                                                        data-toggle="modal">{{ translate('Verify_Payment') }}
                                                </button>
                                            </div>
                                        @endif

                                    </div>
                                </div>
                            @endif

                            @if($order['order_type'] != 'pos')
                                @include('partials.order-operations._online-order-timer-panel')
                                @include('admin-views.order.partials._workflow-actions', ['statusRoute' => 'admin.orders.status'])
                                <div>
                                    <div class="d-flex justify-content-between align-items-center gap-10 form-control">
                                        <span class="title-color">{{ translate('Payment Status') }}</span>
                                        @if($order['payment_method'] == 'offline_payment' && $offlinePaymentStatus != 1)
                                            <label class="switcher payment-status-text">
                                                <input class="switcher_input offline-payment-status-alert"
                                                       type="checkbox" name="payment_status" value="1"
                                                       id="payment_status_switch"
                                                    {{$order->payment_status == 'paid' ?'checked':''}}>
                                                <span class="switcher_control"></span>
                                            </label>
                                        @else
                                            <label class="switcher payment-status-text">
                                                <input class="switcher_input change-payment-status" type="checkbox"
                                                       name="payment_status" value="1"
                                                       data-id="{{ $order['id'] }}"
                                                       data-status="{{ $order->payment_status == 'paid' ?'unpaid':'paid' }}"
                                                    {{$order->payment_status == 'paid' ?'checked':''}}>
                                                <span class="switcher_control"></span>
                                            </label>
                                        @endif
                                    </div>
                                </div>
                            @endif
                            @if($order->customer || $order->is_guest == 1)
                                @if($order['order_type']!='take_away' && $order['order_type'] != 'pos' && $order['order_type'] != 'dine_in' && !$order['delivery_man_id'])

                                    <a href="#"
                                       class="btn btn-primary btn-block d-flex gap-1 justify-content-center align-items-center"
                                       data-toggle="modal" data-target="#assignDeliveryMan">
                                        <img width="17"
                                             src="{{asset('public/assets/admin/img/icons/assain_delivery_man.png')}}"
                                             alt="">
                                        {{translate('Assign_Delivery_Man')}}
                                    </a>
                                @endif
                            @endif


                            @if($order->delivery_man_id && $order->delivery_man)
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h4 class="mb-4 d-flex gap-2">
                                    <span class="card-header-icon">
                                        <i class="tio-user text-dark"></i>
                                    </span>
                                            <span>{{ translate('delivery_man') }}</span>
                                            <a href="#" data-toggle="modal" data-target="#assignDeliveryMan"
                                               class="text--base cursor-pointer ml-auto">
                                                {{translate('Change')}}
                                            </a>
                                        </h4>
                                        <div class="media flex-wrap gap-3">
                                            <a>
                                                <img class="avatar avatar-lg rounded-circle"
                                                     src="{{ \App\CentralLogics\Helpers::public_storage_image_url($order->delivery_man->image ?? null, 'delivery-man', 'public/assets/admin/img/160x160/img1.jpg') }}"
                                                     onerror="this.src='{{ $munchImgPerson }}'"
                                                     alt="Image">
                                            </a>
                                            <div class="media-body d-flex flex-column gap-1">
                                                <a target="" href="#"
                                                   class="text-dark"><span>{{ trim(($order->delivery_man->f_name ?? '').' '.($order->delivery_man->l_name ?? '')) }}</span></a>
                                                <span
                                                    class="text-dark"> <span>{{ $order->delivery_man->orders_count ?? 0 }}</span> {{translate('Orders')}}</span>
                                                <span class="text-dark break-all">
                                            <i class="tio-call-talking-quiet mr-2"></i>
                                            <a href="tel:{{ $order->delivery_man->phone ?? '' }}"
                                               class="text-dark">{{ $order->delivery_man->phone ?? '' }}</a>
                                        </span>
                                                <span class="text-dark break-all">
                                            <i class="tio-email mr-2"></i>
                                            <a href="mailto:{{ $order->delivery_man->email ?? '' }}"
                                               class="text-dark">{{ $order->delivery_man->email ?? '' }}</a>
                                        </span>
                                            </div>
                                        </div>
                                        <hr class="w-100">
                                        @if($order['order_status']=='out_for_delivery')
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h5>{{translate('Last_location')}}</h5>
                                            </div>
                                            @php
                                                $origin = \App\Model\DeliveryHistory::where(['deliveryman_id' => $order['delivery_man_id'], 'order_id' => $order['id']])->first();
                                                $current = \App\Model\DeliveryHistory::where(['deliveryman_id' => $order['delivery_man_id'], 'order_id' => $order['id']])->latest()->first();
                                                $hasDeliveryOriginAndCurrent = ! empty($origin) && ! empty($current);
                                            @endphp
                                            @if($hasDeliveryOriginAndCurrent)
                                                <a target="_blank" class="text-dark"
                                                   title="Delivery Boy Last Location" data-toggle="tooltip"
                                                   data-placement="top"
                                                   href="http://maps.google.com/maps?z=12&t=m&q=loc:{{$current['latitude']}}+{{$current['longitude']}}">
                                                    <img width="13"
                                                         src="{{asset('public/assets/admin/img/icons/location.png')}}"
                                                         alt="">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; {{$current['location']?? ''}}
                                                </a>
                                            @else
                                                <a href="javascript:" data-toggle="tooltip" class="text-dark"
                                                   data-placement="top"
                                                   title="{{translate('Waiting for location...')}}">
                                                    <img width="13"
                                                         src="{{asset('public/assets/admin/img/icons/location.png')}}"
                                                         alt="">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; {{translate('Waiting for location...')}}
                                                </a>
                                            @endif
                                        @else
                                            <a href="javascript:" class="text-dark last-location-view"
                                               data-toggle="tooltip" data-placement="top"
                                               title="{{translate('Only available when order is out for delivery!')}}">
                                                <img width="13"
                                                     src="{{asset('public/assets/admin/img/icons/location.png')}}"
                                                     alt="">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; {{translate('Only available when order is out for delivery!')}}
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            @if($order['order_type']!='take_away' && $order['order_type'] != 'pos' && $order['order_type'] != 'dine_in')
                                <div class="card">
                                    <div class="card-body">
                                        <div class="mb-4 d-flex gap-2 justify-content-between">
                                            <h4 class="mb-0 d-flex gap-2">
                                                <i class="tio-user text-dark"></i>
                                                {{translate('Delivery_Information')}}
                                            </h4>

                                            <div class="edit-btn cursor-pointer" data-toggle="modal"
                                                 data-target="#deliveryInfoModal">
                                                <i class="tio-edit"></i>
                                            </div>
                                        </div>
                                        <div class="delivery--information-single flex-column">
                                            <div class="d-flex">
                                                <div class="name">{{ translate('Name') }}</div>
                                                <div
                                                    class="info">{{ $address? $address['contact_person_name']: '' }}</div>
                                            </div>
                                            <div class="d-flex">
                                                <div class="name">{{translate('Contact')}}</div>
                                                <a href="tel:{{ $address? $address['contact_person_number']: '' }}"
                                                   class="info">{{ $address? $address['contact_person_number']: '' }}</a>
                                            </div>
                                            <div class="d-flex">
                                                <div class="name">{{translate('address')}}</div>
                                                <div class="info">{{$address['address'] ?? ''}}</div>
                                            </div>
                                            @if($hasOrderArea && ! empty($orderAreaName))
                                                <div class="d-flex">
                                                    <div class="name">{{translate('Area')}}</div>
                                                    <div class="info edit-btn cursor-pointer">
                                                        {{ $orderAreaName }}
                                                        @if($branchDeliveryChargeType == 'area')
                                                            <i class="tio-edit" data-toggle="modal"
                                                               data-target="#editArea"></i>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endif
                                            @if($googleMapStatus)
                                                @if($hasAddressMap)
                                                    <hr class="w-100">
                                                    <div class="d-flex align-items-center gap-3">
                                                        <a target="_blank" class="text-dark"
                                                           href="http://maps.google.com/maps?z=12&t=m&q=loc:{{$address['latitude']}}+{{$address['longitude']}}">
                                                            <img width="13"
                                                                 src="{{asset('public/assets/admin/img/icons/location.png')}}"
                                                                 alt="">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                                            {{$address['address']}}
                                                        </a>
                                                    </div>
                                                @endif
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endif

                            @if($order['order_type']=='take_away' && $order['order_type'] != 'pos' && $order['order_type'] != 'dine_in')
                                <div class="card">
                                    <div class="card-body">
                                        <div class="mb-4 d-flex gap-2 justify-content-between">
                                            <h4 class="mb-0 d-flex gap-2">
                                                <i class="tio-user text-dark"></i>
                                                {{translate('Contact_Information')}}
                                            </h4>
                                        </div>
                                        <div class="delivery--information-single flex-column">
                                            <div class="d-flex">
                                                <div class="name">{{ translate('Name') }}</div>
                                                <div
                                                    class="info">{{ $address? $address['contact_person_name']: '' }}</div>
                                            </div>
                                            <div class="d-flex">
                                                <div class="name">{{translate('Contact')}}</div>
                                                <a href="tel:{{ $address? $address['contact_person_number']: '' }}"
                                                   class="info">{{ $address? $address['contact_person_number']: '' }}</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif

                        </div>
                    </div>
                @endif

                @if($showOfflinePaymentCard)

                    <div class="card mt-2">
                        <div class="card-body">
                            <h5 class="form-label mb-3">
                                <span class="card-header-icon"><i class="tio-shopping-basket"></i></span>
                                <span>{{translate('Offline payment information')}}</span>
                            </h5>
                            <div class="offline-payment--information-single flex-column mt-3">
                                <div class="d-flex">
                                    <span class="name">{{ translate('payment_note') }}</span>
                                    <span class="info">{{ $offlinePaymentData['payment_note'] ?? '' }}</span>
                                </div>
                                @foreach($offlinePaymentMethodInformation as $infos)
                                    @foreach($infos as $info_key => $info)
                                        <div class="d-flex">
                                            <span class="name">{{ $info_key }}</span>
                                            <span class="info">{{ $info }}</span>
                                        </div>
                                    @endforeach
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif


                <div class="card mb-3">
                    <div class="card-body">
                        <h4 class="mb-4 d-flex gap-2">
                            <i class="tio-user text-dark"></i>
                            {{ translate('Customer Information') }}
                        </h4>
                        @if($isGuestCustomer)
                            <div class="media flex-wrap gap-3 align-items-center">
                                <a target="#" class="">
                                    <img class="avatar avatar-lg rounded-circle"
                                         src="{{asset('public/assets/admin/img/160x160/img1.jpg')}}" alt="">
                                </a>
                                <div class="media-body d-flex flex-column gap-1">
                                    <a target="#"
                                       class="text-dark text-capitalize"><strong>{{translate('Guest Customer')}}</strong></a>
                                </div>
                            </div>
                        @else
                            @if($hasCustomer)
                                <div class="media flex-wrap gap-3">
                                    <a target="_blank" class=""
                                       href="{{route('admin.customer.view',[$order->customer['id']])}}">
                                        <img class="avatar avatar-lg rounded-circle"
                                             src="{{ \App\CentralLogics\Helpers::user_storage_image_url($order->customer) }}"
                                             onerror="this.src='{{ $munchImgPerson }}'"
                                             alt="Image">
                                    </a>
                                    <div class="media-body d-flex flex-column gap-1">
                                        <a target="_blank"
                                           href="{{route('admin.customer.view',[$order->customer['id']])}}"
                                           class="text-dark"><strong>{{ trim(($order->customer['f_name'] ?? '').' '.($order->customer['l_name'] ?? '')) }}</strong></a>
                                        <span
                                            class="text-dark">{{ $order->customer['orders_count'] ?? 0 }} {{translate('Orders')}}</span>
                                        <span class="text-dark">
                                            <i class="tio-call-talking-quiet mr-2"></i>
                                            <a class="text-dark break-all"
                                               href="tel:{{ $order->customer['phone'] ?? '' }}">{{ $order->customer['phone'] ?? '' }}</a>
                                        </span>
                                        <span class="text-dark">
                                            <i class="tio-email mr-2"></i>
                                            <a class="text-dark break-all"
                                               href="mailto:{{ $order->customer['email'] ?? '' }}">{{ $order->customer['email'] ?? '' }}</a>
                                        </span>
                                    </div>
                                </div>
                            @endif
                            @if($isWalkingCustomer)
                                <div class="media flex-wrap gap-3 align-items-center">
                                    <a target="#" class="">
                                        <img class="avatar avatar-lg rounded-circle"
                                             src="{{asset('public/assets/admin/img/160x160/img1.jpg')}}" alt="">
                                    </a>
                                    <div class="media-body d-flex flex-column gap-1">
                                        <a target="#"
                                           class="text-dark text-capitalize"><strong>{{translate('walking_customer')}}</strong></a>
                                    </div>
                                </div>
                            @endif
                            @if($isMissingCustomer)
                                <div class="media flex-wrap gap-3 align-items-center">
                                    <a target="#" class="">
                                        <img class="avatar avatar-lg rounded-circle"
                                             src="{{asset('public/assets/admin/img/160x160/img1.jpg')}}" alt="">
                                    </a>
                                    <div class="media-body d-flex flex-column gap-1">
                                        <a target="#"
                                           class="text-dark text-capitalize"><strong>{{translate('Customer_not_available')}}</strong></a>
                                    </div>
                                </div>
                            @endif
                        @endif

                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body">
                        <h4 class="mb-4 d-flex gap-2">
                            <i class="tio-user text-dark"></i>
                            {{translate('Branch Information')}}
                        </h4>
                        <div class="media flex-wrap gap-3">
                            <div class="">
                                <img class="avatar avatar-lg rounded-circle"
                                     src="{{ \App\CentralLogics\Helpers::public_storage_image_url($branchImage, 'branch', 'public/assets/admin/img/160x160/img2.jpg') }}"
                                     onerror="this.src='{{ $munchImgProduct }}'"
                                     alt="Image">
                            </div>
                            <div class="media-body d-flex flex-column gap-1">
                                @if($hasBranch)
                                    <span class="text-dark"><span>{{ $branchName }}</span></span>
                                    <span class="text-dark"> <span>{{ $order->branch['orders_count'] ?? 0 }}</span> {{translate('Orders served')}}</span>
                                    @if($hasBranchPhone)
                                        <span class="text-dark break-all">
                                                <i class="tio-call-talking-quiet mr-2"></i>
                                                <a class="text-dark"
                                                   href="tel:{{ $branchPhone }}">{{ $branchPhone }}</a>
                                            </span>
                                    @endif
                                    <span class="text-dark break-all">
                                        <i class="tio-email mr-2"></i>
                                        <a class="text-dark"
                                           href="mailto:{{ $branchEmail }}">{{ $branchEmail }}</a>
                                    </span>
                                @else
                                    <span class="fz--14px text--title font-semibold text-hover-primary d-block">
                                            {{translate('Branch Deleted')}}
                                        </span>
                                @endif

                            </div>
                        </div>
                        @if($hasBranch)
                            <hr class="w-100">
                            <div class="d-flex align-items-center text-dark gap-3">
                                <img width="13" src="{{asset('public/assets/admin/img/icons/location.png')}}" alt="">
                                <a target="_blank" class="text-dark"
                                   href="http://maps.google.com/maps?z=12&t=m&q=loc:{{$order->branch['latitude']}}+{{$order->branch['longitude']}}">
                                    {{$order->branch['address']}}<br>
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="assignDeliveryMan" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title fs-5" id="assignDeliveryManLabel">{{translate('Assign_Delivery_Man')}}</h4>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <ul class="list-group">
                        @foreach($deliverymen as $deliveryMan)
                            <li class="list-group-item d-flex flex-wrap align-items-center gap-3 justify-content-between">
                                <div class="media align-items-center gap-2 flex-wrap">
                                    <div class="avatar">
                                        <img class="img-fit rounded-circle" loading="lazy" decoding="async"
                                             src="{{ \App\CentralLogics\Helpers::public_storage_image_url($deliveryMan->image ?? null, 'delivery-man', 'public/assets/admin/img/160x160/img1.jpg') }}"
                                             onerror="this.src='{{ $munchImgPerson }}'"
                                             alt="Jhon Doe">
                                    </div>
                                    <span>{{$deliveryMan['f_name'].' '.$deliveryMan['l_name']}}</span>
                                </div>
                                <a id="{{$deliveryMan->id}}"
                                   class="btn btn-primary btn-sm assign-deliveryman">{{translate('Assign')}}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade bd-example-modal-sm" tabindex="-1" role="dialog" aria-labelledby="mySmallModalLabel"
         aria-hidden="true">
        <div class="modal-dialog modal-sm" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title h4"
                        id="mySmallModalLabel">{{translate('reference')}} {{translate('code')}} {{translate('add')}}</h5>
                    <button type="button" class="btn btn-xs btn-icon btn-ghost-secondary" data-dismiss="modal"
                            aria-label="Close">
                        <i class="tio-clear tio-lg"></i>
                    </button>
                </div>

                <form action="{{route('admin.orders.add-payment-ref-code',[$order['id']])}}" method="post">
                    @csrf
                    <div class="modal-body">
                        <div class="form-group">
                            <input type="text" name="transaction_reference" class="form-control"
                                   placeholder="{{translate('EX : Code123')}}" required>
                        </div>
                        <button class="btn btn-primary">{{translate('submit')}}</button>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="deliveryInfoModal" id="deliveryInfoModal"
         aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title h4" id="mySmallModalLabel">{{translate('Update_Delivery_Information')}}</h5>
                    <button type="button" class="btn btn-xs btn-icon btn-ghost-secondary" data-dismiss="modal"
                            aria-label="Close">
                        <i class="tio-clear tio-lg"></i>
                    </button>
                </div>
                <form action="{{route('admin.orders.update-shipping')}}" method="post">
                    @csrf
                    <input type="hidden" name="user_id" value="{{$order->user_id}}">
                    <input type="hidden" name="order_id" value="{{$order->id}}">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label>{{translate('Type')}}</label>
                                    <input type="text" name="address_type" class="form-control"
                                           placeholder="{{translate('EX : Home')}}"
                                           value="{{ $address['address_type'] ?? '' }}" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="input-label" for="">{{ translate('contact_person_name') }}
                                        <span class="input-label-secondary text-danger">*</span></label>
                                    <input type="text" class="form-control" name="contact_person_name"
                                           placeholder="{{translate('EX : Jhon Doe')}}"
                                           value="{{ $address['contact_person_name'] ?? '' }}" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="input-label" for="">{{ translate('Contact Number') }}
                                        <span class="input-label-secondary text-danger">*</span></label>
                                    <input type="text" class="form-control" name="contact_person_number"
                                           placeholder="{{translate('EX : 01888888888')}}"
                                           value="{{ $address['contact_person_number']?? '' }}" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>{{translate('floor')}}</label>
                                    <input type="text" class="form-control" name="floor"
                                           placeholder="{{translate('EX : 5')}}" value="{{ $address['floor'] ?? '' }}">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>{{translate('house')}}</label>
                                    <input type="text" class="form-control" name="house"
                                           placeholder="{{translate('EX : 21/B')}}"
                                           value="{{ $address['house'] ?? '' }}">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>{{translate('road')}}</label>
                                    <input type="text" class="form-control" name="road"
                                           placeholder="{{translate('EX : Baker Street')}}"
                                           value="{{ $address['road'] ?? '' }}">
                                </div>
                            </div>

                            @if($googleMapStatus)
                                @if($branchDeliveryChargeType == 'distance')
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="input-label" for="">{{ translate('latitude') }}
                                                <span class="input-label-secondary text-danger">*</span></label>
                                            <input type="text" class="form-control" name="latitude"
                                                   placeholder="{{translate('EX : 23.796584198263794')}}"
                                                   value="{{ $address['latitude'] ?? '' }}">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="input-label" for="">{{ translate('longitude') }}<span
                                                    class="input-label-secondary text-danger">*</span></label>
                                            <input type="text" class="form-control" name="longitude"
                                                   placeholder="{{translate('EX : 23.796584198263794')}}"
                                                   value="{{ $address['longitude'] ?? '' }}" required>
                                        </div>
                                    </div>
                                @endif
                            @endif

                            <div class="col-md-12">
                                <div class="form-group">
                                    <label>{{translate('Address')}}<span
                                            class="input-label-secondary text-danger">*</span></label>
                                    <textarea class="form-control" name="address" cols="30" rows="3"
                                              placeholder="{{translate('EX : Dhaka,_Bangladesh')}}"
                                              required>{{ $address['address'] ?? '' }}</textarea>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end">
                            <button class="btn btn-primary">{{translate('submit')}}</button>
                        </div>
                    </div>
                </form>

            </div>
        </div>
    </div>

    @if($showFoodPreparationControls)
        <div class="modal fade" id="counter-change" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
             aria-hidden="true">
            <div class="modal-dialog modal-sm" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title custom-text-size"
                            id="exampleModalLabel">{{ translate('Need time to prepare the food') }}</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <form action="{{route('admin.orders.increase-preparation-time', ['id' => $order->id])}}"
                          method="post">
                        @csrf
                        <div class="modal-body">
                            <div class="form-group text-center">
                                <input type="number" min="0" name="extra_minute" id="extra_minute" class="form-control"
                                       placeholder="{{translate('EX : 20')}}" required>
                            </div>
                            <div class="form-group flex-between predefined-time-input">
                                <div class="badge text-info shadow li-pointer"
                                     data-time="10">{{ translate('10min') }}</div>
                                <div class="badge text-info shadow li-pointer"
                                     data-time="20">{{ translate('20min') }}</div>
                                <div class="badge text-info shadow li-pointer"
                                     data-time="30">{{ translate('30min') }}</div>
                                <div class="badge text-info shadow li-pointer"
                                     data-time="40">{{ translate('40min') }}</div>
                                <div class="badge text-info shadow li-pointer"
                                     data-time="50">{{ translate('50min') }}</div>
                                <div class="badge text-info shadow li-pointer"
                                     data-time="60">{{ translate('60min') }}</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary"
                                    data-dismiss="modal">{{ translate('Close') }}</button>
                            <button type="submit" class="btn btn-primary">{{ translate('Submit') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if($hasOfflinePayment)
        <div class="modal fade" id="payment_verify_modal">
            <div class="modal-dialog modal-lg offline-details">
                <div class="modal-content">
                    <div class="modal-header justify-content-center">
                        <h4 class="modal-title pb-2">{{translate('Payment_Verification')}}</h4>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span
                                aria-hidden="true">×</span></button>
                    </div>
                    <div class="card">
                        <div class="modal-body mx-2">
                            <p class="text-danger">{{translate('Please Check & Verify the payment information whether it is correct or not before confirm the order.')}}</p>
                            <h5>{{translate('customer_Information')}}</h5>

                            <div class="card-body">
                                @if(!$isGuestCustomer)
                                    <p>{{ translate('name') }}
                                        : {{ $order->customer ? $order->customer->f_name.' '. $order->customer->l_name: ''}} </p>
                                    <p>{{ translate('contact') }}
                                        : {{ $order->customer ? $order->customer->phone: ''}}</p>
                                @else
                                    <p>{{ translate('guest_customer') }} </p>
                                @endif
                            </div>

                            <h5>{{translate('Payment_Information')}}</h5>
                            <div class="row card-body">
                                <div class="col-md-6">
                                    <p>{{ translate('Payment_Method') }} : {{ $offlinePaymentData['payment_name'] ?? '' }}</p>
                                    @foreach($offlinePaymentMethodFields as $fields)
                                        @foreach($fields as $field_key => $field)
                                            <p>{{ $field_key }} : {{ $field }}</p>
                                        @endforeach
                                    @endforeach
                                </div>
                                <div class="col-md-6">
                                    <p>{{ translate('payment_note') }} : {{ $offlinePaymentData['payment_note'] ?? '' }}</p>
                                    @foreach($offlinePaymentMethodInformation as $infos)
                                        @foreach($infos as $info_key => $info)
                                            <p>{{ $info_key }} : {{ $info }}</p>
                                        @endforeach
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="btn--container justify-content-center my-2 mx-3">
                        @if($offlinePaymentStatus === 0)
                            <a type="reset" class="btn btn-secondary verify-offline-payment"
                               data-status="2">{{ translate('Payment_Did_Not_Received') }}</a>
                        @endif
                        @if($order->order_status != 'canceled')
                            <a type="submit" class="btn btn-primary verify-offline-payment"
                               data-status="1">{{ translate('Yes,_Payment_Received') }}</a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="editArea" id="editArea"
         aria-hidden="true">
        <div class="modal-dialog modal-md" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title h4" id="mySmallModalLabel">{{translate('Update_Delivery_Area')}}</h5>
                    <button type="button" class="btn btn-xs btn-icon btn-ghost-secondary" data-dismiss="modal"
                            aria-label="Close">
                        <i class="tio-clear tio-lg"></i>
                    </button>
                </div>
                <form action="{{ route('admin.orders.update-order-delivery-area', ['order_id' => $order->id]) }}"
                      method="post">
                    @csrf
                    <div class="modal-body">
                        <div class="row">

                            @php
                                $branch = \App\Model\Branch::with(['delivery_charge_setup', 'delivery_charge_by_area'])
                                    ->where(['id' => $order['branch_id']])
                                    ->first(['id', 'name', 'status']);
                                $deliveryAreas = optional($branch)->delivery_charge_by_area ?? collect();
                            @endphp

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>{{translate('Delivery Area')}}</label>
                                    <select name="selected_area_id" class="form-control js-select2-custom-x mx-1"
                                            id="areaDropdown">
                                        <option value="">{{ translate('Select Area') }}</option>
                                        @foreach($deliveryAreas as $area)
                                            <option value="{{$area['id']}}"
                                                    {{ $selectedOrderAreaId == $area['id'] ? 'selected' : '' }}
                                                    data-charge="{{$area['delivery_charge']}}">{{ $area['area_name'] }}
                                                - ({{ Helpers::set_symbol($area['delivery_charge']) }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="input-label" for="">{{ translate('Delivery Charge') }}
                                    ({{ Helpers::currency_symbol() }})</label>
                                <input type="number" class="form-control" name="delivery_charge"
                                       id="deliveryChargeInput" value="" readonly>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end">
                            <button class="btn btn-primary">{{translate('update')}}</button>
                        </div>
                    </div>
                </form>

            </div>
        </div>
    </div>


    {{-- delete product modal --}}
    <div class="modal fade deleteProductModal" tabindex="-1" role="dialog" aria-labelledby="deleteProductModal"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow">
                <div class="modal-header justify-content-end p-2 pb-0">
                    <button type="button" class="btn btn-soft-secondary square-btn rounded-circle" data-dismiss="modal"
                            aria-label="Close">
                        <i class="tio-clear"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="text-center">
                        <img width="70" class="avatar-img ratio-1 mx-auto mb-4"
                             src="{{asset('public/assets/admin/img/modal/delete-warning.png')}}" alt="">
                        <h4 class="mb-3">{{ translate('Are you sure to delete this product?') }}</h4>
                        <p class="mb-30">
                            {{ translate('If once you delete this product, this will remove from product list.') }}
                        </p>
                        <div class="d-flex gap-3 justify-content-center align-items-center flex-wrap">
                            <button class="btn btn-secondary min-w-120px"
                                    data-dismiss="modal">{{translate('cancel')}}</button>
                            <button class="btn btn-danger min-w-120px delete_product">{{translate('delete')}}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- confirmation edit product modal --}}
    <div class="modal fade" id="confirmEditProductModal" tabindex="-1" role="dialog"
         aria-labelledby="confirmEditProductModal" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow">
                <div class="modal-header justify-content-end p-2 pb-0">
                    <button type="button" class="btn btn-soft-secondary square-btn rounded-circle" data-dismiss="modal"
                            aria-label="Close">
                        <i class="tio-clear"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="text-center">
                        <img width="70" class="avatar-img ratio-1 mx-auto mb-4"
                             src="{{asset('public/assets/admin/img/modal/delete-warning.png')}}" alt="">
                        <h4 class="mb-3">{{ translate('Are you sure you want to edit this order') }}?</h4>
                        <p class="mb-30">
                            {{ translate('If you edit this order, some product details will be updated, which may affect the total price') }}
                        </p>
                        <div class="d-flex gap-3 justify-content-center align-items-center flex-wrap">
                            <button class="btn btn-secondary min-w-120px"
                                    data-dismiss="modal">{{translate('No')}}</button>
                            <button class="btn btn-danger min-w-120px edit-order-offcanvas"
                                    data-dismiss="modal"
                                    data-toggle="offcanvas"
                                    data-target="#editOrderOffcanvas"
                                    data-order-id="{{$order->id}}">{{translate('Yes')}}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Edit Products Offcanvas --}}
    <div class="offcanvas z--999" id="editOrderOffcanvas" style="--width: 850px">
        <div class="offcanvas-header d-flex justify-content-between align-items-start border-bottom px-2 py-2">
            <div class="pl-3 py-2">
                <h4 class="offcanvas-title mb-2">{{ translate('Edit_Products') }}</h4>
                <div class="d-flex gap-2 gap-sm-4 flex-wrap align-items-center">
                    <h5 class="mb-0 d-flex gap-1 align-items-center">
                        <span class="mr-3">{{ translate('Order') }} #{{ \App\CentralLogics\Helpers::order_display_id($order) }}</span>
                        <span class="badge badge-soft--info px-2 py-1 text-capitalize mr-3">{{ str_replace('_',' ',$order['order_status']) }}</span>
                    </h5>
                    <h5 class="mb-0 d-flex gap-1 align-items-center px-4">
                        <span class="font-weight-normal">{{ translate('Order Placed') }}</span> :
                        <span>{{date('d M Y',strtotime($order['created_at']))}} {{ date(config('time_format'), strtotime($order['created_at'])) }}</span>
                    </h5>
                </div>
            </div>
            <div>
                <button type="button" class="btn btn-soft-secondary square-btn rounded-circle" data-dismiss="offcanvas">
                    <i class="tio-clear"></i>
                </button>
            </div>
        </div>
        <div class="offcanvas-body px-4 pb-0 pt-4">

            <div class="product-search-wrapper position-relative mb-4">
                <div class="position-relative d-flex">
                    <div
                        class="position-absolute top-0 left-0 h-100 px-2 d-flex justify-content-center align-items-center">
                        <i class="tio-search"></i>
                    </div>
                    <input type="search" class="edit-order-product-search-input form-control pl-5"
                           placeholder="{{translate('Search by product name')}}">
                </div>

                <div class="product-search-dropdown bg-white mt-2">
                    {{--dynamic search result--}}
                </div>
            </div>

            @include('admin-views.order.partials.order-products-table', ['orderId' => $order->id])
        </div>
        <div class="offcanvas-footer bg-white py-2 d-flex justify-content-end align-items-end flex-wrap gap-3">
            <button type="button" class="btn btn-secondary px-4 min-w-120px"
                    data-dismiss="offcanvas">{{ translate('Cancel') }}</button>
            <button type="submit" class="btn btn-primary px-4 min-w-120px update-edit-order"
                    data-order-id="{{ $order->id }}">
                {{ translate('Update Cart') }}
            </button>
        </div>
    </div>

@endsection

@push('script_2')

    <script src="{{ asset('public/assets/admin/js/meatco-order-operations.js') }}?v=1.9"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const whatsappBtn = document.getElementById('send-whatsapp-order-btn');
            if (!whatsappBtn) {
                return;
            }

            whatsappBtn.addEventListener('click', function () {
                const label = whatsappBtn.querySelector('span');
                const originalText = label ? label.textContent : '';
                whatsappBtn.classList.add('disabled');
                whatsappBtn.setAttribute('aria-busy', 'true');

                if (typeof toastr !== 'undefined') {
                    toastr.info(@json(translate('Opening WhatsApp...')));
                }

                setTimeout(function () {
                    whatsappBtn.classList.remove('disabled');
                    whatsappBtn.removeAttribute('aria-busy');
                    if (label && originalText) {
                        label.textContent = originalText;
                    }
                }, 2500);
            });
        });

        document.addEventListener("DOMContentLoaded", () => {
            const dd = document.querySelector(".product-search-dropdown .card-body");
            const items = [...dd.querySelectorAll(".product-search-dropdown-item:not(.disabled)")];
            let idx = -1;

            document.addEventListener("keydown", e => {
                if (document.querySelector(".product-search-dropdown.d-none")) return;
                if (!items.length) return;

                if (e.key === "ArrowDown") {
                    e.preventDefault();
                    idx = (idx + 1) % items.length;
                    setActive();
                }
                if (e.key === "ArrowUp") {
                    e.preventDefault();
                    idx = (idx - 1 + items.length) % items.length;
                    setActive();
                }
                if (e.key === "Enter" && idx >= 0) {
                    e.preventDefault();
                    items[idx].click();
                }
            });

            function setActive() {
                items.forEach(i => i.classList.remove("active", "active-item", "border-primary"));
                const el = items[idx];
                el.classList.add("active", "active-item", "border-primary");

                // always keep the active item centered in 300px dropdown
                dd.scrollTop = el.offsetTop - (dd.clientHeight / 2 - el.clientHeight / 2);
            }
        });
    </script>


    <script>
        "use strict";

        $('.assign-deliveryman').click(function () {
            var deliveryManId = $(this).attr('id');
            addDeliveryMan(deliveryManId);
        });

        $('.change-payment-status').on('click', function () {
            let id = $(this).data('id');
            let status = $(this).data('status');
            let paymentStatusRoute = "{{ route('admin.orders.payment-status') }}";
            location.href = paymentStatusRoute + '?id=' + encodeURIComponent(id) + '&payment_status=' + encodeURIComponent(status);
        });

        $('.last-location-view').click(function () {
            last_location_view();
        })

        $('.delivery-date, .delivery-time').on('change', function () {
            changeDeliveryTimeDate(this);
        });

        $('.predefined-time-input .badge').click(function () {
            var time = $(this).data('time');
            predefined_time_input(time);
        });

        $('.verify-offline-payment').click(function () {
            var status = $(this).data('status');
            verify_offline_payment(status);
        });

        $('.offline-payment-status-alert').on('click', function () {
            Swal.fire({
                title: '{{translate("Payment_is_Not_Verified")}}',
                text: '{{ translate("You can not change status of unverified offline payment") }}',
                type: 'question',
                showCancelButton: true,
                showConfirmButton: false,
                cancelButtonColor: 'default',
                confirmButtonColor: '#01684b',
                cancelButtonText: '{{translate("Close")}}',
                confirmButtonText: '',
                reverseButtons: true
            }).then((result) => {
                $('#payment_status_switch').prop('checked', false);
            })
        })

        $('.offline-payment-order-alert').on('click', function () {
            Swal.fire({
                title: '{{translate("Payment_is_Not_Verified")}}',
                text: '{{ translate("You can not change order status to this status. Please Check & Verify the payment information whether it is correct or not. You can only change order status to failed or cancel if payment is not verified.") }}',
                type: 'question',
                showCancelButton: true,
                showConfirmButton: false,
                cancelButtonColor: 'default',
                confirmButtonColor: '#01684b',
                cancelButtonText: '{{translate("Close")}}',
                confirmButtonText: '{{translate("Proceed")}}',
                reverseButtons: true
            }).then((result) => {

            })
        })

        function addDeliveryMan(id) {
            $.ajax({
                type: "GET",
                url: '{{url('/')}}/admin/orders/add-delivery-man/{{$order['id']}}/' + id,
                data: $('#product_form').serialize(),
                success: function (data) {
                    if (data.status == true) {
                        toastr.success('{{translate("Delivery man successfully assigned/changed")}}', {
                            CloseButton: true,
                            ProgressBar: true
                        });
                        setTimeout(function () {
                            location.reload();
                        }, 2000)
                    } else {
                        toastr.error('{{translate("Deliveryman man can not assign/change in that status")}}', {
                            CloseButton: true,
                            ProgressBar: true
                        });
                    }
                },
                error: function () {
                    toastr.error('{{translate("Add valid data")}}', {
                        CloseButton: true,
                        ProgressBar: true
                    });
                }
            });
        }

        function last_location_view() {
            toastr.warning('{{translate("Only available when order is out for delivery!")}}', {
                CloseButton: true,
                ProgressBar: true
            });
        }

        function predefined_time_input(min) {
            document.getElementById("extra_minute").value = min;
        }

        function changeDeliveryTimeDate(t) {
            let name = t.name
            let value = t.value
            $.ajax({
                type: "GET",
                url: '{{url('/')}}/admin/orders/ajax-change-delivery-time-date/{{$order['id']}}?' + t.name + '=' + t.value,
                data: {
                    name: name,
                    value: value
                },
                success: function (data) {
                    if (data.status == true && name == 'delivery_date') {
                        toastr.success('{{translate("Delivery date changed successfully")}}', {
                            CloseButton: true,
                            ProgressBar: true
                        });
                    } else if (data.status == true && name == 'delivery_time') {
                        toastr.success('{{translate("Delivery time changed successfully")}}', {
                            CloseButton: true,
                            ProgressBar: true
                        });
                    } else {
                        toastr.error('{{translate("Order No is not valid")}}', {
                            CloseButton: true,
                            ProgressBar: true
                        });
                    }
                    location.reload();
                },
                error: function () {
                    toastr.error('{{translate("Add valid data")}}', {
                        CloseButton: true,
                        ProgressBar: true
                    });
                },
            });
        }

        function verify_offline_payment(status) {
            $.ajax({
                type: "GET",
                url: '{{url('/')}}/admin/orders/verify-offline-payment/{{$order['id']}}/' + status,
                success: function (data) {
                    if (data.status == true) {
                        toastr.success('{{ translate("offline payment verify status changed") }}', {
                            CloseButton: true,
                            ProgressBar: true
                        });
                    } else {
                        if (data.type == 'canceled') {
                            toastr.error(data.message, {
                                CloseButton: true,
                                ProgressBar: true
                            });
                        } else {
                            toastr.error('{{ translate("offline payment verify status not changed") }}', {
                                CloseButton: true,
                                ProgressBar: true
                            });
                        }
                    }
                    setTimeout(function () {
                        location.reload();
                    }, 2000);
                },
                error: function () {
                }
            });
        }


    </script>
    @if($showFoodPreparationControls)
        <script>
            "use strict";

            const expire_time = "{{ $order['remaining_time'] }}";
            var countDownDate = new Date(expire_time).getTime();
            const time_zone = "{{ Helpers::get_business_settings('time_zone') ?? 'UTC' }}";

            var x = setInterval(function () {
                var now = new Date(new Date().toLocaleString("en-US", {timeZone: time_zone})).getTime();

                var distance = countDownDate - now;

                var days = Math.trunc(distance / (1000 * 60 * 60 * 24));
                var hours = Math.trunc((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                var minutes = Math.trunc((distance % (1000 * 60 * 60)) / (1000 * 60));
                var seconds = Math.trunc((distance % (1000 * 60)) / 1000);


                document.getElementById("timer-icon").classList.remove("d-none");
                document.getElementById("edit-icon").classList.remove("d-none");
                var $text = (distance < 0) ? "{{ translate('over') }}" : "{{ translate('left') }}";
                document.getElementById("counter").innerHTML = Math.abs(days) + "d " + Math.abs(hours) + "h " + Math.abs(minutes) + "m " + Math.abs(seconds) + "s " + $text;
                if (distance < 0) {
                    var element = document.getElementById('counter');
                    element.classList.add('text-danger');
                }
            }, 1000);


            $(document).ready(function () {
                const $areaDropdown = $('#areaDropdown');
                const $deliveryChargeInput = $('#deliveryChargeInput');

                $areaDropdown.change(function () {
                    const selectedOption = $(this).find('option:selected');
                    const charge = selectedOption.data('charge');
                    $deliveryChargeInput.val(charge);
                });
            });
        </script>
    @endif

    <script>
        const orderEditApiRoutes = @json($orderEditApiRoutes);

        $(document).on('click', '#edit-order-button', function () {
            if (!orderEditApiRoutes.clear_session) {
                return;
            }

            $.ajax({
                url: orderEditApiRoutes.clear_session,
                type: 'GET',
                success: function (response) {
                  //
                },
                error: function () {
                    //
                }
            });
        });

        function loadEditOrderProducts(orderId) {
            if (!orderEditApiRoutes.edit_order) {
                return;
            }

            $.ajax({
                url: orderEditApiRoutes.edit_order,
                type: 'GET',
                success: function (response) {
                    if (response.success) {
                        $('#editOrderOffcanvas .order-products').html(response.view);
                        $('.edit-order-product-search-input').val('');
                        $('.product-search-dropdown').addClass('d-none');
                    } else {
                        toastr.error("{{ translate('Failed to load order products') }}");
                    }
                },
                error: function (xhr) {
                    console.error(xhr.responseText);
                    toastr.error("{{ translate('Failed to load order products') }}");
                }
            });
        }

        $(document).on('click', '.edit-order-offcanvas', function () {
            let orderId = $(this).data('order-id');
            loadEditOrderProducts(orderId);
        });

        $(document).on('input change', '.edit-order-product-search-input', function () {
            let search = $(this).val();
            let orderId = {{ $order->id }};

            if (search.length < 1) {
                $('.product-search-dropdown').addClass('d-none');
                return;
            }

            if (!orderEditApiRoutes.search_product) {
                $('.product-search-dropdown').addClass('d-none');
                return;
            }

            $.ajax({
                url: orderEditApiRoutes.search_product,
                type: 'GET',
                data: {
                    search: search,
                    order_id: orderId
                },
                beforeSend: function () {
                    $('.product-search-dropdown').removeClass('d-none').find('.card-body').html('<p class="text-center m-0 py-3">Searching...</p>');
                },
                success: function (response) {
                    if (response.success) {
                        $('.product-search-dropdown').removeClass('d-none').html(response.view);
                    } else {
                        $('.product-search-dropdown').addClass('d-none');
                    }
                },
                error: function () {
                    $('.product-search-dropdown').addClass('d-none');
                }
            });
        });

        $(document).on('click', '.add-to-cart-from-search', function () {
            let productId = $(this).data('product-id');
            let orderId = $(this).data('order-id');
            let branchId = $(this).data('branch-id');

            openProductModal(productId, branchId, orderId);

        });

        function openProductModal(productId, branchId, orderId) {
            if (!orderEditApiRoutes.product_variation) {
                toastr.error('{{ translate('Order edit is not available on this installation.') }}');
                return;
            }

            $.ajax({
                url: orderEditApiRoutes.product_variation,
                type: 'GET',
                data: {
                    product_id: productId,
                    branch_id: branchId,
                    order_id: orderId,
                },
                beforeSend: function () {
                    $('body').append('<div class="loading-backdrop"></div>');
                },
                success: function (response) {
                    if (response.success) {
                        $('#variationProductModal').remove();

                        $('body').append(response.html);
                        setTimeout(() => $('#variationProductModal').modal('show'), 100);

                        $('.product-search-dropdown').addClass('d-none');
                        $('.edit-order-product-search-input').val('');

                    } else {
                        toastr.error(response.message || 'Failed to load product.');
                    }
                },
                complete: function () {
                    $('.loading-backdrop').remove();
                },
                error: function () {
                    toastr.error('Failed to load product details.');
                }
            });
        }

        $(document).on('click', '.edit-product-quantity-btn', function () {
            let button = $(this);
            let action = button.data('type') === 'plus' ? 'increase' : 'decrease';
            let productId = button.data('product-id');
            let orderId = button.data('order-id');

            if (!orderEditApiRoutes.update_product_quantity) {
                return;
            }

            $.ajax({
                url: orderEditApiRoutes.update_product_quantity,
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    order_id: orderId,
                    product_id: productId,
                    action: action
                },
                success: function (response) {
                    if (response.success) {
                        // load the product table
                        loadEditOrderProducts(orderId);
                        toastr.success(response.message);
                    } else {
                        toastr.error(response.message || 'Failed to update quantity');
                    }
                },
                error: function () {
                    toastr.error('Something went wrong.');
                }
            });
        });

        $(document).on('change keypress', '.edit-product-cart-qty-field', function (e) {
            if (e.type === 'keypress' && e.which !== 13) return; // Only trigger on Enter key OR change event

            let input = $(this);
            let newQty = parseInt(input.val());
            let productId = input.data('product-id');
            let orderId = input.data('order-id');

            if (isNaN(newQty) || newQty < 1) {
                toastr.warning('Quantity must be 1 or more.');
                input.val(1);
                return;
            }

            if (!orderEditApiRoutes.update_product_quantity) {
                return;
            }

            $.ajax({
                url: orderEditApiRoutes.update_product_quantity,
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    order_id: orderId,
                    product_id: productId,
                    new_quantity: newQty
                },
                success: function (response) {
                    if (response.success) {
                        loadEditOrderProducts(orderId);
                        toastr.success(response.message);
                    } else {
                        toastr.error(response.message || 'Failed to update quantity');
                    }
                },
                error: function () {
                    toastr.error('Something went wrong.');
                }
            });
        });


        $(document).on('change', '#add-to-cart-form .variation-input', function () {
            var variationGroup = $(this).closest('.variation-group');
            var label = variationGroup.find('.variant-name-optional-or-required-label');
            var isRequired = variationGroup.find('input[name$="[required]"]').val() === 'on';
            var minRequired = parseInt(variationGroup.find('input[name$="[min]"]').val()) || 0;

            if ($(this).attr('type') === 'checkbox') {
                var checkedCount = variationGroup.find('input[type="checkbox"]:checked').length;

                if (checkedCount >= minRequired && minRequired !== 0) {
                    label.html('{{ translate("Complete") }}')
                        .removeClass('badge-soft-secondary badge-soft-danger')
                        .addClass('badge-soft-success');
                } else {
                    label.html(isRequired ? '{{ translate("Required") }}' : '{{ translate("Optional") }}')
                        .removeClass('badge-soft-success')
                        .addClass(isRequired ? 'badge-soft-danger' : 'badge-soft-secondary');
                }
            } else {
                if ($(this).is(':checked')) {
                    label.html('{{ translate("Complete") }}')
                        .removeClass('badge-soft-secondary badge-soft-danger')
                        .addClass('badge-soft-success');
                }
            }

            updateFinalPrice();

        });


        function updateFinalPrice() {
            var basePrice = parseFloat($("#base_price").val());
            var totalVariationPrice = 0;
            var totalAddonPrice = 0;


            $('#add-to-cart-form .variation-input:checked').each(function () {
                // get the price from the sibling <span> which holds optionPrice
                var optionPriceText = $(this).closest('.form--check').find('span').text().replace(/[^\d\.]/g, '');
                var optionPrice = parseFloat(optionPriceText) || 0;
                totalVariationPrice += optionPrice;
            });

            $('#add-to-cart-form .addon-item').each(function () {
                let addonCheckbox = $(this).find('.addon-chek');
                if (addonCheckbox.is(':checked')) {
                    let addonId = addonCheckbox.val();
                    let addonPrice = parseFloat($(this).find(`input[name="addon-price${addonId}"]`).val()) || 0;
                    let addonQty = parseInt($(this).find(`input[name="addon-quantity${addonId}"]`).val()) || 1;
                    totalAddonPrice += addonPrice * addonQty;
                }
            });

            let quantity = parseInt($('#add-to-cart-form input[name="quantity"]').val()) || 1;
            let finalPrice = ((basePrice + totalVariationPrice) * quantity) + totalAddonPrice;

            let currencySymbol = "{{ Helpers::currency_symbol() }}";
            let currencyPosition = "{{ Helpers::get_business_settings('currency_symbol_position') ?? 'left' }}";
            let formattedPrice = finalPrice.toFixed(2);

            if (currencyPosition === 'left') {
                formattedPrice = currencySymbol + formattedPrice;
            } else {
                formattedPrice = formattedPrice + currencySymbol;
            }

            $('#chosen_price').text(formattedPrice);
        }

        $(document).on('click', '#add-to-cart-form .variation-quantity-update-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var input = $(this).closest('.product-quantity-group').find('.variation-cart-qty-field');

            var currentVal = parseInt(input.val()) || 1;
            var max = parseInt(input.attr('max')) || 9999;
            var min = parseInt(input.attr('min')) || 1;

            if ($(this).data('type') === 'minus' && currentVal > min) {
                input.val(currentVal - 1);
            } else if ($(this).data('type') === 'plus' && currentVal < max) {
                input.val(currentVal + 1);
            }

            setTimeout(() => {
                updateFinalPrice();
            }, 50);

        });

        $(document).on('click', '.addon-quantity-input button', function () {
            let input = $(this).siblings('input[type="number"]');
            let type = $(this).find('i').hasClass('tio-add') ? 'plus' : 'minus';
            let currentVal = parseInt(input.val()) || 1;
            let min = parseInt(input.attr('min')) || 1;
            let max = parseInt(input.attr('max')) || 100;

            if (type === 'plus' && currentVal < max) {
                input.val(currentVal + 1);
            } else if (type === 'minus' && currentVal > min) {
                input.val(currentVal - 1);
            }

            setTimeout(() => updateFinalPrice(), 50);
        });

        function addon_quantity_input_toggle(event) {
            let checkbox = $(event.target);
            let quantityBox = checkbox.closest('.addon-item').find('.addon-quantity-input');
            let addonLabel = checkbox.closest('.addon-item').find('.addon_label');

            if (checkbox.is(':checked')) {
                quantityBox.removeClass('d-none').addClass('d-flex');
                addonLabel.css({
                    'color': 'var(--tc) !important',
                    'font-weight': '600 !important'
                });
            } else {
                quantityBox.removeClass('d-flex').addClass('d-none');
                addonLabel.css({
                    'color': '',
                    'font-weight': ''
                });
            }

            updateFinalPrice();

        }

        $(document).on('change', '.addon-chek', function(event) {
            addon_quantity_input_toggle(event);
        });


        $(document).on('click', '.product-variation-add-to-cart-button', function () {
            let formData = $('#add-to-cart-form').serializeArray();
            console.log(formData);
            let orderId = $('input[name="order_id"]').val(); // You can set this dynamically
            formData.push({ name: '_token', value: '{{ csrf_token() }}' });

            if (!orderEditApiRoutes.add_product_to_session) {
                toastr.error('{{ translate('Order edit is not available on this installation.') }}');
                return;
            }

            $.ajax({
                url: orderEditApiRoutes.add_product_to_session,
                method: 'POST',
                data: formData,
                success: function (response) {
                    if (response.success) {
                        loadEditOrderProducts(orderId);
                        $('#variationProductModal').modal('hide')

                        toastr.success(response.message);
                    } else {
                        toastr.error(response.message);
                    }
                },
                error: function () {
                    toastr.error('Something went wrong');
                }
            });
        });


        let deleteProductIndex = null;
        let deleteProductOrderId = null;

        $(document).on('click', '.remove_product', function () {
            deleteProductIndex = $(this).data('index');
            deleteProductOrderId = $(this).data('order-id');
            $('.deleteProductModal').modal('show');
        });

        $(document).on('click', '.delete_product', function () {
            if (deleteProductIndex === null) return;

            if (!orderEditApiRoutes.delete_product_from_session) {
                return;
            }

            $.ajax({
                url: orderEditApiRoutes.delete_product_from_session,
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    order_id: deleteProductOrderId,
                    index: deleteProductIndex
                },
                success: function (response) {
                    if (response.success) {
                        toastr.success(response.message);
                        $('.deleteProductModal').modal('hide');
                        loadEditOrderProducts(deleteProductOrderId);
                    } else {
                        toastr.error(response.message);
                        $('.deleteProductModal').modal('hide');
                    }
                },
                error: function () {
                    toastr.error('Something went wrong.');
                    $('.deleteProductModal').modal('hide');
                }
            });
        });

        $(document).on('click', '.update-edit-order', function () {
            let orderId = $(this).data('order-id');
            let button = $(this)
            button.prop('disabled', true).addClass('disabled');

            if (!orderEditApiRoutes.update_edit_order) {
                button.prop('disabled', false).removeClass('disabled');
                return;
            }

            $.ajax({
                url: orderEditApiRoutes.update_edit_order,
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    order_id: orderId,
                },
                success: function (response) {
                    if (response.success) {
                        toastr.success(response.message);

                        setTimeout(function () {
                            location.reload();
                        }, 1000);
                    } else {
                        toastr.error(response.message || 'Failed to update order.');
                        button.prop('disabled', false).removeClass('disabled');
                    }
                },
                error: function () {
                    toastr.error('Something went wrong.');
                    button.prop('disabled', false).removeClass('disabled');
                }
            });
        });


    </script>
@endpush
