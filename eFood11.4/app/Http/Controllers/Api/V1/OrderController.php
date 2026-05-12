<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\CustomerLogic;
use App\CentralLogics\Helpers;
use App\CentralLogics\OrderLogic;
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
use App\Model\TimeSchedule;
use App\Models\GuestUser;
use App\Models\OfflinePayment;
use App\Models\OrderPartialPayment;
use App\Models\OrderArea;
use App\Models\ReferralCustomer;
use App\User;
use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
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

        $order = $this->order->where(['id' => $request['order_id'], 'user_id' => $userId, 'is_guest' => $userType])->first();
        if (!isset($order)) {
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('Order not found!')]
                ]
            ], 404);
        }

        return response()->json(OrderLogic::track_order($request['order_id']), 200);
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

        if (count($request['cart']) < 1) {
            return response()->json(['errors' => [['code' => 'empty-cart', 'message' => translate('cart is empty')]]], 403);
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
        $preparation_time = Branch::where(['id' => $request['branch_id']])->first()->preparation_time ?? 0;

        if ($request['delivery_time'] == 'now') {
            $deliveryDate = Carbon::now()->format('Y-m-d');
            $deliveryTime = Carbon::now()->add($preparation_time, 'minute')->format('H:i:s');
        } else {
            $deliveryDate = $request['delivery_date'];
            $deliveryTime = Carbon::parse($request['delivery_time'])->add($preparation_time, 'minute')->format('H:i:s');
        }

        // Validate branch availability time slot (backward compatible)
        // Only validate if branch availability feature is being used
        $requestedTime = Carbon::parse($deliveryTime)->format('H:i:s');
        
        // Check if any branch has specific schedules configured
        $branchSchedulesExist = \App\Model\BranchTimeSchedule::where('branch_id', $request['branch_id'])->exists();
        
        // Only validate if branch has specific schedules OR if restaurant schedules exist
        if ($branchSchedulesExist || \App\Model\TimeSchedule::exists()) {
            // Prevent scheduling orders for future dates
            if ($deliveryDate !== Carbon::now()->format('Y-m-d')) {
                return response()->json(['errors' => [['code' => 'date', 'message' => translate('orders_can_only_be_placed_for_today')]]], 403);
            }
            
            // Check if branch is available for the requested time
            if (!Helpers::isBranchAvailable($request['branch_id'], $deliveryDate, $requestedTime)) {
                return response()->json(['errors' => [['code' => 'time', 'message' => translate('order_cannot_be_placed_outside_branch_availability_hours')]]], 403);
            }
            
            // Additional check: Prevent ordering if current time has passed today's closing time
            if (!Helpers::canStillOrderFromBranchToday($request['branch_id'])) {
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

        $orderStatus = ($request->payment_method == 'cash_on_delivery' || $request->payment_method == 'offline_payment') ? 'pending' : 'confirmed';

        if ($request['order_type'] == 'take_away'){
            $deliveryCharge = 0;
        }
        else{
            $deliveryCharge = Helpers::get_delivery_charge(branchId: $request['branch_id'], distance:  $request['distance'], selectedDeliveryArea: $request['selected_delivery_area'], orderAmount: $request['order_amount']);
        }

        try {
            DB::beginTransaction();

            $order_id = 100000 + $this->order->all()->count() + 1;
            $or = [
                'id' => $order_id,
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
                'delivery_address' => $request->delivery_address_id && ($address = CustomerAddress::find($request->delivery_address_id))
                    ? json_encode($address)
                    : ($request->delivery_address
                        ? json_encode($request->delivery_address)
                        : null),
                'delivery_charge' => $deliveryCharge,
                'preparation_time' => 0,
                'is_cutlery_required' => $request['is_cutlery_required'] ?? 0,
                'bring_change_amount' => $request->payment_method != 'cash_on_delivery' ? 0 : ($request->bring_change_amount != null ? $request->bring_change_amount : 0),
                'created_at' => now(),
                'updated_at' => now()
            ];

            $totalTaxAmount = 0;
            $totalProductPrice = 0;
            $totalDiscountOnProduct = 0;
            $totalAddonPrice = 0;

            foreach ($request['cart'] as $c) {
                $product = $this->product->find($c['product_id']);

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

            // Send Email & Notification
            $this->orderEmailAndNotification(request: $request, or : $or, order_id: $order_id);

            return response()->json([
                'message' => translate('order_success'),
                'order_id' => $order_id
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack(); // Rollback transaction on failure
            return response()->json([$e], 403);
        }
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

    private function orderEmailAndNotification($request, $or, $order_id)
    {
        if ((bool)auth('api')->user()){
            $fcmToken = auth('api')->user()?->cm_firebase_token;
            $local = auth('api')->user()?->language_code;
            $customerName = auth('api')->user()?->f_name . ' '. auth('api')->user()?->l_name;
            }else{
                $guest = GuestUser::find($request['guest_id']);
                $fcmToken = $guest ? $guest->fcm_token : '';
                $local = 'en';
                $customerName = 'Guest User';
            }

            $message = Helpers::order_status_update_message($or['order_status']);

            if ($local != 'en'){
                $statusKey = Helpers::order_status_message_key($or['order_status']);
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
            $value = Helpers::text_variable_data_format(value:$message, user_name: $customerName, restaurant_name: $restaurantName,  order_id: $order_id);

            try {
                if ($value && isset($fcmToken)) {
                    $data = [
                        'title' => translate('Order'),
                        'description' => $value,
                        'order_id' => (bool)auth('api')->user() ? $order_id : null,
                        'image' => '',
                        'type' => 'order_status',
                    ];
                    Helpers::send_push_notif_to_device($fcmToken, $data);
                }
            } catch (\Exception $e) {
                //
            }

            try {
                $emailServices = Helpers::get_business_settings('mail_config');
                $orderMailStatus = Helpers::get_business_settings('place_order_mail_status_user');
                if (isset($emailServices['status']) && $emailServices['status'] == 1 && $orderMailStatus == 1 && (bool)auth('api')->user()) {
                    Mail::to(auth('api')->user()->email)->send(new \App\Mail\OrderPlaced($order_id));
                }
            }catch (\Exception $e) {
                //
            }

            if ($or['order_status'] == 'confirmed') {
                $data = [
                    'title' => translate('You have a new order - (Order Confirmed).'),
                    'description' => $order_id,
                    'order_id' => $order_id,
                    'image' => '',
                    'order_status' => $or['order_status'],
                ];

                try {
                    Helpers::send_push_notif_to_topic(data: $data, topic: "kitchen-{$or['branch_id']}", type: 'general', isNotificationPayloadRemove: true);

                } catch (\Exception $e) {
                    //
                }
            }

            try {
                $data = [
                    'title' => translate('New Order Notification'),
                    'description' => translate('You have new order, Check Please'),
                    'order_id' => $order_id,
                    'image' => '',
                    'type' => 'new_order_admin',
                ];

                // Send the original push notifications without any changes
                Helpers::send_push_notif_to_topic(data: $data, topic: 'admin_message', type: 'order_request', web_push_link: route('admin.orders.list',['status'=>'all']));
                Helpers::send_push_notif_to_topic(data: $data, topic: 'branch-order-'. $or['branch_id'] .'-message', type: 'order_request', web_push_link: route('branch.orders.list',['status'=>'all']));
                
                // Send SMS notification to branch if textsms_ke_not is enabled
                // This is completely separate from the order creation process
                try {
                    // Check if the SMS gateway is enabled
                    $config = \App\CentralLogics\SMS_module::get_settings('textsms_ke_not');
                    if (isset($config) && $config['status'] == 1) {
                        $branch = \App\Model\Branch::find($or['branch_id']);
                        if ($branch && !empty($branch->phone)) {
                            // Get customer name
                            $customer_name = '';
                            if ($or['is_guest'] == 0 && isset($or['user_id'])) {
                                $customer = \App\User::find($or['user_id']);
                                if ($customer) {
                                    $customer_name = $customer->f_name . ' ' . $customer->l_name;
                                }
                            } elseif ($or['is_guest'] == 1 && isset($or['guest_id'])) {
                                $guest = \App\Model\Guest::find($or['guest_id']);
                                if ($guest) {
                                    $customer_name = $guest->name;
                                }
                            }
                            
                            // Add customer name and order amount to the data
                            $sms_data = $data;
                            $sms_data['customer_name'] = $customer_name;
                            // Calculate total amount including delivery charge
                            $total_amount = $or['order_amount'] + $or['delivery_charge'];
                            $sms_data['order_amount'] = number_format($total_amount, 2);
                            
                            // Send SMS directly using SMS_module without creating any new orders
                            \App\CentralLogics\SMS_module::textsms_ke_not($branch->phone, $sms_data);
                        }
                    }
                } catch (\Exception $e) {
                    // Log error but don't interrupt the order process
                    \Illuminate\Support\Facades\Log::error('SMS Notification Error: ' . $e->getMessage());
                }
            } catch (\Exception $exception) {
                //
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

            $data['is_product_available'] = $product_id ? $this->product->find($product_id) ? 1 : 0 : 0;
            $data['details_count'] = (int)$data->details_count;

            $productImages = $this->order_detail->where('order_id', $order_id)->pluck('product_id')
                ->filter()
                ->map(function ($product_id) {
                    $product = $this->product->find($product_id);
                    return $product ? $product->image : null;
                })->filter();

            $data['product_images'] = $productImages->toArray();

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

        $details = $this->order_detail->with(['order',
            'order.delivery_man' => function ($query) {
                $query->select('id', 'f_name', 'l_name', 'phone', 'email', 'image', 'branch_id', 'is_active');
            },
            'order.delivery_man.rating', 'order.delivery_address', 'order.order_partial_payments' , 'order.offline_payment', 'order.deliveryman_review'])
            ->withCount(['reviews'])
            ->where(['order_id' => $request['order_id']])
            ->whereHas('order', function ($q) use ($userId, $userType){
                $q->where([ 'user_id' => $userId, 'is_guest' => $userType ]);
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

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function cancelOrder(Request $request): JsonResponse
    {
        $order = $this->order::find($request['order_id']);

        if (!isset($order)){
            return response()->json(['errors' => [['code' => 'order', 'message' => 'Order not found!']]], 404);
        }

        if ($order->order_status != 'pending'){
            return response()->json(['errors' => [['code' => 'order', 'message' => 'Order can only cancel when order status is pending!']]], 403);
        }

        $userId = (bool)auth('api')->user() ? auth('api')->user()->id : $request['guest_id'];
        $userType = (bool)auth('api')->user() ? 0 : 1;

        if ($this->order->where(['user_id' => $userId, 'is_guest' => $userType, 'id' => $request['order_id']])->first()) {
            $this->order->where(['user_id' => $userId, 'is_guest' => $userType, 'id' => $request['order_id']])->update([
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


