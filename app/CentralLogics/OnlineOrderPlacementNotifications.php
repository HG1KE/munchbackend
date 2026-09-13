<?php

namespace App\CentralLogics;

use App\Mail\OrderPlaced;
use App\Model\Branch;
use App\Model\BusinessSetting;
use App\Model\Order;
use App\Models\GuestUser;
use App\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use function App\CentralLogics\translate;

/**
 * Customer/kitchen/admin placement notifications for an already-committed online order.
 * Must never run inside the place-order HTTP response.
 */
class OnlineOrderPlacementNotifications
{
    public static function send(Order $order, ?int $guestId = null, ?int $authenticatedUserId = null): void
    {
        $orderId = (int) $order->id;
        $or = [
            'id' => $orderId,
            'readable_order_id' => $order->readable_order_id,
            'order_status' => $order->order_status,
            'branch_id' => $order->branch_id,
            'user_id' => $order->user_id,
            'is_guest' => $order->is_guest,
            'order_amount' => $order->order_amount,
            'delivery_charge' => $order->delivery_charge,
        ];

        $user = $authenticatedUserId ? User::query()->find($authenticatedUserId) : null;
        if ($user === null && (int) $order->is_guest === 0 && $order->user_id) {
            $user = User::query()->find($order->user_id);
        }

        if ($user) {
            $fcmToken = $user->cm_firebase_token;
            $local = $user->language_code;
            $customerName = trim(($user->f_name ?? '').' '.($user->l_name ?? ''));
        } else {
            $guest = $guestId ? GuestUser::query()->find($guestId) : null;
            $fcmToken = $guest ? $guest->fcm_token : '';
            $local = 'en';
            $customerName = 'Guest User';
        }

        $message = Helpers::order_status_update_message($or['order_status']);

        if ($local != 'en') {
            $statusKey = Helpers::order_status_message_key($or['order_status']);
            $translatedMessage = BusinessSetting::with('translations')->where(['key' => $statusKey])->first();
            if (isset($translatedMessage->translations)) {
                foreach ($translatedMessage->translations as $translation) {
                    if ($local == $translation->locale) {
                        $message = $translation->value;
                    }
                }
            }
        }

        $restaurantName = Helpers::get_business_settings('restaurant_name');
        $displayOrderId = Helpers::order_display_id($or);
        $value = Helpers::text_variable_data_format(
            value: $message,
            user_name: $customerName,
            restaurant_name: $restaurantName,
            order_id: $displayOrderId
        );

        try {
            if ($value && isset($fcmToken)) {
                $data = [
                    'title' => translate('Order'),
                    'description' => $value,
                    'order_id' => $user ? $orderId : null,
                    'image' => '',
                    'type' => 'order_status',
                ];
                Helpers::send_push_notif_to_device($fcmToken, $data);
            }
        } catch (Throwable $e) {
            //
        }

        try {
            $emailServices = Helpers::get_business_settings('mail_config');
            $orderMailStatus = Helpers::get_business_settings('place_order_mail_status_user');
            if (isset($emailServices['status']) && $emailServices['status'] == 1 && $orderMailStatus == 1 && $user) {
                Mail::to($user->email)->send(new OrderPlaced($orderId));
            }
        } catch (Throwable $e) {
            //
        }

        if (in_array($or['order_status'], ['pending', 'confirmed'], true)) {
            $data = [
                'title' => translate('You have a new order - (Order Confirmed).'),
                'description' => $displayOrderId,
                'order_id' => $orderId,
                'readable_order_id' => $or['readable_order_id'] ?? null,
                'order_display_id' => $displayOrderId,
                'image' => '',
                'order_status' => $or['order_status'],
            ];

            try {
                Helpers::send_push_notif_to_topic(data: $data, topic: "kitchen-{$or['branch_id']}", type: 'general', isNotificationPayloadRemove: true);
            } catch (Throwable $e) {
                //
            }
        }

        if (in_array($or['order_status'], ['pending', 'confirmed'], true)) {
            $persisted = Order::with(['customer', 'branch'])->find($orderId);
            if ($persisted) {
                CustomerOrderStatusSms::dispatchPlacement($persisted);
            }
        }

        try {
            $data = [
                'title' => translate('New Order Notification'),
                'description' => translate('You have new order, Check Please'),
                'order_id' => $orderId,
                'image' => '',
                'type' => 'new_order_admin',
            ];

            Helpers::send_push_notif_to_topic(data: $data, topic: 'admin_message', type: 'order_request', web_push_link: route('admin.orders.list', ['status' => 'all']));
            Helpers::send_push_notif_to_topic(data: $data, topic: 'branch-order-'.$or['branch_id'].'-message', type: 'order_request', web_push_link: route('branch.orders.list', ['status' => 'all']));

            try {
                $config = SMS_module::get_settings('textsms_ke_not');
                if (isset($config) && $config['status'] == 1) {
                    $branch = Branch::find($or['branch_id']);
                    if ($branch && ! empty($branch->phone)) {
                        $customer_name = '';
                        if ((int) $or['is_guest'] === 0 && isset($or['user_id'])) {
                            $customer = User::find($or['user_id']);
                            if ($customer) {
                                $customer_name = $customer->f_name.' '.$customer->l_name;
                            }
                        }

                        $sms_data = $data;
                        $sms_data['customer_name'] = $customer_name;
                        $total_amount = $or['order_amount'] + $or['delivery_charge'];
                        $sms_data['order_amount'] = number_format($total_amount, 2);

                        SMS_module::textsms_ke_not($branch->phone, $sms_data);
                    }
                }
            } catch (Throwable $e) {
                Log::error('SMS Notification Error: '.$e->getMessage());
            }
        } catch (Throwable $exception) {
            //
        }
    }
}
