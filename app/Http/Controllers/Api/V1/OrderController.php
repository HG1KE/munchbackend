<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\AbandonedCheckoutService;
use App\CentralLogics\CustomerLogic;
use App\CentralLogics\Helpers;
use App\CentralLogics\OrderLogic;
use App\Jobs\SendOnlineOrderPlacementNotificationsJob;
use App\Support\BranchOrderSlotTime;
use App\Support\OnlineCheckoutIdempotency;
use App\Http\Controllers\Controller;
use App\Model\AddOn;
use App\Model\Branch;
use App\Model\BranchTimeSchedule;
use App\Model\BusinessSetting;
use App\Model\CustomerAddress;
use App\Model\DMReview;
use App\Model\Order;
use App\Model\OrderDetail;
use App\Model\Product;
use App\Model\ProductByBranch;
use App\Support\OrderPlacementTime;
use App\Support\StorefrontVisibilitySchedule;
use App\Model\TimeSchedule;
use App\Models\OfflinePayment;
use App\Models\OrderPartialPayment;
use App\Models\OrderArea;
use App\Models\ReferralCustomer;
use App\User;
use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;
use function App\CentralLogics\translate;

class OrderController extends Controller
{
    public function __construct(
        private User            $user,
        private Order           $order,
        private OrderDetail     $order_detail,
        private ProductByBranch $product_by_branch,
        private Product         $product,
        private OfflinePayment  $offlinePayment,
        private BusinessSetting $business_setting,
        private OrderArea $orderArea,
        private ReferralCustomer $referralCustomer,
    ){}

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function trackOrder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'guest_id' => auth('api')->user() ? 'nullable' : 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $userId = (bool)auth('api')->user() ? auth('api')->user()->id : $request['guest_id'];
        $userType = (bool)auth('api')->user() ? 0 : 1;

        $order = \App\Support\OrderPublicNumber::resolveForCustomer((string) $request['order_id'], (int) $userId, (int) $userType);
        if (!isset($order)) {
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('Order not found!')]
                ]
            ], 404);
        }

        return response()->json(OrderLogic::track_order($order->id), 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function placeOrder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_amount' => 'required',
            'payment_method' => 'required',
            'order_type' => 'required',
            'branch_id' => 'required',
            'delivery_time' => 'required',
            'delivery_date' => 'required',
            'distance' => 'required',
            'guest_id' => auth('api')->user() ? 'nullable' : 'required',
            'is_partial' => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $checkoutUuid = OnlineCheckoutIdempotency::resolveFromRequest($request);
        if ($checkoutUuid === null) {
            OnlineCheckoutIdempotency::logMissingUuid((string) $request->payment_method);
        } else {
            $existingCheckoutOrder = OnlineCheckoutIdempotency::findOrder($checkoutUuid);
            if ($existingCheckoutOrder) {
                OnlineCheckoutIdempotency::logDuplicate($checkoutUuid, $existingCheckoutOrder, 'pre_insert_lookup');

                return OnlineCheckoutIdempotency::successResponse($existingCheckoutOrder);
            }
        }

        $paystackReference = trim((string) ($request->transaction_reference ?? ''));
        if ((string) $request->payment_method === 'paystack' && $paystackReference !== '') {
            $existingPaystackOrder = $this->order->newQuery()
                ->where('payment_method', 'paystack')
                ->where('transaction_reference', $paystackReference)
                ->orderByDesc('id')
                ->first();
            if ($existingPaystackOrder) {
                return OnlineCheckoutIdempotency::successResponse($existingPaystackOrder);
            }
        }

        if (count($request['cart']) < 1) {
            return response()->json(['errors' => [['code' => 'empty-cart', 'message' => translate('cart is empty')]]], 403);
        }

        if (! auth('api')->user() && ! (int) (Helpers::get_business_settings('guest_checkout') ?? 0)) {
            return response()->json(['errors' => [['code' => 'guest_checkout_disabled', 'message' => 'Login is required to place an order.']]], 403);
        }

        $orderType = (string) ($request->input('order_type') ?? '');
        if ($orderType !== 'take_away') {
            $addressId = (int) ($request->input('delivery_address_id') ?? 0);
            $del = $request->input('delivery_address');
            $hasInline = false;
            if (is_array($del)) {
                foreach ($del as $value) {
                    if ($value !== null && $value !== '') {
                        $hasInline = true;
                        break;
                    }
                }
            }
            if ($addressId < 1 && ! $hasInline) {
                return response()->json(['errors' => [['code' => 'delivery_address_required', 'message' => 'A delivery address is required for delivery orders.']]], 403);
            }
        }

        //update daily stock
        Helpers::update_daily_product_stock();

        if(auth('api')->user()){
            $customer = $this->user->find(auth('api')->user()->id);
        }

        if ($request->payment_method == 'wallet_payment') {
            if (Helpers::get_business_settings('wallet_status') != 1){
                return response()->json(['errors' => [['code' => 'payment_method', 'message' => translate('customer_wallet_status_is_disable')]]], 403);
            }
            if (isset($customer) && $customer->wallet_balance < $request['order_amount']) {
                return response()->json(['errors' => [['code' => 'payment_method', 'message' => translate('you_do_not_have_sufficient_balance_in_wallet')]]], 403);
            }
        }

        if ($request['is_partial'] == 1) {
            if (Helpers::get_business_settings('wallet_status') != 1){
                return response()->json(['errors' => [['code' => 'payment_method', 'message' => translate('customer_wallet_status_is_disable')]]], 403);
            }
            if (isset($customer) && $customer->wallet_balance > $request['order_amount']){
                return response()->json(['errors' => [['code' => 'payment_method', 'message' => translate('since your wallet balance is more than order amount, you can not place partial order')]]], 403);
            }
            if (isset($customer) && $customer->wallet_balance < 1){
                return response()->json(['errors' => [['code' => 'payment_method', 'message' => translate('since your wallet balance is less than 1, you can not place partial order')]]], 403);
            }
        }

       // $preparation_time = Helpers::get_business_settings('default_preparation_time') ?? 0;
        $preparation_time = (int) (Branch::where(['id' => $request['branch_id']])->first()->preparation_time ?? 0);

        $customerSlot = BranchOrderSlotTime::customerSlot(
            (string) $request['delivery_time'],
            (string) $request['delivery_date']
        );
        $deliveryDate = $customerSlot['date'];
        $customerSlotTime = $customerSlot['time'];
        $deliveryTime = BranchOrderSlotTime::kitchenReadyTime($customerSlotTime, $preparation_time);

        // Validate branch availability against the customer's requested/current time.
        // Preparation minutes affect stored kitchen ready-time only, not slot eligibility.
        $branchSchedulesExist = BranchTimeSchedule::where('branch_id', $request['branch_id'])->exists();
        $restaurantSchedulesExist = TimeSchedule::exists();

        if ($branchSchedulesExist || $restaurantSchedulesExist) {
            // Prevent scheduling orders for future dates
            if ($deliveryDate !== Carbon::now()->format('Y-m-d')) {
                return response()->json(['errors' => [['code' => 'date', 'message' => translate('orders_can_only_be_placed_for_today')]]], 403);
            }

            $slotOk = Helpers::isBranchAvailable($request['branch_id'], $deliveryDate, $customerSlotTime);

            if (filter_var((string) env('BRANCH_AVAILABILITY_DEBUG', ''), FILTER_VALIDATE_BOOLEAN)) {
                Log::debug('place_order_branch_availability', [
                    'branch_id' => $request['branch_id'],
                    'order_type' => $request['order_type'],
                    'delivery_time_input' => $request['delivery_time'],
                    'delivery_date_resolved' => $deliveryDate,
                    'customer_slot_time' => $customerSlotTime,
                    'kitchen_ready_time' => $deliveryTime,
                    'branch_schedules_exist' => $branchSchedulesExist,
                    'restaurant_schedules_exist' => $restaurantSchedulesExist,
                    'is_branch_available_at_slot' => $slotOk,
                    'can_still_order_today' => Helpers::canStillOrderFromBranchToday($request['branch_id']),
                    'server_now' => Carbon::now()->toIso8601String(),
                ]);
            }

            if (! $slotOk) {
                return response()->json(['errors' => [['code' => 'time', 'message' => translate('order_cannot_be_placed_outside_branch_availability_hours')]]], 403);
            }

            if (! Helpers::canStillOrderFromBranchToday($request['branch_id'])) {
                return response()->json(['errors' => [['code' => 'time', 'message' => translate('branch_is_closed_for_today')]]], 403);
            }
        }
        // If no schedules exist at all, allow the order (backward compatibility)

        $userId = (bool)auth('api')->user() ? auth('api')->user()->id : $request['guest_id'];
        $userType = (bool)auth('api')->user() ? 0 : 1;

        if ($request->is_partial == 1) {
            $paymentStatus = ($request->payment_method == 'cash_on_delivery' || $request->payment_method == 'offline_payment') ? 'partial_paid' : 'paid';
        } else {
            $paymentStatus = ($request->payment_method == 'cash_on_delivery' || $request->payment_method == 'offline_payment') ? 'unpaid' : 'paid';
        }

        $orderStatus = 'pending';

        if ($request['order_type'] == 'take_away'){
            $deliveryCharge = 0;
        }
        else{
            $deliveryCharge = Helpers::get_delivery_charge(branchId: $request['branch_id'], distance:  $request['distance'], selectedDeliveryArea: $request['selected_delivery_area'], orderAmount: $request['order_amount']);
        }

        $resolvedDeliveryAddressForOrder = null;
        if ($request->delivery_address_id && ($addressModel = CustomerAddress::find($request->delivery_address_id))) {
            $resolvedDeliveryAddressForOrder = json_encode($addressModel);
        } elseif ($request->delivery_address) {
            $resolvedDeliveryAddressForOrder = json_encode($request->delivery_address);
        }

        if ($request['order_type'] == 'take_away' && $resolvedDeliveryAddressForOrder === null && isset($customer)) {
            $name = trim((string) ($customer->f_name ?? '').' '.(string) ($customer->l_name ?? ''));
            if ($name === '') {
                $name = translate('Customer');
            }
            $phone = trim((string) ($customer->phone ?? ''));
            if ($phone !== '') {
                $resolvedDeliveryAddressForOrder = json_encode([
                    'contact_person_name' => $name,
                    'contact_person_number' => $phone,
                    'address' => translate('take_away'),
                    'address_type' => 'take_away',
                    'road' => '',
                    'house' => '',
                    'floor' => '',
                    'latitude' => null,
                    'longitude' => null,
                ]);
            }
        }

        if (config('app.takeaway_contact_debug')) {
            Log::debug('place_order_takeaway_contact', [
                'order_type' => $request['order_type'],
                'resolved_delivery_address' => $resolvedDeliveryAddressForOrder !== null,
            ]);
        }

        try {
            DB::beginTransaction();

            $order_id = 100000 + (int) $this->order->max('id') + 1;
            $readableOrderId = app(\App\Services\OrderReadableIdService::class)->reserveNextReadableId();
            $or = [
                'id' => $order_id,
                'readable_order_id' => $readableOrderId,
                'user_id' => $userId,
                'is_guest' => $userType,
                'order_amount' => Helpers::set_price($request['order_amount']),
                'coupon_discount_amount' => Helpers::set_price($request->coupon_discount_amount),
                'coupon_discount_title' => $request->coupon_discount_title == 0 ? null : 'coupon_discount_title',
                'payment_status' => $paymentStatus,
                'order_status' => $orderStatus,
                'coupon_code' => $request['coupon_code'],
                'payment_method' => $request->payment_method,
                'transaction_reference' => $request->transaction_reference ?? null,
                'order_note' => $request['order_note'],
                'order_type' => $request['order_type'],
                'branch_id' => $request['branch_id'],
                'delivery_address_id' => $request->delivery_address_id,
                'delivery_date' => $deliveryDate,
                'delivery_time' => $deliveryTime,
                'delivery_address' => $resolvedDeliveryAddressForOrder,
                'delivery_charge' => $deliveryCharge,
                'preparation_time' => 0,
                'is_cutlery_required' => $request['is_cutlery_required'] ?? 0,
                'bring_change_amount' => $request->payment_method != 'cash_on_delivery' ? 0 : ($request->bring_change_amount != null ? $request->bring_change_amount : 0),
                'created_at' => now(),
                'updated_at' => now()
            ];
            $or = OnlineCheckoutIdempotency::applyToOrderAttributes($or, $checkoutUuid);

            $totalTaxAmount = 0;
            $totalProductPrice = 0;
            $totalDiscountOnProduct = 0;
            $totalAddonPrice = 0;

            foreach ($request['cart'] as $c) {
                $product = $this->product->active()->storefrontScheduleVisible()->where('id', $c['product_id'])->first();
                if (! $product || ! StorefrontVisibilitySchedule::productPasses($product, now())) {
                    return response()->json(['errors' => [['code' => 'product', 'message' => translate('no_data_found')]]], 403);
                }

                $branch_product = $this->product_by_branch->where(['product_id' => $c['product_id'], 'branch_id' => $request['branch_id']])->first();

                //daily and fixed stock quantity validation
                if($branch_product->stock_type == 'daily' || $branch_product->stock_type == 'fixed' ){
                    $available_stock = $branch_product->stock - $branch_product->sold_quantity;
                    if ($available_stock < $c['quantity']){
                        return response()->json(['errors' => [['code' => 'stock', 'message' => translate('stock limit exceeded')]]], 403);
                    }
                }

                $discount_data = [];
                $product->halal_status = $branch_product?->halal_status ?? 0;

                if ($branch_product) {
                    $branch_product_variations = $branch_product->variations;
                    $variations = [];
                    if (count($branch_product_variations)) {
                        $variation_data = Helpers::get_varient($branch_product_variations, $c['variations']);
                        $price = $branch_product['price'] + $variation_data['price'];
                        $variations = $variation_data['variations'];
                    } else {
                        $price = $branch_product['price'];
                    }
                    $discount_data = [
                        'discount_type' => $branch_product['discount_type'],
                        'discount' => $branch_product['discount'],
                    ];
                } else {
                    $product_variations = json_decode($product->variations, true);
                    $variations = [];
                    if (count($product_variations)) {
                        $variation_data = Helpers::get_varient($product_variations, $c['variations']);
                        $price = $product['price'] + $variation_data['price'];
                        $variations = $variation_data['variations'];
                    } else {
                        $price = $product['price'];
                    }
                    $discount_data = [
                        'discount_type' => $product['discount_type'],
                        'discount' => $product['discount'],
                    ];
                }

                $discount_on_product = Helpers::discount_calculate($discount_data, $price);

                /*calculation for addon and addon tax start*/
                $add_on_quantities = $c['add_on_qtys'];
                $add_on_prices = [];
                $add_on_taxes = [];

                foreach($c['add_on_ids'] as $key =>$id){
                    $addon = AddOn::find($id);
                    $add_on_prices[] = $addon['price'];
                    $add_on_taxes[] = ($addon['price']*$addon['tax'])/100;
                }

                $total_addon_tax = array_reduce(
                    array_map(function ($a, $b) {
                        return $a * $b;
                    }, $add_on_quantities, $add_on_taxes),
                    function ($carry, $item) {
                        return $carry + $item;
                    },
                    0
                );
                /*calculation for addon and addon tax end*/

                $or_d = [
                    'order_id' => $order_id,
                    'product_id' => $c['product_id'],
                    'product_details' => $product,
                    'quantity' => $c['quantity'],
                    'price' => $price,
                    'tax_amount' => Helpers::new_tax_calculate($product, $price, $discount_data),
                    'discount_on_product' => $discount_on_product,
                    'discount_type' => 'discount_on_product',
                    'variant' => json_encode($c['variant']),
                    'variation' => json_encode($variations),
                    'add_on_ids' => json_encode($c['add_on_ids']),
                    'add_on_qtys' => json_encode($c['add_on_qtys']),
                    'add_on_prices' => json_encode($add_on_prices),
                    'add_on_taxes' => json_encode($add_on_taxes),
                    'add_on_tax_amount' => $total_addon_tax,
                    'created_at' => now(),
                    'updated_at' => now()
                ];

                $totalTaxAmount += $or_d['tax_amount'] * $c['quantity'];
                $totalProductPrice += $price * $c['quantity'];
                $totalDiscountOnProduct += $discount_on_product * $c['quantity'];;
                $addonPrice = array_reduce(
                    array_map(function ($a, $b) {
                        return $a * $b;
                    }, $add_on_quantities, $add_on_prices),
                    function ($carry, $item) {
                        return $carry + $item;
                    },
                    0
                );
                $totalAddonPrice += $addonPrice;

                $this->order_detail->insert($or_d);

                $this->product->find($c['product_id'])->increment('popularity_count');

                //daily and fixed stock quantity update
                if($branch_product->stock_type == 'daily' || $branch_product->stock_type == 'fixed' ){
                    $branch_product->sold_quantity += $c['quantity'];
                    $branch_product->save();
                }
            }

            $extraDiscountValidationAmount = $totalProductPrice + $totalAddonPrice - $totalDiscountOnProduct - ($request['coupon_discount_amount'] ?? 0);

            // Calculate referral first order discount
            $referralDiscount = 0;
            if (auth('api')->check()) {
                $registeredCustomer = auth('api')->user();

                if ($registeredCustomer?->referral_customer_details && $registeredCustomer?->referral_customer_details?->customer_discount_amount > 0 && $registeredCustomer?->referral_customer_details?->is_used == 0) {
                    $referralDiscount = $this->calculateReferralDiscount(referral: $registeredCustomer?->referral_customer_details, orderAmount: $extraDiscountValidationAmount);
                }
            }

            $or['total_tax_amount'] = $totalTaxAmount;
            $or['referral_discount'] = $referralDiscount;
            $or = array_merge($or, OrderPlacementTime::insertAttributes());

            $o_id = $this->order->insertGetId($or);

            if ($request->payment_method == 'wallet_payment') {
                $amount = $or['order_amount'] + $or['delivery_charge'];
                CustomerLogic::create_wallet_transaction($or['user_id'], $amount, 'order_place', $or['id']);
            }

            if ($request->payment_method == 'offline_payment') {
                $offlinePayment = $this->offlinePayment;
                $offlinePayment->order_id = $or['id'];
                $offlinePayment->payment_info = json_encode($request['payment_info']);
                $offlinePayment->save();
            }

            if ($request['is_partial'] == 1){
                $totalOrderAmount = $or['order_amount'] + $or['delivery_charge'];
                $walletAmount = $customer->wallet_balance;
                $dueAmount = $totalOrderAmount - $walletAmount;

                $walletTransaction = CustomerLogic::create_wallet_transaction($or['user_id'], $walletAmount, 'order_place', $or['id']);

                $partial = new OrderPartialPayment;
                $partial->order_id = $or['id'];
                $partial->paid_with = 'wallet_payment';
                $partial->paid_amount = $walletAmount;
                $partial->due_amount = $dueAmount;
                $partial->save();

                if ($request['payment_method'] != 'cash_on_delivery'){
                    $partial = new OrderPartialPayment;
                    $partial->order_id = $or['id'];
                    $partial->paid_with = $request['payment_method'];
                    $partial->paid_amount = $dueAmount;
                    $partial->due_amount = 0;
                    $partial->save();
                }
            }

            if($request['selected_delivery_area']){
                $orderArea = $this->orderArea;
                $orderArea->order_id = $order_id;
                $orderArea->branch_id = $or['branch_id'];
                $orderArea->area_id = $request['selected_delivery_area'];
                $orderArea->save();
            }

            if (auth('api')->check()) {
                $registeredCustomer = auth('api')->user();

                if ($registeredCustomer?->referral_customer_details && $registeredCustomer?->referral_customer_details->is_used == 0) {
                    $registeredCustomer?->referral_customer_details->update(['is_used' => 1]);

                    $refer_user = $this->user->where(['id' => $registeredCustomer?->refer_by])->first();
                    if (isset($refer_user) && $referralDiscount > 0){
                        $this->sendNotificationToReferralUser(referredUser: $refer_user);
                    }
                }
            }

            DB::commit();

            $this->finishOnlineOrderPlacement($order_id, $request);

            return response()->json([
                'message' => translate('order_success'),
                'order_id' => $order_id,
                'readable_order_id' => $readableOrderId,
                'order_display_id' => $readableOrderId,
            ], 200);

        } catch (UniqueConstraintViolationException $e) {
            DB::rollBack();
            $existingCheckoutOrder = OnlineCheckoutIdempotency::recoverExistingFromException($checkoutUuid, $e);
            if ($existingCheckoutOrder) {
                return OnlineCheckoutIdempotency::successResponse($existingCheckoutOrder);
            }

            return $this->existingPaystackOrderResponse($request, $paystackReference) ?? response()->json([$e], 403);
        } catch (QueryException $e) {
            DB::rollBack();
            $existingCheckoutOrder = OnlineCheckoutIdempotency::recoverExistingFromException($checkoutUuid, $e);
            if ($existingCheckoutOrder) {
                return OnlineCheckoutIdempotency::successResponse($existingCheckoutOrder);
            }

            return $this->existingPaystackOrderResponse($request, $paystackReference) ?? response()->json([$e], 403);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([$e], 403);
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function finishOnlineOrderPlacement(int $orderId, Request $request): void
    {
        $persisted = Order::query()->find($orderId);
        if ($persisted) {
            AbandonedCheckoutService::linkOrderConversion($persisted);
        }

        SendOnlineOrderPlacementNotificationsJob::dispatch(
            $orderId,
            isset($request['guest_id']) ? (int) $request['guest_id'] : null,
            auth('api')->id()
        )->afterResponse();
    }

    private function existingPaystackOrderResponse(Request $request, string $paystackReference): ?\Illuminate\Http\JsonResponse
    {
        if ((string) $request->payment_method !== 'paystack' || $paystackReference === '') {
            return null;
        }

        $existingPaystackOrder = $this->order->newQuery()
            ->where('payment_method', 'paystack')
            ->where('transaction_reference', $paystackReference)
            ->orderByDesc('id')
            ->first();

        return $existingPaystackOrder ? OnlineCheckoutIdempotency::successResponse($existingPaystackOrder) : null;
    }

    public function sendNotificationToReferralUser($referredUser)
    {
        $message = Helpers::order_status_update_message('referral_code_user_first_order_place_message');
        $restaurantName = Helpers::get_business_settings('restaurant_name');
        $customerName = ($referredUser->f_name ?? '') . ' ' . ($referredUser->l_name ?? '');
        $local = $referredUser->language_code ?? 'en';

        if ($local != 'en'){
            $translatedMessage = BusinessSetting::with('translations')->where(['key' => 'referral_code_user_first_order_place_message'])->first();
            if (isset($translatedMessage->translations)){
                foreach ($translatedMessage->translations as $translation){
                    if ($local == $translation->locale){
                        $message = $translation->value;
                    }
                }
            }
        }

        $value = Helpers::text_variable_data_format(value:$message, user_name: $customerName, restaurant_name: $restaurantName);
        $customerFcmToken = $referredUser->cm_firebase_token ?? null;

        if ($value && isset($customerFcmToken)) {
            $data = [
                'title' => translate('Referral code user First order place'),
                'description' => $value,
                'order_id' => '',
                'image' => '',
                'type' => 'referral',
            ];

            try {
                Helpers::send_push_notif_to_device($customerFcmToken, $data);
            }catch (\Exception $e) {
                //
            }
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function getOrderList(Request $request): JsonResponse
    {
        $userId = (bool)auth('api')->user() ? auth('api')->user()->id : $request['guest_id'];
        $userType = (bool)auth('api')->user() ? 0 : 1;
        $orderFilter = $request->order_filter;

        $orders = $this->order->with(['customer', 'delivery_man.rating'])
            ->withCount('details')
            ->withCount(['details as total_quantity' => function($query) {
                $query->select(DB::raw('sum(quantity)'));
            }])
            ->where(['user_id' => $userId, 'is_guest' => $userType])
            ->when($orderFilter == 'history', function ($query) use ($orderFilter) {
                $query->whereIn('order_status', ['delivered', 'canceled', 'failed', 'returned']);
            })
            ->when($orderFilter == 'ongoing', function ($query) use ($orderFilter) {
                $query->whereNotIn('order_status', ['delivered', 'canceled', 'failed', 'returned']);
            })
            ->orderBy('id', 'DESC')
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);


        $orders->map(function ($data) {
            $data['deliveryman_review_count'] = DMReview::where(['delivery_man_id' => $data['delivery_man_id'], 'order_id' => $data['id']])->count();

            $order_id = $data->id;
            $order_details = $this->order_detail->where('order_id', $order_id)->first();
            $product_id = $order_details?->product_id;

            $data['is_product_available'] = $product_id ? (count(StorefrontVisibilitySchedule::filterProductIds([(int) $product_id])) > 0 ? 1 : 0) : 0;
            $data['details_count'] = (int)$data->details_count;

            $productImages = $this->order_detail->where('order_id', $order_id)->pluck('product_id')
                ->filter()
                ->map(function ($product_id) {
                    $product = $this->product->find($product_id);
                    return $product ? $product->image : null;
                })->filter();

            $data['product_images'] = $productImages->toArray();
            $data['readable_order_id'] = $data->readable_order_id;
            $data['order_display_id'] = Helpers::order_display_id($data);

            return $data;
        });

        $ordersArray = [
            'total_size' => $orders->total(),
            'limit' => $request['limit'],
            'offset' => $request['offset'],
            'orders' => $orders->items(),
        ];

        return response()->json($ordersArray, 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function getOrderDetails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $userId = (bool)auth('api')->user() ? auth('api')->user()->id : $request['guest_id'];
        $userType = (bool)auth('api')->user() ? 0 : 1;

        $order = \App\Support\OrderPublicNumber::resolveForCustomer((string) $request['order_id'], (int) $userId, (int) $userType);
        $details = collect();
        if ($order) {
            $details = $this->order_detail->with(['order',
                'order.delivery_man' => function ($query) {
                    $query->select('id', 'f_name', 'l_name', 'phone', 'email', 'image', 'branch_id', 'is_active');
                },
                'order.delivery_man.rating', 'order.delivery_address', 'order.order_partial_payments' , 'order.offline_payment', 'order.deliveryman_review'])
                ->withCount(['reviews'])
                ->where(['order_id' => $order->id])
                ->whereHas('order', function ($q) use ($userId, $userType){
                    $q->where([ 'user_id' => $userId, 'is_guest' => $userType ]);
                })
                ->get();
        }

        if ($details->count() < 1) {
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('Order not found!')]
                ]
            ], 404);
        }

        $details = Helpers::order_details_formatter($details);
        return response()->json($details, 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function cancelOrder(Request $request): JsonResponse
    {
        $order = \App\Support\OrderPublicNumber::resolve((string) $request['order_id']);

        if (!isset($order)){
            return response()->json(['errors' => [['code' => 'order', 'message' => 'Order not found!']]], 404);
        }

        if ($order->order_status != 'pending'){
            return response()->json(['errors' => [['code' => 'order', 'message' => 'Order can only cancel when order status is pending!']]], 403);
        }

        $userId = (bool)auth('api')->user() ? auth('api')->user()->id : $request['guest_id'];
        $userType = (bool)auth('api')->user() ? 0 : 1;

        if ($this->order->where(['user_id' => $userId, 'is_guest' => $userType, 'id' => $order->id])->first()) {
            $this->order->where(['user_id' => $userId, 'is_guest' => $userType, 'id' => $order->id])->update([
                'order_status' => 'canceled'
            ]);
            return response()->json(['message' => translate('order_canceled')], 200);
        }
        return response()->json([
            'errors' => [
                ['code' => 'order', 'message' => translate('no_data_found')]
            ]
        ], 401);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function updatePaymentMethod(Request $request): JsonResponse
    {
        if ($this->order->where(['user_id' => $request->user()->id, 'id' => $request['order_id']])->first()) {
            $this->order->where(['user_id' => $request->user()->id, 'id' => $request['order_id']])->update([
                'payment_method' => $request['payment_method']
            ]);
            return response()->json(['message' => translate('payment_method_updated')], 200);
        }
        return response()->json([
            'errors' => [
                ['code' => 'order', 'message' => translate('no_data_found')]
            ]
        ], 401);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function guestTrackOrder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'phone' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $orderId = $request->input('order_id');
        $phone = $request->input('phone');
        //dd($orderId, $phone);

        $order = $this->order->with(['customer'])
            ->where('id', $orderId)
            ->where(function ($query) use ($phone) {
                $query->where(function ($subQuery) use ($phone) {
                    $subQuery->where('is_guest', 0)
                        ->whereHas('customer', function ($customerSubQuery) use ($phone) {
                            $customerSubQuery->where('phone', $phone);
                        });
                })
                    ->orWhere(function ($subQuery) use ($phone) {
                        // Check for guest orders (both old & new formats)
                        $subQuery->where('is_guest', 1)
                            ->whereHas('delivery_address', function ($addressSubQuery) use ($phone) {
                                // Old method: Check `delivery_address` table
                                $addressSubQuery->where('contact_person_number', $phone);
                            })
                            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.delivery_address, '$.contact_person_number')) = ?", [$phone]);
                        // New method: Check JSON column in `orders` table
                    });
            })
            ->first();


        if (!isset($order)) {
            return response()->json(['errors' => [['code' => 'order', 'message' => translate('Order not found!')]]], 404);
        }

        return response()->json(OrderLogic::track_order($request['order_id']), 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function getGuestOrderDetails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'phone' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $phone = $request->input('phone');

        $details = $this->order_detail->with([
            'order',
            'order.customer',
            'order.order_partial_payments'
        ])
            ->withCount(['reviews'])
            ->where(['order_id' => $request['order_id']])
            ->where(function ($query) use ($phone) {
                $query->where(function ($subQuery) use ($phone) {
                    // Check for registered customers (not guests)
                    $subQuery->whereHas('order', function ($orderSubQuery) use ($phone) {
                        $orderSubQuery->where('is_guest', 0)
                            ->whereHas('customer', function ($customerSubQuery) use ($phone) {
                                $customerSubQuery->where('phone', $phone);
                            });
                    });
                })
                    ->orWhere(function ($subQuery) use ($phone) {
                        // Check for guest orders (both old & new formats)
                        $subQuery->whereHas('order', function ($orderSubQuery) use ($phone) {
                            $orderSubQuery->where('is_guest', 1)
                                ->whereHas('delivery_address', function ($addressSubQuery) use ($phone) {
                                    // Old format: Check `delivery_address` table
                                    $addressSubQuery->where('contact_person_number', $phone);
                                })
                                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.delivery_address, '$.contact_person_number')) = ?", [$phone]);
                            // New format: Check JSON column in `orders` table
                        });
                    });
            })
            ->get();

        if ($details->count() < 1) {
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('Order not found!')]
                ]
            ], 404);
        }

        $details = Helpers::order_details_formatter($details);
        return response()->json($details, 200);
    }

    private function calculateReferralDiscount($referral, $orderAmount): float
    {
        // Check discount validity
        $validityValue = (int)($referral->customer_discount_validity ?? 0);
        $validityType = $referral->customer_discount_validity_type ?? 'day';
        $isValid = true;

        if ($validityValue > 0) {
            $createdAt = $referral->created_at;

            $validUntil = match ($validityType) {
                'day'   => $createdAt->copy()->addDays($validityValue),
                'week'  => $createdAt->copy()->addWeeks($validityValue),
                'month' => $createdAt->copy()->addMonths($validityValue),
                default => $createdAt,
            };

            $isValid = now()->lte($validUntil); // Check if still valid
        }

        if (!$isValid) {
            return 0;
        }

        // Calculate discount
        $discountAmount = (float)($referral->customer_discount_amount ?? 0);
        $discountType   = $referral->customer_discount_amount_type ?? 'amount';

        $calculatedDiscount = $discountType == 'percent'
            ? ($orderAmount * $discountAmount) / 100
            : $discountAmount;

        // Make sure discount does not exceed order amount
        $finalDiscount = min($calculatedDiscount, $orderAmount);

        return $finalDiscount;
    }

}


