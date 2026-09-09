<?php

namespace App\CentralLogics;

use App\Model\Order;
use App\Models\OrderPartialPayment;
use App\User;
use Illuminate\Support\Facades\Log;

/**
 * Shared admin delivered transition (loyalty, SMS, notifications, payment side-effects).
 */
class OrderDeliveredTransitionService
{
    /**
     * @return array{success: bool, user_message: string, reason: string, loyalty_result: int|false|null, order: ?Order}
     */
    public static function transitionToDelivered(Order $order, string $handlerPath, array $options = []): array
    {
        $validation = self::validateForDelivered($order, $options);
        if (! $validation['allowed']) {
            return [
                'success' => false,
                'user_message' => $validation['user_message'],
                'reason' => $validation['reason'],
                'loyalty_result' => null,
                'order' => $order,
            ];
        }

        try {
            $order->loadMissing(['transaction', 'customer', 'branch', 'delivery_man', 'guest']);

            $loyaltyResult = null;

            if ((int) $order->is_guest === 0 && $order->user_id) {
                $loyaltyResult = CustomerLogic::create_loyalty_point_transaction(
                    $order->user_id,
                    $order->id,
                    $order->order_amount,
                    'order_place'
                );
            }

            if ((int) $order->is_guest === 0 && $order->user_id) {
                if ($order->transaction === null) {
                    app(OrderLogic::class)->create_transaction($order, 'admin');
                }

                $user = User::query()->find($order->user_id);
                if ($user) {
                    $referralData = $user->referral_customer_details;
                    if ($referralData && (int) ($referralData->is_used_by_refer ?? 0) === 0) {
                        $referralEarningAmount = $referralData->ref_by_earning_amount ?? 0;
                        $referredByUser = $referralEarningAmount > 0
                            ? User::query()->find($user->refer_by)
                            : null;

                        if ($referralEarningAmount > 0 && $referredByUser) {
                            CustomerLogic::referral_earning_wallet_transaction(
                                $order->user_id,
                                'referral_order_place',
                                $referredByUser->id,
                                $referralEarningAmount
                            );
                        }
                    }
                }
            }

            if ($order->payment_method === 'cash_on_delivery') {
                $partialData = OrderPartialPayment::query()->where(['order_id' => $order->id])->first();
                if ($partialData) {
                    $partial = new OrderPartialPayment;
                    $partial->order_id = $order->id;
                    $partial->paid_with = 'cash_on_delivery';
                    $partial->paid_amount = $partialData->due_amount;
                    $partial->due_amount = 0;
                    $partial->save();
                }
            }

            $previousOrderStatus = $order->order_status;
            $order->order_status = 'delivered';
            $order->payment_status = 'paid';
            $order->save();

            $orderFresh = $order->fresh(['customer', 'branch', 'delivery_man', 'guest']);

            CustomerOrderStatusSms::dispatchProcessing($orderFresh, $previousOrderStatus);
            LoyaltyDeliverySmsService::attemptAfterDeliveredTransition($orderFresh, $loyaltyResult, $handlerPath);

            self::sendCustomerStatusPush($orderFresh);

            Log::info('order.delivered_transition.completed', [
                'handler' => $handlerPath,
                'order_id' => $orderFresh->id,
                'previous_status' => $previousOrderStatus,
                'loyalty_result' => $loyaltyResult,
            ]);

            return [
                'success' => true,
                'user_message' => translate('Order status updated!'),
                'reason' => 'delivered',
                'loyalty_result' => $loyaltyResult,
                'order' => $orderFresh,
            ];
        } catch (\Throwable $e) {
            Log::error('order.delivered_transition.failed', [
                'handler' => $handlerPath,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'user_message' => translate('Order status update failed'),
                'reason' => 'exception',
                'loyalty_result' => null,
                'order' => $order,
            ];
        }
    }

    /**
     * @return array{allowed: bool, reason: string, user_message: string}
     */
    public static function validateForDelivered(Order $order, array $options = []): array
    {
        if (in_array($order->order_status, ['delivered', 'failed'], true)) {
            return [
                'allowed' => false,
                'reason' => 'terminal_status',
                'user_message' => translate('you_can_not_change_the_status_of '.$order->order_status.' order'),
            ];
        }

        if ($order->transaction_reference === null
            && ! in_array($order->payment_method, ['cash_on_delivery', 'wallet_payment', 'offline_payment'], true)) {
            return [
                'allowed' => false,
                'reason' => 'missing_payment_reference',
                'user_message' => translate('add_your_payment_reference_first'),
            ];
        }

        if (self::mustHaveDeliveryManAssigned($order, $options)) {
            return [
                'allowed' => false,
                'reason' => 'missing_delivery_man',
                'user_message' => translate('Please assign delivery man first!'),
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'ok',
            'user_message' => '',
        ];
    }

    /**
     * @param  array{require_delivery_man?: bool, delivery_man_exempt_order_types?: array<int, string>}  $options
     */
    public static function mustHaveDeliveryManAssigned(Order $order, array $options = []): bool
    {
        if (! ($options['require_delivery_man'] ?? true)) {
            return false;
        }

        $exemptTypes = $options['delivery_man_exempt_order_types'] ?? ['take_away'];

        if (in_array($order->order_type, $exemptTypes, true)) {
            return false;
        }

        return $order->delivery_man_id === null;
    }

    private static function sendCustomerStatusPush(Order $order): void
    {
        try {
            $message = Helpers::order_status_update_message('delivered');
            $restaurantName = Helpers::get_business_settings('restaurant_name');
            $deliverymanName = $order->delivery_man
                ? trim(($order->delivery_man->f_name ?? '').' '.($order->delivery_man->l_name ?? ''))
                : '';
            $customerName = (int) $order->is_guest === 0
                ? ($order->customer ? trim(($order->customer->f_name ?? '').' '.($order->customer->l_name ?? '')) : '')
                : 'Guest User';
            $local = (int) $order->is_guest === 0
                ? ($order->customer->language_code ?? 'en')
                : 'en';

            if ($local !== 'en') {
                $statusKey = Helpers::order_status_message_key('delivered');
                $translatedMessage = \App\Model\BusinessSetting::query()
                    ->with('translations')
                    ->where(['key' => $statusKey])
                    ->first();
                if ($translatedMessage && isset($translatedMessage->translations)) {
                    foreach ($translatedMessage->translations as $translation) {
                        if ($local === $translation->locale) {
                            $message = $translation->value;
                        }
                    }
                }
            }

            $value = Helpers::text_variable_data_format(
                value: $message,
                user_name: $customerName,
                restaurant_name: $restaurantName,
                delivery_man_name: $deliverymanName,
                order_id: Helpers::order_display_id($order)
            );

            if (! $value) {
                return;
            }

            $customerFcmToken = null;
            if ((int) $order->is_guest === 0) {
                $customerFcmToken = $order->customer?->cm_firebase_token;
            } elseif ((int) $order->is_guest === 1) {
                $customerFcmToken = $order->guest?->fcm_token;
            }

            if ($customerFcmToken) {
                Helpers::send_push_notif_to_device($customerFcmToken, [
                    'title' => translate('Order'),
                    'description' => $value,
                    'order_id' => $order->id,
                    'image' => '',
                    'type' => 'order_status',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('order.delivered_transition.push_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
