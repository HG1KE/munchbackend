<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PalPlussException;
use App\Http\Controllers\Controller;
use App\Models\PalPlussPaymentAttempt;
use App\Models\PaymentRequest;
use App\Services\PalPluss\PalPlussFulfillmentService;
use App\Services\PalPluss\PalPlussStkInitiator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PalPlussController extends Controller
{
    public function __construct(
        private readonly PalPlussStkInitiator $stkInitiator,
        private readonly PalPlussFulfillmentService $fulfillment,
    ) {
    }

    public function initiate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|uuid',
            'phone' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()], 422);
        }

        $paymentRequest = PaymentRequest::query()
            ->where('id', $request->input('payment_id'))
            ->where('is_paid', 0)
            ->first();

        if ($paymentRequest === null) {
            return response()->json([
                'errors' => [['code' => 'payment_not_found', 'message' => 'Payment session not found or already completed.']],
            ], 404);
        }

        try {
            $payload = $this->stkInitiator->initiate($paymentRequest, (string) $request->input('phone'));

            return response()->json($payload, 200);
        } catch (PalPlussException $e) {
            Log::warning('palpluss.initiate_failed', [
                'payment_id' => $paymentRequest->id,
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);

            $status = $e->httpStatus && $e->httpStatus >= 400 && $e->httpStatus < 600
                ? $e->httpStatus
                : 502;

            return response()->json([
                'errors' => [[
                    'code' => $e->errorCode ?? 'palpluss_initiate_failed',
                    'message' => $e->getMessage(),
                ]],
            ], $status);
        }
    }

    public function verify(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'nullable|uuid',
            'transaction_id' => 'nullable|string|max:64',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()], 422);
        }

        $transactionId = trim((string) $request->input('transaction_id', ''));
        $paymentId = $request->input('payment_id');

        if ($transactionId === '' && is_string($paymentId)) {
            $attempt = PalPlussPaymentAttempt::query()
                ->where('payment_request_id', $paymentId)
                ->orderByDesc('id')
                ->first();
            $transactionId = (string) ($attempt?->transaction_id ?? '');
        }

        if ($transactionId === '') {
            return response()->json([
                'errors' => [['code' => 'transaction_id', 'message' => 'transaction_id or payment_id with an STK attempt is required.']],
            ], 422);
        }

        $result = $this->fulfillment->fulfill(
            $transactionId,
            PalPlussFulfillmentService::SOURCE_BROWSER_VERIFY,
            is_string($paymentId) ? $paymentId : null
        );

        $paymentRequest = $result['payment_request'];
        $orderId = $result['order_id'];

        return response()->json([
            'status' => $result['status'],
            'outcome' => $result['outcome'],
            'transaction_id' => $result['transaction_id'],
            'payment_id' => $paymentRequest?->id,
            'order_id' => $orderId,
            'order_placed' => $result['order_placed'],
            'placement_status' => $result['placement_status'],
            'message' => $result['message'],
            'order_display_id' => $orderId,
            'readable_order_id' => $orderId,
        ], ($result['outcome'] === 'failed' && $result['status'] === 'failed') ? 422 : 200);
    }

    public function status(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()], 422);
        }

        $attempt = PalPlussPaymentAttempt::query()
            ->where('payment_request_id', $request->input('payment_id'))
            ->orderByDesc('id')
            ->first();

        if ($attempt === null) {
            return response()->json([
                'errors' => [['code' => 'not_found', 'message' => 'No PalPluss STK attempt for this payment.']],
            ], 404);
        }

        return response()->json([
            'payment_id' => $attempt->payment_request_id,
            'transaction_id' => $attempt->transaction_id,
            'status' => $attempt->status,
            'amount' => $attempt->amount,
            'order_id' => $attempt->placed_order_id,
            'mpesa_receipt' => $attempt->mpesa_receipt,
        ]);
    }
}
