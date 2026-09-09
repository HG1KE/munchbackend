<?php

namespace App\Http\Controllers;

use App\Exceptions\PaystackException;
use App\Model\Order;
use App\Models\PaymentRequest;
use App\Services\Paystack\PaystackFulfillmentService;
use App\Services\Paystack\PaystackFulfillmentSource;
use App\Services\Paystack\PaystackGatewayStatus;
use App\Services\PaystackService;
use App\Traits\Processor;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PaystackController extends Controller
{
    use Processor;

    private PaymentRequest $payment;

    private PaystackService $paystack;

    public function __construct(PaymentRequest $payment, PaystackService $paystack)
    {
        $this->payment = $payment;
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

        if (! $this->paystack->isConfigured()) {
            Log::warning('paystack.pay_unconfigured', ['payment_id' => $request->input('payment_id')]);

            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, [
                ['error_code' => 'paystack', 'message' => 'Paystack is not configured.'],
            ]), 503);
        }

        $data = $this->findUnpaidPaymentRequest($request->input('payment_id'));
        if ($data === null) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }

        $payer = json_decode($data['payer_information']);
        $reference = $this->paystack->generateTransactionReference();

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
            if ($httpStatus === 200 && $this->orderPaymentVerifiedButNotPlaced($result)) {
                $placementCode = $result['placement_error']['code'] ?? 'order_not_placed';
                if ($placementCode === 'missing_place_order_draft') {
                    return response()->json($result, 200);
                }

                Log::critical('paystack.paid_without_order', [
                    'reference' => $reference,
                    'payment_id' => $result['payment_id'] ?? null,
                    'placement_status' => $result['placement_status'] ?? null,
                    'placement_error' => $result['placement_error'] ?? null,
                    'stage' => 'verify_inline_response',
                ]);

                return response()->json(array_merge($result, [
                    'errors' => [[
                        'code' => $placementCode,
                        'message' => $result['placement_error']['message'] ?? 'Payment received but order placement failed. Our team has been notified.',
                    ]],
                ]), 422);
            }

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

    public function redirectToGateway(Request $request): RedirectResponse|Redirector|Application
    {
        if (! $this->paystack->isConfigured()) {
            Log::warning('paystack.initialize_unconfigured');

            return redirect()->route('payment-fail');
        }

        $metadata = $this->normalizeMetadata($request->input('metadata'), $request->input('orderID'));
        $paymentId = $request->query('token') ?? $request->input('token');
        if (is_string($paymentId) && $paymentId !== '' && ! isset($metadata['payment_request_id'])) {
            $metadata['payment_request_id'] = $paymentId;
        }

        $reference = (string) $request->input('reference', $this->paystack->generateTransactionReference());

        try {
            $result = $this->paystack->initializeTransaction([
                'email' => $request->input('email', $this->paystack->getMerchantEmail() ?? 'customer@example.com'),
                'amount' => (int) $request->input('amount'),
                'reference' => $reference,
                'callback_url' => route('paystack.callback'),
                'currency' => $request->input('currency', 'NGN'),
                'metadata' => $metadata,
            ]);

            $authorizationUrl = $result['data']['authorization_url'] ?? null;
            if (! $authorizationUrl) {
                throw new PaystackException('Paystack did not return an authorization URL.');
            }

            if (is_string($paymentId) && $paymentId !== '') {
                $paymentRequest = $this->findUnpaidPaymentRequest($paymentId);
                if ($paymentRequest !== null) {
                    $paymentRequest->transaction_id = $reference;
                    $paymentRequest->payment_method = 'paystack';
                    $paymentRequest->save();
                }
            }

            return redirect()->away($authorizationUrl);
        } catch (PaystackException $exception) {
            Log::error('paystack.initialize_error', [
                'reference' => $reference,
                'message' => $exception->getMessage(),
            ]);

            return redirect()->route('payment-fail');
        }
    }

    public function handleGatewayCallback(Request $request)
    {
        $reference = $request->query('reference') ?? $request->query('trxref');

        if (! $reference) {
            Log::warning('paystack.callback_missing_reference');

            return redirect()->route('payment-fail');
        }

        if (! $this->paystack->isConfigured()) {
            Log::warning('paystack.callback_unconfigured', ['reference' => $reference]);

            return redirect()->route('payment-fail');
        }

        try {
            $result = $this->verifyAndCompletePayment((string) $reference, null, forRedirect: true);

            if (($result['status'] ?? '') === 'success' && isset($result['payment_request'])) {
                if ($this->orderPaymentVerifiedButNotPlaced($result)
                    && ($result['placement_error']['code'] ?? null) !== 'missing_place_order_draft') {
                    Log::critical('paystack.paid_without_order', [
                        'reference' => $reference,
                        'payment_id' => $result['payment_id'] ?? null,
                        'placement_status' => $result['placement_status'] ?? null,
                        'placement_error' => $result['placement_error'] ?? null,
                        'stage' => 'gateway_callback',
                    ]);

                    return $this->payment_response($result['payment_request'], 'fail');
                }

                return $this->payment_response($result['payment_request'], 'success');
            }

            $paymentData = $result['payment_request'] ?? null;
            if ($this->shouldInvokePaymentFailureHook($result, $paymentData)) {
                call_user_func($paymentData->failure_hook, $paymentData);
            }

            return $this->payment_response($paymentData, 'fail');
        } catch (PaystackException $exception) {
            Log::error('paystack.callback_verify_error', [
                'reference' => $reference,
                'message' => $exception->getMessage(),
            ]);

            return redirect()->route('payment-fail');
        }
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
    private function verifyAndCompletePayment(string $reference, ?string $paymentId = null, bool $forRedirect = false): array
    {
        $source = $forRedirect
            ? PaystackFulfillmentSource::GATEWAY_CALLBACK
            : PaystackFulfillmentSource::BROWSER_VERIFY;

        $result = app(PaystackFulfillmentService::class)->fulfillPaidCharge($reference, $source, $paymentId);

        if ($result['status'] === 'success') {
            if ($result['outcome'] === 'already_placed') {
                Log::info('paystack.verify_idempotent_success', [
                    'reference' => $reference,
                    'payment_id' => $result['payment_request']?->id,
                ]);
            } elseif (in_array($result['outcome'], ['order_placed', 'verified_not_placed', 'verified_non_order'], true)) {
                Log::info('paystack.verify_success', [
                    'reference' => $reference,
                    'attribute_id' => $result['payment_request']?->attribute_id,
                    'payment_id' => $result['payment_request']?->id,
                ]);
            }
        } elseif ($result['outcome'] === 'not_paid') {
            Log::warning('paystack.verify_not_successful', [
                'reference' => $reference,
                'gateway_status' => $result['payment_details']['data']['status'] ?? null,
            ]);
        }

        return $this->formatVerifyResult(
            $result['status'] === 'success' ? 'success' : 'fail',
            $result['payment_request'] ?? null,
            $reference,
            $forRedirect,
            $result
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatVerifyResult(
        string $status,
        ?PaymentRequest $paymentRequest,
        string $reference,
        bool $forRedirect = false,
        ?array $fulfillmentResult = null,
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

        $orderMeta = $fulfillmentResult !== null
            ? [
                'order_id' => $fulfillmentResult['order_id'] ?? null,
                'readable_order_id' => null,
                'order_display_id' => null,
                'order_placed' => (bool) ($fulfillmentResult['order_placed'] ?? false),
                'placement_status' => $fulfillmentResult['placement_status'] ?? null,
                'placement_error' => $fulfillmentResult['placement_error'] ?? null,
            ]
            : $this->resolveVerifiedOrderMeta($paymentRequest, $reference);

        if (($orderMeta['order_placed'] ?? false) && $orderMeta['order_id'] !== null) {
            $order = Order::query()->find($orderMeta['order_id']);
            $orderMeta['readable_order_id'] = $order?->readable_order_id;
            $orderMeta['order_display_id'] = $order !== null
                ? \App\CentralLogics\Helpers::order_display_id($order)
                : (string) $orderMeta['order_id'];
        }

        $payload = [
            'status' => $status,
            'reference' => $reference,
            'token' => base64_encode($tokenString),
            'callback_url' => $callbackUrl,
            'payment_request' => $forRedirect ? $paymentRequest : null,
            'attribute_id' => $paymentRequest->attribute_id ?? null,
            'payment_id' => $paymentRequest->id ?? null,
            'order_id' => $orderMeta['order_id'],
            'readable_order_id' => $orderMeta['readable_order_id'],
            'order_display_id' => $orderMeta['order_display_id'],
            'order_placed' => $orderMeta['order_placed'],
            'placement_status' => $orderMeta['placement_status'],
            'placement_error' => $orderMeta['placement_error'],
        ];

        if ($forRedirect) {
            $payload['payment_details'] = $fulfillmentResult['payment_details'] ?? null;
        }

        return $payload;
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

    /**
     * @return array<string, mixed>
     */
    private function normalizeMetadata(mixed $metadata, mixed $orderId = null): array
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?? [];
        }
        if (! is_array($metadata)) {
            $metadata = [];
        }

        if (! isset($metadata['attribute_id']) && $orderId !== null && $orderId !== '') {
            $metadata['attribute_id'] = $orderId;
        }

        return $metadata;
    }

    private function findUnpaidPaymentRequest(string $paymentId): ?PaymentRequest
    {
        return $this->payment::where(['id' => $paymentId])->where(['is_paid' => 0])->first();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function orderPaymentVerifiedButNotPlaced(array $result): bool
    {
        if (($result['status'] ?? '') !== 'success') {
            return false;
        }

        $paymentRequest = $result['payment_request'] ?? null;
        if ($paymentRequest instanceof PaymentRequest) {
            return $paymentRequest->attribute === 'order' && ! ($result['order_placed'] ?? false);
        }

        if (isset($result['payment_id'])) {
            $row = PaymentRequest::query()->find($result['payment_id']);

            return $row !== null
                && $row->attribute === 'order'
                && ! ($result['order_placed'] ?? false);
        }

        return ! ($result['order_placed'] ?? true);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function shouldInvokePaymentFailureHook(array $result, mixed $paymentRequest): bool
    {
        if (! $paymentRequest instanceof PaymentRequest) {
            return false;
        }

        if (! function_exists((string) $paymentRequest->failure_hook)) {
            return false;
        }

        $gatewayStatus = $result['payment_details']['data']['status'] ?? null;
        $resultStatus = ($result['status'] ?? '') === 'fail' ? 'failed' : (string) ($result['status'] ?? '');

        return PaystackGatewayStatus::shouldInvokeFailureHook($resultStatus, $gatewayStatus);
    }
}
