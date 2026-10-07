<?php

namespace App\Services\Payments\Intent\Support;

use App\Exceptions\PalPlussException;
use App\Exceptions\PaystackException;
use App\Http\Controllers\PaystackController;
use App\Models\PaymentRequest;
use App\Services\PalPluss\PalPlussStkInitiator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Shared initiation response builders previously private on DigitalPaymentController.
 * Behaviour preserved byte-for-byte for existing clients.
 */
final class PaymentInitiationResponder
{
    public function paystackInlineCheckoutResponse(string $redirectLink, ?string $email = null): JsonResponse
    {
        $paymentId = $this->extractPaymentIdFromRedirectLink($redirectLink);
        if ($paymentId === null) {
            Log::warning('paystack.inline_payment_id_missing', ['redirect_link' => $redirectLink]);

            return response()->json([
                'errors' => [[
                    'code' => 'payment_session_error',
                    'message' => 'Could not resolve payment session for Paystack inline checkout.',
                ]],
            ], 500);
        }

        $paymentRequest = PaymentRequest::query()
            ->where('id', $paymentId)
            ->where('is_paid', 0)
            ->first();

        if ($paymentRequest === null) {
            return response()->json([
                'errors' => [[
                    'code' => 'payment_not_found',
                    'message' => 'Payment session not found or already completed.',
                ]],
            ], 404);
        }

        try {
            /** @var PaystackController $paystackController */
            $paystackController = app(PaystackController::class);
            $payload = $paystackController->initializePopupForPaymentRequest($paymentRequest, $email);

            return response()->json($payload, 200);
        } catch (PaystackException $exception) {
            Log::warning('paystack.inline_from_payment_mobile_failed', [
                'payment_id' => $paymentId,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'errors' => [[
                    'code' => 'paystack_initialize_failed',
                    'message' => $exception->getMessage(),
                ]],
            ], 502);
        } catch (\Throwable $exception) {
            Log::error('paystack.inline_from_payment_mobile_exception', [
                'payment_id' => $paymentId,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'errors' => [[
                    'code' => 'paystack_initialize_error',
                    'message' => 'Unable to initialize Paystack payment.',
                ]],
            ], 500);
        }
    }

    public function extractPaymentIdFromRedirectLink(string $redirectLink): ?string
    {
        if (preg_match('/payment_id=([0-9a-f-]{36})/i', $redirectLink, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public function palplussStkCheckoutResponse(string $redirectLink, string $phone): JsonResponse
    {
        $paymentId = $this->extractPaymentIdFromRedirectLink($redirectLink);
        if ($paymentId === null) {
            return response()->json([
                'errors' => [[
                    'code' => 'payment_session_error',
                    'message' => 'Could not resolve payment session for PalPluss STK checkout.',
                ]],
            ], 500);
        }

        $paymentRequest = PaymentRequest::query()
            ->where('id', $paymentId)
            ->where('is_paid', 0)
            ->first();

        if ($paymentRequest === null) {
            return response()->json([
                'errors' => [[
                    'code' => 'payment_not_found',
                    'message' => 'Payment session not found or already completed.',
                ]],
            ], 404);
        }

        try {
            $payload = app(PalPlussStkInitiator::class)->initiate($paymentRequest, $phone);

            return response()->json($payload, 200);
        } catch (PalPlussException $exception) {
            Log::warning('palpluss.stk_from_payment_mobile_failed', [
                'payment_id' => $paymentId,
                'error_code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]);

            $status = $exception->httpStatus && $exception->httpStatus >= 400 && $exception->httpStatus < 600
                ? $exception->httpStatus
                : 502;

            return response()->json([
                'errors' => [[
                    'code' => $exception->errorCode ?? 'palpluss_initiate_failed',
                    'message' => $exception->getMessage(),
                ]],
            ], $status);
        }
    }
}
