<?php

namespace App\CentralLogics;

use App\Model\BusinessSetting;
use App\Model\PointTransitions;
use App\Model\WalletBonus;
use App\User;
use App\Model\WalletTransaction;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerLogic{

    public const ADMIN_WALLET_CREDIT_TYPE = 'add_fund_by_admin';
    public const ADMIN_WALLET_DEBIT_TYPE = 'debit_by_admin';

    public static function create_wallet_transaction($user_id, float $amount, $transaction_type, $referance)
    {

        if(BusinessSetting::where('key','wallet_status')->first()->value != 1) return false;

        $user = User::find($user_id);
        $current_balance = $user->wallet_balance;

        $wallet_transaction = new WalletTransaction();
        $wallet_transaction->user_id = $user->id;
        $wallet_transaction->transaction_id = Str::random('30');
        $wallet_transaction->reference = $referance;
        $wallet_transaction->transaction_type = $transaction_type;

        $debit = 0.0;
        $credit = 0.0;

        if(in_array($transaction_type, ['add_fund_by_admin','add_fund','loyalty_point', 'referrer','add_fund_bonus']))
        {
            $credit = $amount;

            if($transaction_type == 'loyalty_point')
            {
                $credit = (int)($amount / BusinessSetting::where('key','loyalty_point_exchange_rate')->first()->value);
            }
        }
        else if($transaction_type == 'order_place')
        {
            $debit = $amount;
        }

        $wallet_transaction->credit = $credit;
        $wallet_transaction->debit = $debit;
        $wallet_transaction->balance = $current_balance + $credit - $debit;
        $wallet_transaction->created_at = now();
        $wallet_transaction->updated_at = now();
        $user->wallet_balance = $current_balance + $credit - $debit;

        try{
            DB::beginTransaction();
            $user->save();
            $wallet_transaction->save();
            DB::commit();
            if(in_array($transaction_type, ['loyalty_point','order_place','add_fund_by_admin', 'referrer', 'add_fund', 'add_fund_bonus'])) return $wallet_transaction;
            return true;
        }catch(\Exception $ex)
        {
            info($ex);
            DB::rollback();

            return false;
        }
        return false;
    }

    /**
     * Atomically credit or debit a customer wallet from an authorized admin action.
     * Reuses users.wallet_balance and wallet_transactions; never updates balance without a ledger row.
     *
     * @return array{ok: bool, message?: string, transaction?: WalletTransaction, replayed?: bool}
     */
    public static function adjust_wallet_by_admin(
        int $userId,
        $amount,
        string $type,
        string $reason,
        ?int $adminId = null,
        ?string $idempotencyKey = null
    ): array {
        $type = strtolower(trim($type));
        $reason = trim($reason);
        $idempotencyKey = $idempotencyKey !== null ? trim($idempotencyKey) : null;
        if ($idempotencyKey === '') {
            $idempotencyKey = null;
        }

        if (!in_array($type, ['credit', 'debit'], true)) {
            return ['ok' => false, 'message' => translate('invalid_wallet_adjustment_type')];
        }

        if (!is_numeric($amount)) {
            return ['ok' => false, 'message' => translate('The amount must be a number.')];
        }

        $amount = round((float) $amount, 3);
        if ($amount <= 0) {
            return ['ok' => false, 'message' => translate('The amount must be greater than 0')];
        }

        if ($reason === '') {
            return ['ok' => false, 'message' => translate('reason_note') . ' ' . translate('is_required')];
        }

        if (mb_strlen($reason) > 191) {
            return ['ok' => false, 'message' => translate('reason_note') . ' ' . translate('is_too_long')];
        }

        $walletStatus = BusinessSetting::where('key', 'wallet_status')->first();
        if (!$walletStatus || (int) $walletStatus->value !== 1) {
            return ['ok' => false, 'message' => translate('customer_wallet_status_is_disable')];
        }

        try {
            return DB::transaction(function () use ($userId, $amount, $type, $reason, $adminId, $idempotencyKey) {
                $user = User::query()->where('id', $userId)->lockForUpdate()->first();
                if (!$user) {
                    return ['ok' => false, 'message' => translate('Customer not found!')];
                }

                if ($idempotencyKey !== null) {
                    $existing = WalletTransaction::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        if ((int) $existing->user_id !== (int) $user->id) {
                            return ['ok' => false, 'message' => translate('failed_to_create_transaction')];
                        }

                        return [
                            'ok' => true,
                            'replayed' => true,
                            'transaction' => $existing,
                        ];
                    }
                }

                $currentBalance = round((float) $user->wallet_balance, 3);
                $credit = $type === 'credit' ? $amount : 0.0;
                $debit = $type === 'debit' ? $amount : 0.0;
                $newBalance = round($currentBalance + $credit - $debit, 3);

                if ($type === 'debit' && $newBalance < 0) {
                    return ['ok' => false, 'message' => translate('wallet_debit_exceeds_balance')];
                }

                $transactionType = $type === 'credit'
                    ? self::ADMIN_WALLET_CREDIT_TYPE
                    : self::ADMIN_WALLET_DEBIT_TYPE;

                $walletTransaction = new WalletTransaction();
                $walletTransaction->user_id = $user->id;
                $walletTransaction->admin_id = $adminId;
                $walletTransaction->transaction_id = 'ADMADJ_' . str_replace('-', '', (string) Str::uuid());
                $walletTransaction->reference = $reason;
                $walletTransaction->idempotency_key = $idempotencyKey;
                $walletTransaction->transaction_type = $transactionType;
                $walletTransaction->credit = $credit;
                $walletTransaction->debit = $debit;
                $walletTransaction->balance = $newBalance;
                $walletTransaction->admin_bonus = 0;
                $walletTransaction->created_at = now();
                $walletTransaction->updated_at = now();

                $user->wallet_balance = $newBalance;
                $user->save();
                $walletTransaction->save();

                return [
                    'ok' => true,
                    'replayed' => false,
                    'transaction' => $walletTransaction,
                ];
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey && self::isUniqueConstraintViolation($exception)) {
                $existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing && (int) $existing->user_id === $userId) {
                    return [
                        'ok' => true,
                        'replayed' => true,
                        'transaction' => $existing,
                    ];
                }
            }

            info($exception);

            return ['ok' => false, 'message' => translate('failed_to_create_transaction')];
        } catch (\Throwable $exception) {
            info($exception);

            return ['ok' => false, 'message' => translate('failed_to_create_transaction')];
        }
    }

    private static function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        if (isset($exception->errorInfo[0])) {
            $sqlState = (string) $exception->errorInfo[0];
        }

        return $sqlState === '23000' || $sqlState === '23505' || str_contains($exception->getMessage(), 'UNIQUE');
    }

    /**
     * @return int|false Points credited on success; 0 when loyalty disabled or zero credit; false on failure.
     */
    public static function create_loyalty_point_transaction($user_id, $referance, $amount, $transaction_type)
    {
        $loyaltyEnabled = (int) (Helpers::get_business_settings('loyalty_point_status') ?? 0) === 1;
        if (! $loyaltyEnabled) {
            return 0;
        }

        $credit = 0;
        $debit = 0;
        $user = User::find($user_id);

        if (!isset($user)){
            return false;
        }

        if ($transaction_type === 'order_place') {
            $existing = PointTransitions::query()
                ->where('user_id', $user_id)
                ->where('reference', (string) $referance)
                ->where('type', 'order_place')
                ->where('credit', '>', 0)
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                return (int) $existing->credit;
            }
        }

        $loyalty_point_transaction = new PointTransitions();
        $loyalty_point_transaction->user_id = $user->id;
        $loyalty_point_transaction->transaction_id = Str::random('30');
        $loyalty_point_transaction->reference = $referance;
        $loyalty_point_transaction->type = $transaction_type;

        if($transaction_type=='order_place')
        {
            $purchasePoint = (float) (Helpers::get_business_settings('loyalty_point_item_purchase_point') ?? 0);
            $credit = (int) ($amount * $purchasePoint / 100);
            if ($credit <= 0) {
                return 0;
            }
        }
        else if($transaction_type=='point_to_wallet')
        {
            $debit = $amount;
        }

        $current_balance = $user->point + $credit - $debit;
        $loyalty_point_transaction->amount = $current_balance;
        $loyalty_point_transaction->credit = $credit;
        $loyalty_point_transaction->debit = $debit;
        $loyalty_point_transaction->created_at = now();
        $loyalty_point_transaction->updated_at = now();
        $user->point = $current_balance;

        try{
            DB::beginTransaction();
            $user->save();
            $loyalty_point_transaction->save();
            DB::commit();

            return $credit;
        }catch(\Exception $ex)
        {
            info($ex);
            DB::rollback();

            return false;
        }
    }


    public static function referral_earning_wallet_transaction($user_id, $transaction_type, $referance, $referralEarningAmount)
    {
        $user = User::find($referance);
        $current_balance = $user->wallet_balance;

        $debit = 0.0;
        $credit = 0.0;
        $credit = $referralEarningAmount;

        $wallet_transaction = new WalletTransaction();
        $wallet_transaction->user_id = $user->id;
        $wallet_transaction->transaction_id = Str::random('30');
        $wallet_transaction->reference = $user_id;
        $wallet_transaction->transaction_type = $transaction_type;
        $wallet_transaction->credit = $credit;
        $wallet_transaction->debit = $debit;
        $wallet_transaction->balance = $current_balance + $credit;
        $wallet_transaction->created_at = now();
        $wallet_transaction->updated_at = now();

        $user->wallet_balance = $current_balance + $credit;

        $message = Helpers::order_status_update_message('referral_code_user_first_order_delivered_message');
        $restaurantName = Helpers::get_business_settings('restaurant_name');
        $customerName = ($user->f_name ?? '') . ' ' . ($user->l_name ?? '');
        $local = $user->language_code ?? 'en';

        if ($local != 'en'){
            $translatedMessage = BusinessSetting::with('translations')->where(['key' => 'referral_code_user_first_order_delivered_message'])->first();
            if (isset($translatedMessage->translations)){
                foreach ($translatedMessage->translations as $translation){
                    if ($local == $translation->locale){
                        $message = $translation->value;
                    }
                }
            }
        }

        $value = Helpers::text_variable_data_format(value:$message, user_name: $customerName, restaurant_name: $restaurantName);
        $customerFcmToken = $user->cm_firebase_token ?? null;


        if ($value && isset($customerFcmToken)) {
            $data = [
                'title' => translate('Referral code user First order delivered'),
                'description' => $value,
                'order_id' => '',
                'image' => '',
                'type' => 'referral',
            ];

            try {
                Helpers::send_push_notif_to_device($customerFcmToken, $data);
            }catch (\Exception $e) {
                dd($e);
            }
        }

        try{
            DB::beginTransaction();
            $user->save();
            $wallet_transaction->save();
            DB::commit();
        }catch(\Exception $ex)
        {
            info($ex);
            DB::rollback();

            return false;
        }
    }

    public static function loyalty_point_wallet_transfer_transaction($user_id, $point, $amount) {

        DB::transaction(function () use ($user_id, $point, $amount) {

            //Customer (loyalty_point update)
            $user = User::find($user_id);
            $current_wallet_balance = $user->wallet_balance;
            $current_point = $user->point;
            //dd($current_wallet_balance);

            $user->point -= $point;
            $user->wallet_balance += $amount;
            $user->save();

            WalletTransaction::create([
                'user_id' => $user_id,
                'transaction_id' => Str::random('30'),
                'reference' => null,
                'transaction_type' => 'loyalty_point_to_wallet',
                'debit' => 0,
                'credit' => $amount,
                'balance' => $current_wallet_balance + $amount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            PointTransitions::create([
                'user_id' => $user_id,
                'transaction_id' => Str::random('30'),
                'reference' => null,
                'type' => 'loyalty_point_to_wallet',
                'debit' => $point,
                'credit' => 0,
                'amount' => $current_point - $point,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public static function add_to_wallet($customer_id, float $amount)
    {
        $customer = User::find($customer_id);
        $fcm_token = $customer ? $customer->cm_firebase_token : '';
        $bonus_amount = self::add_to_wallet_bonus($customer_id, $amount);
        $reference = 'add-fund';

        $wallet_transaction = self::create_wallet_transaction($customer_id, $amount, 'add_fund', $reference);

        if ($wallet_transaction) {
            $local = $customer ? $customer->language_code : 'en';
            $restaurant_name = Helpers::get_business_settings('restaurant_name');
            $bonus_value = '';

            if ($bonus_amount > 0){
                $bonus_transaction = self::create_wallet_transaction($customer_id, $bonus_amount, 'add_fund_bonus', 'add-fund-bonus');

                if ($bonus_transaction){
                    $bonus_message = Helpers::order_status_update_message(ADD_WALLET_BONUS_MESSAGE);

                    if ($local != 'en'){
                        $translated_message = BusinessSetting::with('translations')->where(['key' => ADD_WALLET_BONUS_MESSAGE])->first();
                        if (isset($translated_message->translations)){
                            foreach ($translated_message->translations as $translation){
                                if ($local == $translation->locale){
                                    $bonus_message = $translation->value;
                                }
                            }
                        }
                    }
                    $bonus_value = Helpers::text_variable_data_format(value:$bonus_message, user_name: $customer->f_name. ' '. $customer->l_name, restaurant_name: $restaurant_name);
                }
            }

            $message = Helpers::order_status_update_message(ADD_WALLET_MESSAGE);

            if ($local != 'en'){
                $translated_message = BusinessSetting::with('translations')->where(['key' => ADD_WALLET_MESSAGE])->first();
                if (isset($translated_message->translations)){
                    foreach ($translated_message->translations as $translation){
                        if ($local == $translation->locale){
                            $message = $translation->value;
                        }
                    }
                }
            }
            $value = Helpers::text_variable_data_format(value:$message, user_name: $customer->f_name. ' '. $customer->l_name, restaurant_name: $restaurant_name);

            try {
                if ($value) {
                    $data = [
                        'title' => translate('wallet'),
                        'description' => $bonus_amount > 0 ? Helpers::set_symbol($amount) . ' ' . $value. ', '. Helpers::set_symbol($bonus_amount). ' '. $bonus_value : Helpers::set_symbol($amount) . ' ' . $value,
                        'order_id' => '',
                        'image' => '',
                        'type' => 'order_status',
                    ];
                    if (isset($fcm_token)) {
                        Helpers::send_push_notif_to_device($fcm_token, $data);
                    }
                }
                return true;
            } catch (\Exception $e) {
                Toastr::warning(translate('Push notification send failed for Customer!'));
            }
        }

        return false;

    }

    public static function add_to_wallet_bonus($customer_id, float $amount)
    {
        $bonuses = WalletBonus::active()
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->where('minimum_add_amount', '<=', $amount)
            ->get();

        $bonuses = $bonuses->where('minimum_add_amount', $bonuses->max('minimum_add_amount'));

        foreach ($bonuses as $key=>$item) {
            $item->applied_bonus_amount = $item->bonus_type == 'percentage' ? ($amount*$item->bonus_amount)/100 : $item->bonus_amount;

            //max bonus check
            if($item->bonus_type == 'percentage' && $item->applied_bonus_amount > $item->maximum_bonus_amount) {
                $item->applied_bonus_amount = $item->maximum_bonus_amount;
            }
        }

        return $bonuses->max('applied_bonus_amount') ?? 0;
    }

}
