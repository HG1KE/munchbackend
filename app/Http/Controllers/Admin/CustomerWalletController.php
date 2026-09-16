<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\CentralLogics\CustomerLogic;
use App\Http\Controllers\Controller;
use App\Model\BusinessSetting;
use App\Model\WalletTransaction;
use App\User;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Validator;

class CustomerWalletController extends Controller
{
    public function __construct(
        private WalletTransaction $walletTransaction,
        private User              $user,
    )
    {}

    /**
     * @return Renderable|RedirectResponse
     */
    public function addFundView(): Renderable|RedirectResponse
    {
        if (BusinessSetting::where('key', 'wallet_status')->first()->value != 1) {
            Toastr::error(translate('customer_wallet_status_is_disable'));
            return back();
        }

        return view('admin-views.customer.wallet.add-fund');
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function addFund(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'exists:users,id',
            'amount' => 'numeric|min:0|not_in:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }

        $walletTransaction = CustomerLogic::create_wallet_transaction($request->customer_id, $request->amount, 'add_fund_by_admin', $request->referance);

        if ($walletTransaction) {
            return response()->json([], 200);
        }

        return response()->json(['errors' => [
            'message' => translate('failed_to_create_transaction')
        ]], 200);
    }

    /**
     * @param Request $request
     * @return Renderable
     */
    public function report(Request $request): Renderable
    {
        $data = $this->walletTransaction
            ->selectRaw('sum(credit) as total_credit, sum(debit) as total_debit')
            ->when(($request->from && $request->to), function ($query) use ($request) {
                $query->whereBetween('created_at', [$request->from . ' 00:00:00', $request->to . ' 23:59:59']);
            })
            ->when($request->transaction_type, function ($query) use ($request) {
                $query->where('transaction_type', $request->transaction_type);
            })
            ->when($request->customer_id, function ($query) use ($request) {
                $query->where('user_id', $request->customer_id);
            })
            ->get();

        $transactions = $this->walletTransaction
            ->when(($request->from && $request->to), function ($query) use ($request) {
                $query->whereBetween('created_at', [$request->from . ' 00:00:00', $request->to . ' 23:59:59']);
            })
            ->when($request->transaction_type, function ($query) use ($request) {
                $query->where('transaction_type', $request->transaction_type);
            })
            ->when($request->customer_id, function ($query) use ($request) {
                $query->where('user_id', $request->customer_id);
            })
            ->latest()
            ->paginate(Helpers::getPagination());

        return view('admin-views.customer.wallet.report', compact('data', 'transactions'));
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function getCustomers(Request $request): JsonResponse
    {
        $key = explode(' ', $request['q']);
        $data = $this->user
            ->where('user_type', null)
            ->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('f_name', 'like', "%{$value}%")
                        ->orWhere('l_name', 'like', "%{$value}%")
                        ->orWhere('phone', 'like', "%{$value}%");
                }
            })
            ->limit(8)
            ->get([DB::raw('id, CONCAT(f_name, " ", l_name, " (", phone ,")") as text')]);
        if ($request->all) $data[] = (object)['id' => false, 'text' => translate('all')];

        return response()->json($data);
    }

    /**
     * @param Request $request
     * @param int|string $customer_id
     * @return JsonResponse
     */
    public function adjust(Request $request, $customer_id): JsonResponse
    {
        $request->request->remove('resulting_balance');
        $request->request->remove('customer_id');

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:credit,debit',
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'reason' => 'required|string|max:191',
            'idempotency_key' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 200);
        }

        $customer = $this->user->where('user_type', null)->find($customer_id);
        if (!$customer) {
            return response()->json(['errors' => [
                ['code' => 'customer_id', 'message' => translate('Customer not found!')],
            ]], 200);
        }

        $result = CustomerLogic::adjust_wallet_by_admin(
            (int) $customer->id,
            $request->input('amount'),
            (string) $request->input('type'),
            (string) $request->input('reason'),
            auth('admin')->id() ? (int) auth('admin')->id() : null,
            (string) $request->input('idempotency_key'),
        );

        if (empty($result['ok']) || empty($result['transaction'])) {
            return response()->json(['errors' => [
                ['code' => 'wallet', 'message' => $result['message'] ?? translate('failed_to_create_transaction')],
            ]], 200);
        }

        $transaction = $result['transaction'];
        $balance = (float) $customer->fresh()->wallet_balance;

        return response()->json([
            'message' => translate('wallet_adjusted_successfully'),
            'replayed' => (bool) ($result['replayed'] ?? false),
            'wallet_balance' => $balance,
            'wallet_balance_formatted' => Helpers::set_symbol($balance),
            'transaction' => [
                'transaction_id' => $transaction->transaction_id,
                'transaction_type' => $transaction->transaction_type,
                'credit' => (float) $transaction->credit,
                'debit' => (float) $transaction->debit,
                'balance' => (float) $transaction->balance,
                'reference' => $transaction->reference,
                'created_at' => $transaction->created_at,
            ],
        ], 200);
    }
}
