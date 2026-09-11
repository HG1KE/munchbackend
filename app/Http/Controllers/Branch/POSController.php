<?php

namespace App\Http\Controllers\Branch;

use App\CentralLogics\CustomerOrderStatusSms;
use App\CentralLogics\PosDeliveryCustomerSms;
use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Model\AddOn;
use App\Model\Branch;
use App\Model\Category;
use App\Model\CustomerAddress;
use App\Model\Notification;
use App\Model\Product;
use App\Model\Order;
use App\Services\BranchPosCatalogService;
use App\Services\BranchPosTodayOrdersService;
use App\Services\PosOrderCancellationService;
use App\Services\OrderReadableIdService;
use App\Support\OrderPlacementTime;
use App\Support\PosOrderTypes;
use App\Model\OrderDetail;
use App\Model\ProductByBranch;
use App\Models\OrderChangeAmount;
use App\User;
use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use App\Models\DeliveryChargeByArea;
use Rap2hpoutre\FastExcel\FastExcel;
use function App\CentralLogics\translate;

class POSController extends Controller
{
    public function __construct(
        private Category        $category,
        private Order           $order,
        private User            $user,
        private Product         $product,
        private Branch          $branch,
        private ProductByBranch $product_by_Branch,
        private BranchPosCatalogService $posCatalog,
        private BranchPosTodayOrdersService $posTodayOrders,
        private PosOrderCancellationService $posCancellation,
    )
    {}

    /**
     * @param Request $request
     * @return Renderable
     */
    public function index(Request $request): Renderable
    {
        $branchId = (int) auth('branch')->id();

        return view('branch-views.pos.index', [
            'catalog' => $this->posCatalog->forBranch($branchId),
            'branchName' => (string) (auth('branch')->user()->name ?? ''),
        ]);
    }

    public function catalog(): JsonResponse
    {
        $branchId = (int) auth('branch')->id();

        return response()->json([
            'success' => 1,
            'data' => $this->posCatalog->forBranch($branchId),
        ]);
    }

    public function heartbeat(): JsonResponse
    {
        $branchId = (int) auth('branch')->id();

        return response()->json([
            'success' => 1,
            'authenticated' => true,
            'csrf' => csrf_token(),
            'catalog_version' => $this->posCatalog->versionForBranch($branchId),
        ]);
    }

    public function todayOrders(Request $request): JsonResponse
    {
        $page = (int) $request->input('page', 1);
        $payload = $this->posTodayOrders->forBranch(
            (int) auth('branch')->id(),
            (string) (auth('branch')->user()->name ?? ''),
            $request->input('search'),
            $request->input('filter'),
            $page
        );

        return response()->json([
            'success' => 1,
            'data' => $payload,
        ]);
    }

    public function cancelOrder(Request $request): JsonResponse
    {
        $order = $this->order
            ->where('id', (int) $request->input('order_id'))
            ->where('branch_id', auth('branch')->id())
            ->whereIn('sales_channel', PosOrderTypes::salesChannels())
            ->first();

        if (! $order) {
            return response()->json(['success' => 0, 'message' => 'Order not found'], 404);
        }

        $result = $this->posCancellation->cancel(
            $order,
            (string) $request->input('cancellation_reason', ''),
            'branch',
            (int) auth('branch')->id(),
            $this->posClientUuid($request),
            $request->input('offline') ? 'pos_offline' : 'pos'
        );

        if (! $result['success']) {
            $status = ($result['code'] ?? '') === 'reason' ? 422 : 422;

            return response()->json([
                'success' => 0,
                'message' => $result['message'],
                'code' => $result['code'] ?? 'cancel_failed',
            ], $status);
        }

        $fresh = $result['order'];
        if (! $result['duplicate']) {
            $this->posCancellation->dispatchSms($fresh);
            $fresh->refresh();
        }

        $fresh->loadMissing(['branch', 'cancelledByBranch', 'cancelledByAdmin']);
        $cashierName = (string) (auth('branch')->user()->name ?? '');

        return response()->json([
            'success' => 1,
            'duplicate' => (bool) $result['duplicate'],
            'message' => $result['message'],
            'order' => $this->posTodayOrders->serializeOrder($fresh, $cashierName),
        ]);
    }

    public function markTicketPrinted(Request $request): JsonResponse
    {
        $ticket = (string) $request->input('ticket');
        if (! in_array($ticket, ['kitchen', 'receipt'], true)) {
            return response()->json(['success' => 0, 'message' => 'Invalid ticket'], 422);
        }

        $order = $this->order
            ->where('id', (int) $request->input('order_id'))
            ->where('branch_id', auth('branch')->id())
            ->whereIn('sales_channel', PosOrderTypes::salesChannels())
            ->first();

        if (! $order) {
            return response()->json(['success' => 0, 'message' => 'Order not found'], 404);
        }

        if ($ticket === 'kitchen' && PosOrderCancellationService::isCancelledStatus($order->order_status)) {
            return response()->json(['success' => 0, 'message' => 'Cancelled orders cannot print kitchen tickets'], 422);
        }

        $column = $ticket === 'kitchen' ? 'kitchen_printed_at' : 'receipt_printed_at';
        if ($order->{$column} === null) {
            $order->{$column} = now();
            $order->save();
        }

        return response()->json(array_merge([
            'success' => 1,
        ], $this->posPrintFlags($order)));
    }

    public function serviceWorker()
    {
        $path = public_path('assets/admin/js/munch-pos-sw.js');

        return response()->file($path, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/branch/pos',
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function quickView(Request $request): JsonResponse
    {
        $product = $this->product->with('product_by_branch')->findOrFail($request->product_id);

        return response()->json([
            'success' => 1,
            'view' => view('branch-views.pos._quick-view-data', compact('product'))->render(),
        ]);
    }

    /**
     * @param Request $request
     * @return array
     */
    public function variantPrice(Request $request): array
    {
        $product = $this->product->find($request->id);
        $price = $product->price;
        $addonPrice = 0;

        if ($request['addon_id'] && $product && $product->allowsAddonOnPos()) {
            foreach ($request['addon_id'] as $id) {
                $addonPrice += $request['addon-price' . $id] * $request['addon-quantity' . $id];
            }
        }

        $branchProduct = $this->product_by_Branch->where(['product_id' => $request->id, 'branch_id' => auth('branch')->id()])->first();

        if (isset($branchProduct)) {
            $branchProductVariations = $branchProduct->variations;
            $discountData = [
                'discount_type' => $branchProduct['discount_type'],
                'discount' => $branchProduct['discount']
            ];

            if ($request->variations && count($branchProductVariations)) {
                $priceTotal = $branchProduct['price'] + Helpers::new_variation_price($branchProductVariations, $request->variations);
                $price = $priceTotal - Helpers::discount_calculate($discountData, $priceTotal);
            } else {
                $price = $branchProduct['price'] - Helpers::discount_calculate($discountData, $branchProduct['price']);
            }
        }
        return array('price' => Helpers::set_symbol(($price * $request->quantity) + $addonPrice));
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function getCustomers(Request $request): JsonResponse
    {
        $key = explode(' ', $request['q']);
        $data = $this->user
            ->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('f_name', 'like', "%{$value}%")
                        ->orWhere('l_name', 'like', "%{$value}%")
                        ->orWhere('phone', 'like', "%{$value}%");
                }
            })
            ->whereNotNull(['f_name', 'l_name', 'phone'])
            ->limit(8)
            ->get([DB::raw('id, CONCAT(f_name, " ", l_name, " (", phone ,")") as text')]);

        $data[] = (object)['id' => false, 'text' => translate('walk_in_customer')];

        return response()->json($data);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function updateTax(Request $request): RedirectResponse
    {
        if ($request->tax < 0) {
            Toastr::error(translate('Tax_can_not_be_less_than_0_percent'));
            return back();
        } elseif ($request->tax > 100) {
            Toastr::error(translate('Tax_can_not_be_more_than_100_percent'));
            return back();
        }

        $cart = $request->session()->get('cart', collect([]));
        $cart['tax'] = $request->tax;
        $request->session()->put('cart', $cart);

        return back();
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function updateDiscount(Request $request): RedirectResponse
    {
        if (session()->has('cart')) {
            if (count(session()->get('cart')) < 1) {
                Toastr::error(translate('cart_empty_warning'));
                return back();
            }
        } else {
            Toastr::error(translate('cart_empty_warning'));
            return back();
        }

        if ($request->type == 'percent' && $request->discount < 0) {
            Toastr::error(translate('Extra_discount_can_not_be_less_than_0_percent'));
            return back();
        } elseif ($request->type == 'percent' && $request->discount > 100) {
            Toastr::error(translate('Extra_discount_can_not_be_more_than_100_percent'));
            return back();
        }

        $cart = $request->session()->get('cart', collect([]));
        $cart['extra_discount_type'] = $request->type;
        $cart['extra_discount'] = $request->discount;

        $request->session()->put('cart', $cart);
        return back();
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function updateQuantity(Request $request): JsonResponse
    {
        $cart = $request->session()->get('cart', collect([]));
        $cart = $cart->map(function ($object, $key) use ($request) {
            if ($key == $request->key) {
                $object['quantity'] = $request->quantity;
            }
            return $object;
        });
        $request->session()->put('cart', $cart);

        return response()->json([], 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function addToCart(Request $request): JsonResponse
    {
        $product = $this->product->find($request->id);

        $data = array();
        $data['id'] = $product->id;
        $str = '';
        $variations = [];
        $price = 0;
        $addonPrice = 0;
        $variationPrice = 0;
        $addonTotalTax = 0;

        $branchProduct = $this->product_by_Branch->where(['product_id' => $request->id, 'branch_id' => auth('branch')->id()])->first();
        $branchProductPrice = 0;
        $discountData = [];

        if (isset($branchProduct)) {
            $branchProductVariations = $branchProduct->variations;

            if ($request->variations && count($branchProductVariations)) {
                foreach ($request->variations as $key => $value) {

                    if ($value['required'] == 'on' && !isset($value['values'])) {
                        return response()->json([
                            'data' => 'variation_error',
                            'message' => translate('Please select items from') . ' ' . $value['name'],
                        ]);
                    }
                    if (isset($value['values']) && $value['min'] != 0 && $value['min'] > count($value['values']['label'])) {
                        return response()->json([
                            'data' => 'variation_error',
                            'message' => translate('Please select minimum ') . $value['min'] . translate(' For ') . $value['name'] . '.',
                        ]);
                    }
                    if (isset($value['values']) && $value['max'] != 0 && $value['max'] < count($value['values']['label'])) {
                        return response()->json([
                            'data' => 'variation_error',
                            'message' => translate('Please select maximum ') . $value['max'] . translate(' For ') . $value['name'] . '.',
                        ]);
                    }
                }
                $variationData = Helpers::get_varient($branchProductVariations, $request->variations);
                $variationPrice = $variationData['price'];
                $variations = $request->variations;

            }

            $branchProductPrice = $branchProduct['price'];
            $discountData = [
                'discount_type' => $branchProduct['discount_type'],
                'discount' => $branchProduct['discount']
            ];

        }
        $price = $branchProductPrice + $variationPrice;
        $data['variation_price'] = $variationPrice;

        $discountOnProduct = Helpers::discount_calculate($discountData, $price);

        $data['variations'] = $variations;
        $data['variant'] = $str;

        $data['quantity'] = $request['quantity'];
        $data['price'] = $price;
        $data['name'] = $product->name;
        $data['discount'] = $discountOnProduct;
        $data['image'] = $product->image;
        $data['add_ons'] = [];
        $data['add_on_qtys'] = [];
        $data['add_on_prices'] = [];
        $data['add_on_tax'] = [];

        if ($request['addon_id'] && $product->allowsAddonOnPos()) {
            $allowedAddonIds = $product->addonIds();
            foreach ($request['addon_id'] as $id) {
                $id = (int) $id;
                if ($id < 1 || ! in_array($id, $allowedAddonIds, true)) {
                    continue;
                }
                $addonPrice += $request['addon-price' . $id] * $request['addon-quantity' . $id];
                $data['add_on_qtys'][] = $request['addon-quantity' . $id];

                $add_on = AddOn::find($id);
                if (! $add_on) {
                    continue;
                }
                $data['add_on_prices'][] = $add_on['price'];
                $addonTax = ($add_on['price'] * $add_on['tax']/100);
                $addonTotalTax += (($add_on['price'] * $add_on['tax']/100) * $request['addon-quantity' . $id]);
                $data['add_on_tax'][] = $addonTax;
                $data['add_ons'][] = $id;
            }
        }

        $data['addon_price'] = $addonPrice;
        $data['addon_total_tax'] = $addonTotalTax;
        $data['discount_data'] = $discountData;

        if ($request->session()->has('cart')) {
            $cart = $request->session()->get('cart', collect([]));
            $cart->push($data);
        } else {
            $cart = collect([$data]);
            $request->session()->put('cart', $cart);
        }

        return response()->json([
            'data' => $data
        ]);
    }

    /**
     * @param Request $request
     * @return RedirectResponse|JsonResponse
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function placeOrder(Request $request): RedirectResponse|JsonResponse
    {
        if ($this->isJsonPosOrder($request)) {
            $prepared = $this->prepareJsonPosOrder($request);
            if ($prepared instanceof JsonResponse) {
                return $prepared;
            }
        }

        if ($request->session()->has('cart')) {
            if (count($request->session()->get('cart')) < 1) {
                return $this->posFail($request, translate('cart_empty_warning'));
            }
        } else {
            return $this->posFail($request, translate('cart_empty_warning'));
        }

        $orderType = PosOrderTypes::normalize(
            session()->has('order_type') ? (string) session()->get('order_type') : PosOrderTypes::TAKE_AWAY
        );

        $platformError = PosOrderTypes::marketplacePlatformOrderError(
            $orderType,
            $request->input('platform_order_number')
        );
        if ($platformError !== null) {
            return $this->posFail($request, translate($platformError));
        }

        $deliveryCharge = 0;
        $distance = 0;
        $areaId = null;
        $customerAddress = null;

        if (PosOrderTypes::isDelivery($orderType)) {
            if (!session()->has('address')){
                return $this->posFail($request, translate('please select a delivery address'));
            }

            $addressData = session()->get('address');
            $distance = $addressData['distance'] ?? 0;
            $areaId = $addressData['area_id'] ?? $addressData['selected_area_id'] ?? null;

            $deliveryError = PosOrderTypes::posDeliveryFieldError($orderType, [
                'customer_name' => $addressData['contact_person_name'] ?? '',
                'customer_phone' => $addressData['contact_person_number'] ?? '',
                'address' => $addressData['address'] ?? '',
            ]);
            if ($deliveryError !== null) {
                return $this->posFail($request, translate($deliveryError));
            }

            $address = [
                'address_type' => 'Home',
                'contact_person_name' => $addressData['contact_person_name'] ?? 'POS Delivery',
                'contact_person_number' => $addressData['contact_person_number'] ?? '',
                'address' => $addressData['address'] ?? '',
                'floor' => $addressData['floor'] ?? null,
                'road' => $addressData['road'] ?? null,
                'house' => $addressData['house'] ?? null,
                'longitude' => (string) ($addressData['longitude'] ?? ''),
                'latitude' => (string) ($addressData['latitude'] ?? ''),
                'user_id' => session()->get('customer_id') ?: null,
                'is_guest' => session()->get('customer_id') ? 0 : 1,
            ];
            $customerAddress = CustomerAddress::create($address);
        }

        $cart = $request->session()->get('cart');
        $totalTaxAmount = 0;
        $totalAddonPrice = 0;
        $totalAddonTax = 0;
        $productPrice = 0;
        $orderDetails = [];

        $orderId = 100000 + $this->order->all()->count() + 1;
        if ($this->order->find($orderId)) {
            $orderId = $this->order->orderBy('id', 'DESC')->first()->id + 1;
        }

        $order = $this->order;
        $order->id = $orderId;

        $placedAt = $this->resolvePosPlacedAt($request);

        $order->user_id = session()->get('customer_id') ?? null;
        $order->coupon_discount_title = $request->coupon_discount_title == 0 ? null : $request->coupon_discount_title;
        $paymentMethod = PosOrderTypes::resolvedPaymentMethod($orderType, $request->type);

        $order->payment_status = PosOrderTypes::isPaidImmediately($orderType, $paymentMethod) ? 'paid' : 'unpaid';
        $order->order_status = PosOrderTypes::defaultStatus($orderType);
        $order->order_type = PosOrderTypes::databaseType($orderType);
        $order->coupon_code = $request->coupon_code ?? null;
        $order->payment_method = $paymentMethod;
        $order->transaction_reference = $request->input('transaction_reference');
        $order->client_uuid = $this->posClientUuid($request);
        $order->sales_channel = PosOrderTypes::salesChannel($orderType);
        if (Schema::hasColumn('orders', 'platform_order_number')) {
            $order->platform_order_number = PosOrderTypes::isMarketplace($orderType)
                ? PosOrderTypes::normalizePlatformOrderNumber($request->input('platform_order_number'))
                : null;
        }
        $order->delivery_address_id = PosOrderTypes::isDelivery($orderType) && $customerAddress ? $customerAddress->id : null;
        if (Schema::hasColumn('orders', 'rider_name')) {
            $order->rider_name = $this->posRiderName($request, $orderType);
        }
        if (Schema::hasColumn('orders', 'rider_phone')) {
            $order->rider_phone = $this->posRiderPhone($request, $orderType);
        }
        $order->delivery_date = $placedAt->format('Y-m-d');
        $order->delivery_time = $placedAt->format('H:i:s');
        $order->order_note = $request->filled('order_note') ? $request->input('order_note') : null;
        $order->checked = 1;
        $order->created_at = $placedAt;
        $order->updated_at = $placedAt;

        foreach ($cart as $c) {
            if (is_array($c)) {
                $discountOnProduct = 0;
                $discount = 0;
                $productSubtotal = ($c['price']) * $c['quantity'];
                $discountOnProduct += ($c['discount'] * $c['quantity']);

                $product = $this->product->find($c['id']);
                if ($product) {
                    $price = $c['price'];

                    $product = Helpers::product_data_formatting($product);
                    $addonData = Helpers::calculate_addon_price(AddOn::whereIn('id', $c['add_ons'])->get(), $c['add_on_qtys']);

                    //*** addon quantity integer casting ***
                    array_walk($c['add_on_qtys'], function (&$add_on_qtys) {
                        $add_on_qtys = (int)$add_on_qtys;
                    });
                    //***end***

                    $branchProduct = $this->product_by_Branch->where(['product_id' => $c['id'], 'branch_id' => auth('branch')->id()])->first();

                    $discountData = [];
                    if (isset($branchProduct)) {
                        $variationData = Helpers::get_varient($branchProduct->variations, $c['variations']);
                        $discountData = [
                            'discount_type' => $branchProduct['discount_type'],
                            'discount' => $branchProduct['discount']
                        ];
                    }

                    $discount = Helpers::discount_calculate($discountData, $price);
                    $variations = $variationData['variations'];

                    $orderData = [
                        'product_id' => $c['id'],
                        'product_details' => $product,
                        'quantity' => $c['quantity'],
                        'price' => $price,
                        'tax_amount' => Helpers::new_tax_calculate($product, $price, $discountData),
                        'discount_on_product' => $discount,
                        'discount_type' => 'discount_on_product',
                        'variation' => json_encode($variations),
                        'add_on_ids' => json_encode($addonData['addons']),
                        'add_on_qtys' => json_encode($c['add_on_qtys']),
                        'add_on_prices' => json_encode($c['add_on_prices']),
                        'add_on_taxes' => json_encode($c['add_on_tax']),
                        'add_on_tax_amount' => $c['addon_total_tax'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    $totalTaxAmount += $orderData['tax_amount'] * $c['quantity'];
                    $totalAddonPrice += $addonData['total_add_on_price'];

                    $totalAddonTax += $c['addon_total_tax'];

                    $productPrice += $productSubtotal - $discountOnProduct;
                    $orderDetails[] = $orderData;
                }
            }
        }

        $totalPrice = $productPrice + $totalAddonPrice;
        $totalPriceForDiscountValidation = $totalPrice ?? 0;
        if (isset($cart['extra_discount'])) {
            $extraDiscount = $cart['extra_discount_type'] == 'percent' && $cart['extra_discount'] > 0 ? (($totalPrice * $cart['extra_discount']) / 100) : $cart['extra_discount'];
            $totalPrice -= $extraDiscount;
        }
        if (isset($cart['extra_discount']) && $cart['extra_discount_type'] == 'amount') {
            if ($cart['extra_discount'] > $totalPriceForDiscountValidation) {
                return $this->posFail($request, translate('discount_can_not_be_more_than '). $totalPriceForDiscountValidation);
            }
        }
        $tax = isset($cart['tax']) ? $cart['tax'] : 0;
        $totalTaxAmount = ($tax > 0) ? (($totalPrice * $tax) / 100) : $totalTaxAmount;

        $deliveryCharge = $this->resolvePosDeliveryCharge($request, $orderType, $distance, $areaId, $totalPrice + $totalTaxAmount + $totalAddonTax);

        try {
            $order->extra_discount = $extraDiscount ?? 0;
            $order->total_tax_amount = $totalTaxAmount;
            $order->delivery_charge = $deliveryCharge;
            $order->order_amount = $totalPrice + $totalTaxAmount + $order->delivery_charge + $totalAddonTax;
            $order->coupon_discount_amount = 0.00;
            $order->branch_id = auth('branch')->id();
            $order->table_id = null;
            $order->number_of_people = null;

            OrderPlacementTime::applyToOrder($order, $placedAt);

            DB::transaction(function () use ($order, &$orderDetails, $request, $paymentMethod, $orderType, $customerAddress) {
                $order->save();
                $this->persistPosDeliveryAddressJson($order, $orderType, $customerAddress);

                foreach ($orderDetails as $key => $item) {
                    $orderDetails[$key]['order_id'] = $order->id;
                }
                OrderDetail::insert($orderDetails);

                if (PosOrderTypes::isImmediatePosPayment($paymentMethod)) {
                    $orderChangeAmount = new OrderChangeAmount();
                    $orderChangeAmount->order_id = $order->id;
                    $orderChangeAmount->order_amount = $order->order_amount;
                    $orderChangeAmount->paid_amount = $order->order_amount;
                    $orderChangeAmount->save();
                }
            });

            if (! PosOrderTypes::isPosFamily($order->order_type, $order->sales_channel)
                && in_array($order->order_status, ['pending', 'confirmed'], true)) {
                CustomerOrderStatusSms::dispatchPlacement($order->fresh(['customer', 'branch']));
            }

            $this->dispatchPosDeliveryCustomerSms($order);

            session()->forget('cart');
            session(['last_order' => $order->id]);

            session()->forget('customer_id');
            session()->forget('branch_id');
            session()->forget('table_id');
            session()->forget('people_number');
            session()->forget('address');
            session()->forget('order_type');

            if (! $this->isJsonPosOrder($request)) {
                Toastr::success(translate('order_placed_successfully'));
            }

            //send notification to kitchen
            if ($order->order_type == 'dine_in') {
                $notification = new Notification;
                $notification->title = "You have a new order from POS - (Order Confirmed). ";
                $notification->description = $order->id;
                $notification->status = 1;
                $notification->order_id =  $order->id;
                $notification->order_status = $order->order_status;

                try {
                    Helpers::send_push_notif_to_topic(data: $notification, topic: "kitchen-{$order->branch_id}", type: 'general', isNotificationPayloadRemove: true);
                    Toastr::success(translate('Notification sent successfully!'));
                } catch (\Exception $e) {
                    Toastr::warning(translate('Push notification failed!'));
                }
            }

            //send notification to customer for home delivery
            if ($order->order_type == 'delivery' && ! PosOrderTypes::isPosFamily($order->order_type, $order->sales_channel) && $order->user_id){
                $message = Helpers::order_status_update_message('confirmed');
                $customer = $this->user->find($order->user_id);
                $customerFcmToken = $customer?->cm_firebase_token;
                $local = $customer?->language_code ?? 'en';
                $customerName = $customer?->f_name . ' '. $customer?->l_name ?? '';

                if ($local != 'en'){
                    $statusKey = Helpers::order_status_message_key('confirmed');
                    $translatedMessage = $this->business_setting->with('translations')->where(['key' => $statusKey])->first();
                    if (isset($translatedMessage->translations)){
                        foreach ($translatedMessage->translations as $translation){
                            if ($local == $translation->locale){
                                $message = $translation->value;
                            }
                        }
                    }
                }

                $restaurantName = Helpers::get_business_settings('restaurant_name');
                $value = Helpers::text_variable_data_format(value:$message, user_name: $customerName, restaurant_name: $restaurantName,  order_id: Helpers::order_display_id($order));


                if ($value && isset($customerFcmToken)) {
                    $data = [
                        'title' => translate('Order'),
                        'description' => $value,
                        'order_id' => $orderId,
                        'image' => '',
                        'type' => 'order_status',
                    ];
                    Helpers::send_push_notif_to_device($customerFcmToken, $data);
                }

                try {
                    $emailServices = Helpers::get_business_settings('mail_config');
                    $orderMailStatus = Helpers::get_business_settings('place_order_mail_status_user');
                    if (isset($emailServices['status']) && $emailServices['status'] == 1 && $orderMailStatus == 1 && isset($customer)) {
                        Mail::to($customer->email)->send(new \App\Mail\OrderPlaced($orderId));
                    }
                }catch (\Exception $e) {
                    //
                }
            }

            if ($this->isJsonPosOrder($request)) {
                return response()->json($this->posPlacedOrderJson($order));
            }

            return back();
        } catch (\Exception $e) {
            info($e);
            $clientUuid = $this->posClientUuid($request);
            if ($this->isJsonPosOrder($request) && $clientUuid) {
                $existing = $this->order
                    ->where('branch_id', auth('branch')->id())
                    ->where('client_uuid', $clientUuid)
                    ->first();
                if ($existing) {
                    $this->dispatchPosDeliveryCustomerSms($existing);

                    return response()->json($this->posPlacedOrderJson($existing, ['duplicate' => true]));
                }
            }
        }

        return $this->posFail($request, translate('failed_to_place_order'), 500);
    }

    /**
     * @return Renderable
     */
    public function cartItems(): Renderable
    {
        return view('branch-views.pos._cart');
    }

    /**
     * @return JsonResponse
     */
    public function emptyCart(): JsonResponse
    {
        session()->forget('cart');
        Session::forget('table_id');
        Session::forget('customer_id');
        Session::forget('people_number');
        session()->forget('address');
        session()->forget('order_type');

        return response()->json([], 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function removeFromCart(Request $request): JsonResponse
    {
        if ($request->session()->has('cart')) {
            $cart = $request->session()->get('cart', collect([]));
            $cart->forget($request->key);
            $request->session()->put('cart', $cart);
        }

        return response()->json([], 200);
    }

    /**
     * @param Request $request
     * @return Renderable
     */
    public function orderList(Request $request): Renderable
    {
        $from = $request->from;
        $to = $request->to;
        $search = $request['search'];
        $salesChannel = $request->input('sales_channel');
        $salesChannels = PosOrderTypes::salesChannels();

        $this->order->where(['checked' => 0])->update(['checked' => 1]);

        $orders = $this->order->pos()->with(['customer', 'branch'])
            ->where('branch_id', auth('branch')->id())
            ->when($request->search, function ($q, $search) {
                $keywords = explode(' ', $search);
                $q->where(function ($subQuery) use ($keywords) {
                    foreach ($keywords as $keyword) {
                        OrderReadableIdService::applyTerm($subQuery, $keyword);
                    }
                });
            })
            ->when($request->from && $request->to, function ($q) use ($request) {
                $q->whereBetween('created_at', [$request->from, Carbon::parse($request->to)->endOfDay()]);
            })
            ->when($salesChannel && in_array($salesChannel, $salesChannels, true), function ($q) use ($salesChannel) {
                $q->where('sales_channel', $salesChannel);
            })
            ->latest()
            ->paginate(Helpers::getPagination())
            ->appends($request->query());

        return view('branch-views.pos.order.list', compact('orders', 'search', 'from', 'to', 'salesChannel', 'salesChannels'));
    }

    /**
     * @param $id
     * @return Renderable|RedirectResponse
     */
    public function orderDetails($id): Renderable|RedirectResponse
    {
        $order = $this->order->with('details')->where(['id' => $id, 'branch_id' => auth('branch')->id()])->first();
        if (isset($order)) {
            return view('branch-views.pos.order.order-view', compact('order'));
        } else {
            Toastr::info('No more orders!');
            return back();
        }
    }

    /**
     * @param $id
     * @return JsonResponse
     */
    public function generateInvoice($id): JsonResponse
    {
        $order = $this->order->where('id', $id)->first();

        return response()->json([
            'success' => 1,
            'view' => view('branch-views.pos.order.invoice', compact('order'))->render(),
        ]);
    }

    /**
     * @return RedirectResponse
     */
    public function clearSessionData(): RedirectResponse
    {
        session()->forget('customer_id');
        session()->forget('branch_id');
        session()->forget('table_id');
        session()->forget('people_number');
        session()->forget('address');
        session()->forget('order_type');
        Toastr::success(translate('clear data successfully'));

        return back();
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function customerStore(Request $request): RedirectResponse
    {
        $request->validate([
            'f_name' => 'required',
            'l_name' => 'required',
            'phone' => 'required',
            'email' => 'required|email',
        ]);

        $user_phone = $this->user->where('phone', $request->phone)->first();
        if (isset($user_phone)){
            Toastr::error(translate('The phone is already taken'));
            return back();
        }

        $user_email = $this->user->where('email', $request->email)->first();
        if (isset($user_email)){
            Toastr::error(translate('The email is already taken'));
            return back();
        }

        $this->user->create([
            'f_name' => $request->f_name,
            'l_name' => $request->l_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => bcrypt('password'),
        ]);

        Toastr::success(translate('customer added successfully'));
        return back();
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function store_keys(Request $request): JsonResponse
    {
        session()->put($request['key'], $request['value']);
        return response()->json($request['key'], 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function sessionDestroy(Request $request): JsonResponse
    {
        Session::forget('cart');
        Session::forget('table_id');
        Session::forget('customer_id');
        Session::forget('people_number');
        session()->forget('address');
        session()->forget('order_type');

        return response()->json();
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function addDeliveryInfo(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'contact_person_name' => 'required',
            'contact_person_number' => 'required',
            'address' => 'required',
//            'latitude' => 'required',
//            'longitude' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 200);
        }

        $branchId = auth('branch')->id();
        $branch = $this->branch->find($branchId);
        $originLat = $branch['latitude'];
        $originLng = $branch['longitude'];
        $destinationLat = $request['latitude'];
        $destinationLng = $request['longitude'];

        if ($request->has('latitude') && $request->has('longitude')){

            $data = $this->getDistance($originLat, $originLng, $destinationLat, $destinationLng);
            $distanceValue = $data[0]['distanceMeters'];

            $distance = $distanceValue/1000;
        }

        if ($request['selected_area_id']){
            $area = DeliveryChargeByArea::find($request['selected_area_id']);
        }

        $address = [
            'contact_person_name' => $request->contact_person_name,
            'contact_person_number' => $request->contact_person_number,
            'address_type' => 'Home',
            'address' => $request->address,
            'floor' => $request->floor,
            'road' => $request->road,
            'house' => $request->house,
            'distance' => $distance ?? 0,
            'longitude' => (string)$request->longitude,
            'latitude' => (string)$request->latitude,
            'area_id' => $request['selected_area_id'],
            'area_name' => $area->area_name ?? null
        ];

        $request->session()->put('address', $address);

        return response()->json([
            'data' => $address,
            'view' => view('admin-views.pos._address', compact('address'))->render(),
        ]);
    }

    private function getDistance($originLat, $originLng, $destinationLat, $destinationLng)
    {
        $apiKey = Helpers::get_business_settings('map_api_server_key');
        $url = 'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix';

        $origin = [
            "waypoint" => [
                "location" => [
                    "latLng" => [
                        "latitude" =>  $originLat,
                        "longitude" => $originLng
                    ]
                ]
            ]
        ];

        $destination = [
            "waypoint" => [
                "location" => [
                    "latLng" => [
                        "latitude" => $destinationLat,
                        "longitude" => $destinationLng
                    ]
                ]
            ]
        ];

        $data = [
            "origins" => $origin,
            "destinations" => $destination,
            "travelMode" => "DRIVE",
            "routingPreference" => "TRAFFIC_AWARE"
        ];

        // API Headers
        $headers = [
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $apiKey,
            'X-Goog-FieldMask' => '*'
        ];

        // Send POST request
        $response = Http::withHeaders($headers)->post($url, $data);
        return $response->json();
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function orderTypeStore(Request $request): JsonResponse
    {
        session()->put('order_type', $request['order_type']);
        return response()->json($request['order_type'], 200);
    }

    public function exportOrder(Request $request)
    {
        $branchId = auth('branch')->id();

        // Single query with conditional filters
        $orders = $this->order->pos()->with(['customer', 'branch'])
            ->where('branch_id', $branchId)
            ->when($request->search, function ($q, $search) {
                $keywords = explode(' ', $search);
                $q->where(function ($subQuery) use ($keywords) {
                    foreach ($keywords as $keyword) {
                        OrderReadableIdService::applyTerm($subQuery, $keyword);
                    }
                });
            })
            ->when($request->from && $request->to, function ($q) use ($request) {
                $q->whereBetween('created_at', [$request->from, Carbon::parse($request->to)->endOfDay()]);
            })
            ->when($request->filled('sales_channel') && in_array($request->input('sales_channel'), PosOrderTypes::salesChannels(), true), function ($q) use ($request) {
                $q->where('sales_channel', $request->input('sales_channel'));
            })
            ->latest()
            ->get();

        if ($orders->isEmpty()) {
            Toastr::warning(translate('No Data Found'));
            return back();
        }

        $data = $orders->map(function ($order, $key) {
            return [
                'SL' => $key + 1,
                'Order ID' => Helpers::order_display_id($order),
                'Platform Order No.' => trim((string) ($order->platform_order_number ?? '')),
                'Order Date' => date('d M Y h:i A', strtotime($order->created_at)),
                'Customer Info' => $order->user_id ? "{$order->customer?->f_name} {$order->customer?->l_name}" : 'Walk-in Customer',
                'Total Amount' => Helpers::set_symbol($order->order_amount),
                'Payment Status' => ucfirst($order->payment_status),
                'Order Status' => ucfirst(str_replace('_', ' ', $order->order_status)),
                'Order Type' => PosOrderTypes::channelLabel($order->sales_channel, $order->order_type),
            ];
        });

        return (new FastExcel($data))->download('pos-orders.xlsx');
    }

    /**
     * @return array{kitchen_printed: bool, receipt_printed: bool}
     */
    private function posPrintFlags(Order $order): array
    {
        return [
            'kitchen_printed' => $order->kitchen_printed_at !== null,
            'receipt_printed' => $order->receipt_printed_at !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function posPlacedOrderJson(Order $order, array $extra = []): array
    {
        $fresh = $order->fresh(['details', 'customer', 'customer_delivery_address', 'branch']) ?: $order;
        $cashierName = (string) (auth('branch')->user()->name ?? '');

        return array_merge([
            'success' => 1,
            'order_id' => $fresh->id,
            'order_display_id' => Helpers::order_display_id($fresh),
            'message' => translate('order_placed_successfully'),
            'order' => $this->posTodayOrders->serializeOrder($fresh, $cashierName),
        ], $extra, $this->posPrintFlags($fresh));
    }

    /**
     * `orders.delivery_address` is both a JSON column and a BelongsTo relation.
     * Eloquent `forceFill`/`save` can write the relation instead of the JSON,
     * so persist the POS delivery customer snapshot with the query builder.
     */
    private function persistPosDeliveryAddressJson(Order $order, string $orderType, ?CustomerAddress $customerAddress): void
    {
        if (! PosOrderTypes::isDelivery($orderType) || ! $customerAddress || ! $order->id) {
            return;
        }

        $payload = [
            'contact_person_name' => $customerAddress->contact_person_name,
            'contact_person_number' => $customerAddress->contact_person_number,
            'address' => $customerAddress->address,
            'phone' => $customerAddress->contact_person_number,
        ];

        DB::table('orders')->where('id', $order->id)->update([
            'delivery_address' => json_encode($payload),
        ]);
    }

    private function isJsonPosOrder(Request $request): bool
    {
        return $request->expectsJson() || $request->header('X-Munch-POS') === '1';
    }

    private function posFail(Request $request, string $message, int $status = 422): RedirectResponse|JsonResponse
    {
        if ($this->isJsonPosOrder($request)) {
            return response()->json([
                'success' => 0,
                'message' => $message,
            ], $status);
        }

        Toastr::error($message);

        return back();
    }

    private function resolvePosPlacedAt(Request $request): Carbon
    {
        $raw = $request->input('placed_at');
        if (! is_string($raw) || trim($raw) === '') {
            return now();
        }

        try {
            $placedAt = Carbon::parse($raw);
        } catch (\Throwable) {
            return now();
        }

        if ($placedAt->isFuture()) {
            return now();
        }

        return $placedAt;
    }

    /**
     * Hydrate the session cart from a JSON POS payload and short-circuit duplicates.
     */
    private function prepareJsonPosOrder(Request $request): ?JsonResponse
    {
        $clientUuid = $this->posClientUuid($request);
        if ($clientUuid !== null) {
            $existing = $this->order
                ->where('branch_id', auth('branch')->id())
                ->where('client_uuid', $clientUuid)
                ->first();
            if ($existing) {
                $this->dispatchPosDeliveryCustomerSms($existing);

                return response()->json($this->posPlacedOrderJson($existing, ['duplicate' => true]));
            }
        }

        $items = $request->input('items', []);
        if (! is_array($items) || $items === []) {
            return $this->posFail($request, translate('cart_empty_warning'));
        }

        $deliveryError = $this->jsonPosDeliveryValidationError($request);
        if ($deliveryError !== null) {
            return $this->posFail($request, $deliveryError);
        }

        $platformError = PosOrderTypes::marketplacePlatformOrderError(
            $request->input('order_type'),
            $request->input('platform_order_number')
        );
        if ($platformError !== null) {
            return $this->posFail($request, translate($platformError));
        }

        $cart = collect([]);
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $line = $this->composeLineItem($item, PosOrderTypes::normalize($request->input('order_type')));
            if (! ($line['ok'] ?? false)) {
                return $this->posFail($request, (string) ($line['message'] ?? translate('failed_to_place_order')));
            }
            $cart->push($line['data']);
        }

        if ($cart->isEmpty()) {
            return $this->posFail($request, translate('cart_empty_warning'));
        }

        $cart['extra_discount'] = PosOrderTypes::allowsManualDiscount($request->input('order_type'))
            ? (float) $request->input('extra_discount', 0)
            : 0;
        $cart['extra_discount_type'] = $request->input('extra_discount_type', 'amount') === 'percent' ? 'percent' : 'amount';

        $request->session()->put('cart', $cart);
        $request->session()->put('order_type', PosOrderTypes::normalize($request->input('order_type')));
        $request->session()->forget('customer_id');
        $request->session()->forget('table_id');
        $request->session()->forget('people_number');

        $address = $request->input('address');
        if (is_array($address) && PosOrderTypes::isDelivery($request->input('order_type'))) {
            $request->session()->put('address', $address);
        } else {
            $request->session()->forget('address');
        }

        return null;
    }

    private function posClientUuid(Request $request): ?string
    {
        $clientUuid = trim((string) $request->input('client_uuid', ''));

        return $clientUuid === '' ? null : $clientUuid;
    }

    private function resolvePosDeliveryCharge(Request $request, string $orderType, mixed $distance, mixed $areaId, float $orderAmount): float
    {
        if (! PosOrderTypes::showsDeliveryCharge($orderType)) {
            return 0;
        }

        if ($this->isJsonPosOrder($request)) {
            return max(0, (float) $request->input('delivery_charge', 0));
        }

        return (float) Helpers::get_delivery_charge(
            branchId: auth('branch')->id() ?? 1,
            distance: $distance,
            selectedDeliveryArea: $areaId,
            orderAmount: $orderAmount
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, data?: array<string, mixed>, message?: string}
     */
    private function composeLineItem(array $input, string $orderType = PosOrderTypes::TAKE_AWAY): array
    {
        $productId = (int) ($input['id'] ?? 0);
        $product = $this->product->find($productId);
        if (! $product) {
            return ['ok' => false, 'message' => translate('failed_to_place_order')];
        }

        $data = [];
        $data['id'] = $product->id;
        $str = '';
        $variations = [];
        $variationPrice = 0;

        $branchProduct = $this->product_by_Branch
            ->where(['product_id' => $productId, 'branch_id' => auth('branch')->id()])
            ->first();
        $resolved = app(\App\Services\ProductChannelPricingService::class)
            ->resolveForSale($product, (int) auth('branch')->id(), $orderType);
        if (! $resolved['available']) {
            return ['ok' => false, 'message' => translate('Product is not available for this channel')];
        }
        $branchProductPrice = $resolved['price'];
        $discountData = [];

        if (isset($branchProduct)) {
            $branchProductVariations = $branchProduct->variations;
            $requestVariations = $input['variations'] ?? [];

            if ($requestVariations && is_array($branchProductVariations) && count($branchProductVariations)) {
                foreach ($requestVariations as $value) {
                    if (! is_array($value)) {
                        continue;
                    }
                    if (($value['required'] ?? '') == 'on' && !isset($value['values'])) {
                        return [
                            'ok' => false,
                            'message' => translate('Please select items from') . ' ' . ($value['name'] ?? ''),
                        ];
                    }
                    if (isset($value['values']) && ($value['min'] ?? 0) != 0 && ($value['min'] ?? 0) > count($value['values']['label'] ?? [])) {
                        return [
                            'ok' => false,
                            'message' => translate('Please select minimum ') . $value['min'] . translate(' For ') . $value['name'] . '.',
                        ];
                    }
                    if (isset($value['values']) && ($value['max'] ?? 0) != 0 && ($value['max'] ?? 0) < count($value['values']['label'] ?? [])) {
                        return [
                            'ok' => false,
                            'message' => translate('Please select maximum ') . $value['max'] . translate(' For ') . $value['name'] . '.',
                        ];
                    }
                }
                $variationData = Helpers::get_varient($branchProductVariations, $requestVariations);
                $variationPrice = $variationData['price'];
                $variations = $requestVariations;
            }

            $discountData = [
                'discount_type' => $branchProduct['discount_type'],
                'discount' => $branchProduct['discount']
            ];
        }

        $price = $branchProductPrice + $variationPrice;
        $data['variation_price'] = $variationPrice;
        $discountOnProduct = Helpers::discount_calculate($discountData, $price);

        $data['variations'] = $variations;
        $data['variant'] = $str;
        $data['quantity'] = max(1, (int) ($input['quantity'] ?? 1));
        $data['price'] = $price;
        $data['name'] = $product->name;
        $data['discount'] = $discountOnProduct;
        $data['image'] = $product->image;
        $data['add_ons'] = [];
        $data['add_on_qtys'] = [];
        $data['add_on_prices'] = [];
        $data['add_on_tax'] = [];
        $data['addon_price'] = 0;
        $data['addon_total_tax'] = 0;
        $data['discount_data'] = $discountData;
        $this->attachPosAddons($data, $product, $input);

        return ['ok' => true, 'data' => $data];
    }

    /**
     * Apply selected POS addons using the existing order-detail addon fields.
     * Ignored unless the product explicitly allows addons on POS.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $input
     */
    private function attachPosAddons(array &$data, Product $product, array $input): void
    {
        if (! $product->allowsAddonOnPos()) {
            return;
        }

        $allowed = $product->addonIds();
        if ($allowed === []) {
            return;
        }

        $requested = $input['addon_id'] ?? [];
        if (! is_array($requested) || $requested === []) {
            return;
        }

        $qtyMap = is_array($input['addon_quantities'] ?? null) ? $input['addon_quantities'] : [];
        $addonPrice = 0.0;
        $addonTotalTax = 0.0;

        foreach ($requested as $rawId) {
            $id = (int) $rawId;
            if ($id < 1 || ! in_array($id, $allowed, true)) {
                continue;
            }
            $addon = AddOn::query()->find($id);
            if (! $addon) {
                continue;
            }
            $qty = (int) ($qtyMap[$id] ?? $qtyMap[(string) $id] ?? 1);
            if ($qty < 1) {
                $qty = 1;
            }
            $price = (float) $addon->price;
            $tax = ((float) ($addon->tax ?? 0) / 100) * $price;
            $data['add_ons'][] = $id;
            $data['add_on_qtys'][] = $qty;
            $data['add_on_prices'][] = $price;
            $data['add_on_tax'][] = $tax;
            $addonPrice += $price * $qty;
            $addonTotalTax += $tax * $qty;
        }

        $data['addon_price'] = $addonPrice;
        $data['addon_total_tax'] = $addonTotalTax;
    }

    private function jsonPosDeliveryValidationError(Request $request): ?string
    {
        $address = $request->input('address');
        $address = is_array($address) ? $address : [];
        $error = PosOrderTypes::posDeliveryFieldError($request->input('order_type'), [
            'customer_name' => $address['contact_person_name'] ?? '',
            'customer_phone' => $address['contact_person_number'] ?? '',
            'address' => $address['address'] ?? '',
        ]);

        return $error === null ? null : translate($error);
    }

    private function dispatchPosDeliveryCustomerSms(Order $order): void
    {
        $fresh = $order->fresh(['customer', 'branch', 'details', 'customer_delivery_address']);
        PosDeliveryCustomerSms::dispatch($fresh ?: $order);
    }

    private function posRiderName(Request $request, string $orderType): ?string
    {
        if (! PosOrderTypes::isDelivery($orderType)) {
            return null;
        }

        $fromRequest = trim((string) $request->input('rider_name', ''));
        if ($fromRequest !== '') {
            return $fromRequest;
        }

        $address = session()->get('address');
        $fromAddress = is_array($address) ? trim((string) ($address['rider_name'] ?? '')) : '';

        return $fromAddress !== '' ? $fromAddress : null;
    }

    private function posRiderPhone(Request $request, string $orderType): ?string
    {
        if (! PosOrderTypes::isDelivery($orderType)) {
            return null;
        }

        $fromRequest = trim((string) $request->input('rider_phone', ''));
        if ($fromRequest !== '') {
            return $fromRequest;
        }

        $address = session()->get('address');
        $fromAddress = is_array($address) ? trim((string) ($address['rider_phone'] ?? '')) : '';

        return $fromAddress !== '' ? $fromAddress : null;
    }
}
