<?php

namespace App\Http\Controllers;

use App\Exceptions\PaystackException;
use App\Model\Order;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PaystackService;
use App\Traits\Processor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;
use Unicodeveloper\Paystack\Facades\Paystack;

class PaystackController extends Controller
{
    use Processor;

    private PaymentRequest $payment;

    private $user;

    private PaystackService $paystack;

    public function __construct(PaymentRequest $payment, User $user, PaystackService $paystack)
    {
        $config = $this->payment_config('paystack', 'payment_config');
        $values = false;
        if (!is_null($config) && $config->mode == 'live') {
            $values = json_decode($config->live_values);
        } elseif (!is_null($config) && $config->mode == 'test') {
            $values = json_decode($config->test_values);
        }

        if ($values) {
            $config = array(
                'publicKey' => env('PAYSTACK_PUBLIC_KEY', $values->public_key),
                'secretKey' => env('PAYSTACK_SECRET_KEY', $values->secret_key),
                'paymentUrl' => env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'),
                'merchantEmail' => env('MERCHANT_EMAIL', $values->merchant_email),
            );
            Config::set('paystack', $config);
        }

        $this->payment = $payment;
        $this->user = $user;
        $this->paystack = $paystack;
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|uuid'
        ]);

        if ($validator->fails()) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, $this->error_processor($validator)), 400);
        }

        $data = $this->payment::where(['id' => $request['payment_id']])->where(['is_paid' => 0])->first();
        if (!isset($data)) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }

        $payer = json_decode($data['payer_information']);

        $reference = Paystack::genTranxRef();

        return view('payment-gateway.paystack', compact('data', 'payer', 'reference'));
    }

    /**
     * API: initialize Paystack Inline Popup for an existing payment_requests row.
     */
    public function initializeInline(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|uuid',
            'email' => 'nullable|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $this->error_processor($validator),
            ], 422);
        }

        if (! $this->paystack->isConfigured()) {
            Log::warning('paystack.inline_initialize_unconfigured', [
                'payment_id' => $request->input('payment_id'),
            ]);

            return response()->json([
                'errors' => [[
                    'code' => 'paystack_not_configured',
                    'message' => 'Paystack is not configured. Enable it in admin payment settings.',
                ]],
            ], 503);
        }

        $paymentRequest = $this->findUnpaidPaymentRequest($request->input('payment_id'));
        if ($paymentRequest === null) {
            return response()->json([
                'errors' => [[
                    'code' => 'payment_not_found',
                    'message' => 'Payment session not found or already completed.',
                ]],
            ], 404);
        }

        try {
            $payload = $this->initializePopupForPaymentRequest(
                $paymentRequest,
                $request->input('email')
            );

            Log::info('paystack.inline_initialize_ok', [
                'payment_id' => $paymentRequest->id,
                'reference' => $payload['paystack']['reference'] ?? null,
            ]);

            return response()->json($payload, 200);
        } catch (PaystackException $exception) {
            Log::warning('paystack.inline_initialize_failed', [
                'payment_id' => $paymentRequest->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'errors' => [[
                    'code' => 'paystack_initialize_failed',
                    'message' => $exception->getMessage(),
                ]],
            ], 502);
        } catch (Throwable $exception) {
            Log::error('paystack.inline_initialize_exception', [
                'payment_id' => $paymentRequest->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'errors' => [[
                    'code' => 'paystack_initialize_error',
                    'message' => 'Unable to initialize Paystack payment. Please try again.',
                ]],
            ], 500);
        }
    }

    /**
     * API: verify Paystack transaction after inline popup success (idempotent).
     */
    public function verifyInline(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reference' => 'required|string|max:191',
            'payment_id' => 'nullable|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $this->error_processor($validator),
            ], 422);
        }

        $reference = (string) $request->input('reference');

        if (! $this->paystack->isConfigured()) {
            Log::warning('paystack.inline_verify_unconfigured', ['reference' => $reference]);

            return response()->json([
                'errors' => [[
                    'code' => 'paystack_not_configured',
                    'message' => 'Paystack is not configured.',
                ]],
            ], 503);
        }

        try {
            $result = $this->verifyAndCompletePayment(
                $reference,
                $request->input('payment_id')
            );

            $httpStatus = ($result['status'] ?? '') === 'success' ? 200 : 402;

            return response()->json($result, $httpStatus);
        } catch (PaystackException $exception) {
            Log::warning('paystack.inline_verify_failed', [
                'reference' => $reference,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'errors' => [[
                    'code' => 'paystack_verify_failed',
                    'message' => $exception->getMessage(),
                ]],
            ], 502);
        } catch (Throwable $exception) {
            Log::error('paystack.inline_verify_exception', [
                'reference' => $reference,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'errors' => [[
                    'code' => 'paystack_verify_error',
                    'message' => 'Unable to verify Paystack payment.',
                ]],
            ], 500);
        }
    }

    public function redirectToGateway(Request $request)
    {
        return Paystack::getAuthorizationUrl()->redirectNow();
    }

    public function handleGatewayCallback(Request $request)
    {
        $paymentDetails = Paystack::getPaymentData();

        if ($paymentDetails['status'] == true) {
            $attributeId = $paymentDetails['data']['metadata']['attribute_id'] ?? null;
            $existing = $attributeId !== null
                ? $this->payment::where(['attribute_id' => $attributeId])->first()
                : null;

            if ($existing && (int) $existing->is_paid === 1) {
                return $this->payment_response($existing, 'success');
            }

            $this->payment::where(['attribute_id' => $paymentDetails['data']['metadata']['attribute_id']])->update([
                'payment_method' => 'paystack',
                'is_paid' => 1,
                'transaction_id' => $request['trxref'],
            ]);
            $data = $this->payment::where(['attribute_id' => $paymentDetails['data']['metadata']['attribute_id']])->first();
            if (isset($data) && function_exists($data->success_hook)) {
                call_user_func($data->success_hook, $data);
            }
            return $this->payment_response($data, 'success');
        }

        $payment_data = $this->payment::where(['attribute_id' => $paymentDetails['data']['metadata']['attribute_id']])->first();
        if (isset($payment_data) && function_exists($payment_data->failure_hook)) {
            call_user_func($payment_data->failure_hook, $payment_data);
        }
        return $this->payment_response($payment_data, 'fail');
    }

    /**
     * @return array<string, mixed>
     */
    public function initializePopupForPaymentRequest(PaymentRequest $paymentRequest, ?string $emailOverride = null): array
    {
        $payer = json_decode($paymentRequest->payer_information ?? '{}');
        $email = $emailOverride
            ?? (is_object($payer) ? ($payer->email ?? null) : null)
            ?? $this->paystack->getMerchantEmail()
            ?? 'customer@example.com';

        $reference = $this->paystack->generateTransactionReference();
        $amountMinor = (int) round((float) $paymentRequest->payment_amount * 100);
        $currency = strtoupper((string) ($paymentRequest->currency_code ?? 'NGN'));

        $metadata = [
            'attribute_id' => $paymentRequest->attribute_id,
            'payment_request_id' => (string) $paymentRequest->id,
            'attribute' => $paymentRequest->attribute,
        ];

        $result = $this->paystack->initializeTransaction([
            'email' => $email,
            'amount' => $amountMinor,
            'reference' => $reference,
            'callback_url' => route('paystack.callback'),
            'currency' => $currency,
            'metadata' => $metadata,
        ]);

        $inline = $this->paystack->buildInlineCheckoutPayload($result);

        $paymentRequest->transaction_id = $reference;
        $paymentRequest->payment_method = 'paystack';
        $paymentRequest->save();

        return [
            'checkout_mode' => 'paystack_inline',
            'payment_id' => (string) $paymentRequest->id,
            'paystack' => array_merge($inline, [
                'public_key' => $this->paystack->getPublicKey(),
                'email' => $email,
                'amount' => $amountMinor,
                'currency' => $currency,
                'metadata' => $metadata,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyAndCompletePayment(string $reference, ?string $paymentId = null): array
    {
        $alreadyPaid = $this->resolvePaymentRequest($paymentId, $reference, null);
        if (
            $alreadyPaid !== null
            && (int) $alreadyPaid->is_paid === 1
            && (string) $alreadyPaid->transaction_id === $reference
        ) {
            Log::info('paystack.verify_idempotent_success', [
                'reference' => $reference,
                'payment_id' => $alreadyPaid->id,
            ]);

            return $this->formatVerifyResult('success', $alreadyPaid, $reference);
        }

        $paymentDetails = $this->paystack->verifyTransaction($reference);
        $gatewaySuccess = ($paymentDetails['status'] ?? false) === true
            && (string) ($paymentDetails['data']['status'] ?? '') === 'success';

        $paymentRequest = $this->resolvePaymentRequest($paymentId, $reference, $paymentDetails);

        if (! $gatewaySuccess) {
            Log::warning('paystack.verify_not_successful', [
                'reference' => $reference,
                'gateway_status' => $paymentDetails['data']['status'] ?? null,
            ]);

            return $this->formatVerifyResult('fail', $paymentRequest, $reference);
        }

        if ($paymentRequest === null) {
            return $this->formatVerifyResult('fail', null, $reference);
        }

        if ((int) $paymentRequest->is_paid !== 1) {
            $this->payment::where(['id' => $paymentRequest->id])->update([
                'payment_method' => 'paystack',
                'is_paid' => 1,
                'transaction_id' => $reference,
            ]);
            $paymentRequest = $this->payment::where(['id' => $paymentRequest->id])->first() ?? $paymentRequest;

            if (isset($paymentRequest) && function_exists($paymentRequest->success_hook)) {
                call_user_func($paymentRequest->success_hook, $paymentRequest);
            }

            Log::info('paystack.verify_success', [
                'reference' => $reference,
                'attribute_id' => $paymentRequest->attribute_id ?? null,
                'payment_id' => $paymentRequest->id ?? null,
            ]);
        }

        return $this->formatVerifyResult('success', $paymentRequest, $reference);
    }

    /**
     * @param  array<string, mixed>|null  $paymentDetails
     */
    private function resolvePaymentRequest(?string $paymentId, string $reference, ?array $paymentDetails): ?PaymentRequest
    {
        if ($paymentId !== null && $paymentId !== '') {
            $byId = $this->payment::where(['id' => $paymentId])->first();
            if ($byId !== null) {
                return $byId;
            }
        }

        $byReference = $this->payment::where(['transaction_id' => $reference])
            ->orderByDesc('created_at')
            ->first();
        if ($byReference !== null) {
            return $byReference;
        }

        if ($paymentDetails === null) {
            return null;
        }

        $metadata = $paymentDetails['data']['metadata'] ?? [];
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?? [];
        }
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $resolvedId = $metadata['payment_request_id'] ?? null;
        if (is_string($resolvedId) && $resolvedId !== '') {
            $byMetaId = $this->payment::where(['id' => $resolvedId])->first();
            if ($byMetaId !== null) {
                return $byMetaId;
            }
        }

        $attributeId = $metadata['attribute_id'] ?? null;
        if ($attributeId !== null && $attributeId !== '') {
            return $this->payment::where(['attribute_id' => $attributeId])->first();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatVerifyResult(
        string $status,
        ?PaymentRequest $paymentRequest,
        string $reference,
    ): array {
        $tokenString = $paymentRequest
            ? 'payment_method=paystack&&attribute_id=' . $paymentRequest->attribute_id . '&&transaction_reference=' . $reference
            : 'payment_method=paystack&&transaction_reference=' . $reference;

        $callbackUrl = null;
        if ($paymentRequest && $paymentRequest->external_redirect_link) {
            $flag = $status === 'success' ? 'success' : 'fail';
            $callbackUrl = $paymentRequest->external_redirect_link
                . '?flag=' . $flag
                . '&&token=' . base64_encode($tokenString);
        }

        $orderMeta = $this->resolveVerifiedOrderMeta($paymentRequest, $reference);

        return [
            'status' => $status,
            'reference' => $reference,
            'token' => base64_encode($tokenString),
            'callback_url' => $callbackUrl,
            'payment_request' => null,
            'attribute_id' => $paymentRequest->attribute_id ?? null,
            'payment_id' => $paymentRequest->id ?? null,
            'order_id' => $orderMeta['order_id'],
            'readable_order_id' => $orderMeta['readable_order_id'],
            'order_display_id' => $orderMeta['order_display_id'],
            'order_placed' => $orderMeta['order_placed'],
            'placement_status' => $orderMeta['placement_status'],
            'placement_error' => $orderMeta['placement_error'],
        ];
    }

    /**
     * @return array{
     *     order_id: ?int,
     *     readable_order_id: ?string,
     *     order_display_id: ?string,
     *     order_placed: bool,
     *     placement_status: ?string,
     *     placement_error: ?array<string, mixed>
     * }
     */
    private function resolveVerifiedOrderMeta(?PaymentRequest $paymentRequest, string $reference): array
    {
        $empty = [
            'order_id' => null,
            'readable_order_id' => null,
            'order_display_id' => null,
            'order_placed' => false,
            'placement_status' => null,
            'placement_error' => null,
        ];

        if ($paymentRequest === null || $reference === '') {
            return $empty;
        }

        try {
            $order = Order::query()
                ->where('transaction_reference', $reference)
                ->orderByDesc('id')
                ->first();
        } catch (Throwable) {
            return $empty;
        }

        if ($order === null) {
            return $empty;
        }

        return [
            'order_id' => $order->id,
            'readable_order_id' => $order->readable_order_id ?? null,
            'order_display_id' => \App\CentralLogics\Helpers::order_display_id($order),
            'order_placed' => true,
            'placement_status' => 'placed',
            'placement_error' => null,
        ];
    }

    private function findUnpaidPaymentRequest(string $paymentId): ?PaymentRequest
    {
        return $this->payment::where(['id' => $paymentId])->where(['is_paid' => 0])->first();
    }
}
