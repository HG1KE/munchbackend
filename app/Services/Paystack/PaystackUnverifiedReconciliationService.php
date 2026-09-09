<?php

namespace App\Services\Paystack;

use App\Models\PaymentRequest;
use App\Services\PaystackService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled safety net: verify successful Paystack charges and ensure orders exist.
 */
class PaystackUnverifiedReconciliationService
{
    public function __construct(
        private readonly PaystackFulfillmentService $fulfillment,
        private readonly PaystackOrderProtectionService $protection,
        private readonly PaystackService $paystack,
    ) {}

    /**
     * @return array{
     *     verified: int,
     *     orders_placed: int,
     *     order_failed: int,
     *     verify_failed: int,
     *     not_paid: int,
     *     skipped: int,
     *     scanned: int,
     *     details: list<array<string, mixed>>
     * }
     */
    public function reconcile(int $minAgeMinutes = 5, int $limit = 50, ?string $referenceFilter = null): array
    {
        if (! Schema::hasTable('payment_requests') || ! $this->paystack->isConfigured()) {
            return $this->emptySummary();
        }

        $candidates = $this->safetyNetCandidatesQuery($minAgeMinutes, $limit, $referenceFilter)->get();
        $verified = 0;
        $ordersPlaced = 0;
        $orderFailed = 0;
        $verifyFailed = 0;
        $notPaid = 0;
        $skipped = 0;
        $details = [];

        foreach ($candidates as $paymentRequest) {
            $detail = $this->reconcilePaymentRequest($paymentRequest);
            $details[] = $detail;

            $result = (string) ($detail['result'] ?? '');
            if ($result === 'order_placed' || $result === 'recovered') {
                $verified++;
                $ordersPlaced++;
            } elseif ($result === 'order_failed') {
                $verified++;
                $orderFailed++;
            } elseif ($result === 'verify_failed') {
                $verifyFailed++;
            } elseif ($result === 'not_paid') {
                $notPaid++;
            } else {
                $skipped++;
            }
        }

        return [
            'verified' => $verified,
            'orders_placed' => $ordersPlaced,
            'order_failed' => $orderFailed,
            'verify_failed' => $verifyFailed,
            'not_paid' => $notPaid,
            'skipped' => $skipped,
            'scanned' => $candidates->count(),
            'details' => $details,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reconcilePaymentRequest(PaymentRequest $paymentRequest): array
    {
        $reference = trim((string) ($paymentRequest->transaction_id ?? ''));
        $baseLog = [
            'payment_request_id' => $paymentRequest->id,
            'transaction_reference' => $reference,
            'payer_id' => $paymentRequest->payer_id,
            'payment_amount' => (float) $paymentRequest->payment_amount,
            'is_paid' => (int) $paymentRequest->is_paid,
            'placed_order_id' => $paymentRequest->placed_order_id,
        ];

        Log::info('paystack.reconcile_candidate', $baseLog);

        if ($reference === '') {
            Log::info('paystack.reconciliation_skipped', array_merge($baseLog, [
                'reason' => 'missing_reference',
            ]));

            return array_merge($baseLog, ['result' => 'skipped', 'reason' => 'missing_reference']);
        }

        if ($paymentRequest->attribute !== 'order') {
            Log::info('paystack.reconciliation_skipped', array_merge($baseLog, [
                'reason' => 'not_order_attribute',
            ]));

            return array_merge($baseLog, ['result' => 'skipped', 'reason' => 'not_order_attribute']);
        }

        if ($this->shouldSkipCandidate($paymentRequest, $reference)) {
            Log::info('paystack.reconciliation_skipped', array_merge($baseLog, [
                'reason' => 'already_complete_or_ineligible',
            ]));

            return array_merge($baseLog, [
                'result' => 'skipped',
                'reason' => 'already_complete_or_ineligible',
            ]);
        }

        $result = $this->fulfillment->fulfillPaidCharge(
            $reference,
            PaystackFulfillmentSource::RECONCILIATION,
            (string) $paymentRequest->id,
        );

        return $this->mapFulfillmentToReconcileDetail($baseLog, $result, $paymentRequest);
    }

    /**
     * @return array{
     *     count: int,
     *     total_payment_value: float,
     *     oldest_at: ?string,
     *     references: list<array<string, mixed>>
     * }
     */
    public function auditUnverified(int $minAgeMinutes = 0, int $limit = 500): array
    {
        if (! Schema::hasTable('payment_requests')) {
            return ['count' => 0, 'total_payment_value' => 0.0, 'oldest_at' => null, 'references' => []];
        }

        $query = $this->safetyNetCandidatesQuery($minAgeMinutes, $limit);
        $rows = (clone $query)->reorder()->orderBy('created_at')->get();

        return [
            'count' => $rows->count(),
            'total_payment_value' => round((float) $rows->sum('payment_amount'), 2),
            'oldest_at' => $rows->first()?->created_at?->toIso8601String(),
            'references' => $rows->map(fn (PaymentRequest $row) => [
                'payment_request_id' => $row->id,
                'transaction_reference' => $row->transaction_id,
                'payer_id' => $row->payer_id,
                'payment_amount' => (float) $row->payment_amount,
                'is_paid' => (int) $row->is_paid,
                'placed_order_id' => $row->placed_order_id,
                'created_at' => $row->created_at?->toIso8601String(),
                'placement_status' => $row->placement_status,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function investigateReference(string $reference): array
    {
        $paymentRequest = PaymentRequest::query()
            ->where('transaction_id', $reference)
            ->orderByDesc('created_at')
            ->first();

        $paystack = $this->paystack->inspectTransaction($reference);

        $existingOrderId = $paymentRequest
            ? ($paymentRequest->placed_order_id ?: $this->protection->findExistingOrderId($reference))
            : null;

        $eligible = $paymentRequest !== null
            && $paymentRequest->attribute === 'order'
            && $existingOrderId === null
            && ! $this->shouldSkipCandidate($paymentRequest, $reference)
            && ($paystack['successful'] ?? false) === true
            && $paymentRequest->created_at !== null
            && $paymentRequest->created_at->lte(now()->subMinutes(5));

        return [
            'reference' => $reference,
            'payment_found' => $paymentRequest !== null,
            'payment_request' => $paymentRequest ? [
                'id' => $paymentRequest->id,
                'is_paid' => (int) $paymentRequest->is_paid,
                'payment_amount' => (float) $paymentRequest->payment_amount,
                'placement_status' => $paymentRequest->placement_status,
                'placed_order_id' => $paymentRequest->placed_order_id,
                'payer_id' => $paymentRequest->payer_id,
                'created_at' => $paymentRequest->created_at?->toIso8601String(),
            ] : null,
            'paystack' => $paystack,
            'existing_order_id' => $existingOrderId,
            'reconciliation_eligible' => $eligible,
            'recovery_recommendation' => $this->recoveryRecommendation($paymentRequest, $paystack, $existingOrderId),
        ];
    }

    public function safetyNetCandidatesQuery(int $minAgeMinutes = 5, int $limit = 50, ?string $referenceFilter = null): Builder
    {
        $hasReferenceFilter = $referenceFilter !== null && $referenceFilter !== '';
        $cooldownMinutes = max(
            1,
            (int) config('paystack.reconcile_in_progress_cooldown_minutes', 60),
            (int) config('paystack.reconcile_verify_failed_cooldown_minutes', 15),
        );

        $query = PaymentRequest::query()
            ->where('payment_method', 'paystack')
            ->where('attribute', 'order')
            ->whereNotNull('transaction_id')
            ->where('transaction_id', '!=', '')
            ->where(function (Builder $builder) {
                $builder->where('is_paid', 0)
                    ->orWhereNull('placed_order_id');
            })
            ->where(function (Builder $builder) {
                $builder->whereNull('placement_status')
                    ->orWhereNotIn('placement_status', PaymentRequest::excludedRecoveryPlacementStatuses());
            })
            ->where('created_at', '<=', now()->subMinutes(max(1, $minAgeMinutes)));

        // Soft cooldown for unpaid sessions only (batch mode). Targeted --reference
        // retries bypass cooldown so operators can force an immediate re-check.
        if (! $hasReferenceFilter) {
            $query->where(function (Builder $builder) use ($cooldownMinutes) {
                $builder->where('is_paid', 1)
                    ->orWhereNull('placement_attempted_at')
                    ->orWhere('placement_attempted_at', '<=', now()->subMinutes($cooldownMinutes));
            });
        }

        if ((bool) config('paystack.reconcile_newest_first', true)) {
            $query->orderByDesc('created_at');
        } else {
            $query->orderBy('created_at');
        }

        $query->limit($limit);

        if ($hasReferenceFilter) {
            $query->where('transaction_id', $referenceFilter);
        }

        return $query;
    }

    /** @deprecated Use safetyNetCandidatesQuery() */
    public function unverifiedCandidatesQuery(int $minAgeMinutes = 5, int $limit = 50, ?string $referenceFilter = null): Builder
    {
        return $this->safetyNetCandidatesQuery($minAgeMinutes, $limit, $referenceFilter);
    }

    /**
     * @return Collection<int, PaymentRequest>
     */
    public function unverifiedCandidates(int $minAgeMinutes = 5, int $limit = 50): Collection
    {
        return $this->safetyNetCandidatesQuery($minAgeMinutes, $limit)->get();
    }

    private function shouldSkipCandidate(PaymentRequest $paymentRequest, string $reference): bool
    {
        if ($paymentRequest->isInconsistentPlacedState()) {
            return true;
        }

        if ($paymentRequest->hasPlacedOrder()) {
            return true;
        }

        $existingOrderId = $this->protection->findExistingOrderId($reference);
        if ($existingOrderId !== null) {
            return true;
        }

        if ((int) $paymentRequest->is_paid === 1 && ! $paymentRequest->isEligibleForRecovery()) {
            return in_array((string) ($paymentRequest->placement_status ?? ''), PaymentRequest::excludedRecoveryPlacementStatuses(), true);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $baseLog
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function mapFulfillmentToReconcileDetail(
        array $baseLog,
        array $result,
        PaymentRequest $paymentRequest,
    ): array {
        $outcome = (string) ($result['outcome'] ?? 'failed');

        if ($outcome === 'already_placed') {
            Log::info('paystack.reconciliation_skipped', array_merge($baseLog, [
                'reason' => 'already_placed',
                'order_id' => $result['order_id'] ?? null,
            ]));

            return array_merge($baseLog, [
                'result' => 'skipped',
                'reason' => 'already_placed',
                'order_id' => $result['order_id'] ?? null,
            ]);
        }

        if ($outcome === 'not_paid') {
            $gatewayStatus = $this->extractGatewayStatus($result);
            $disposition = $this->disposeNotPaidSession($paymentRequest, $gatewayStatus, $result['message'] ?? null);

            Log::info('paystack.reconcile_not_paid', array_merge($baseLog, [
                'message' => $result['message'] ?? null,
                'gateway_status' => $gatewayStatus,
                'disposition' => $disposition,
            ]));

            return array_merge($baseLog, [
                'result' => 'not_paid',
                'message' => $result['message'] ?? null,
                'gateway_status' => $gatewayStatus,
                'disposition' => $disposition,
            ]);
        }

        if ($outcome === 'order_placed') {
            Log::info('paystack.reconciliation_recovered', array_merge($baseLog, [
                'order_id' => $result['order_id'] ?? null,
            ]));

            return array_merge($baseLog, [
                'result' => 'recovered',
                'order_id' => $result['order_id'] ?? null,
            ]);
        }

        if ($outcome === 'verified_not_placed') {
            Log::warning('paystack.reconciliation_failed', array_merge($baseLog, [
                'placement_status' => $result['placement_status'] ?? null,
                'placement_error' => $result['placement_error'] ?? null,
            ]));

            return array_merge($baseLog, [
                'result' => 'order_failed',
                'placement_status' => $result['placement_status'] ?? null,
                'placement_error' => $result['placement_error'] ?? null,
            ]);
        }

        if ($outcome === 'verify_failed') {
            $this->touchReconcileAttempt($paymentRequest);

            Log::warning('paystack.reconcile_verify_failed', array_merge($baseLog, [
                'message' => $result['message'] ?? null,
            ]));

            return array_merge($baseLog, [
                'result' => 'verify_failed',
                'message' => $result['message'] ?? null,
                'disposition' => 'cooldown',
            ]);
        }

        if ($outcome === 'failed') {
            Log::warning('paystack.reconciliation_failed', array_merge($baseLog, [
                'message' => $result['message'] ?? null,
            ]));

            return array_merge($baseLog, [
                'result' => 'order_failed',
                'message' => $result['message'] ?? null,
            ]);
        }

        Log::info('paystack.reconciliation_skipped', array_merge($baseLog, [
            'reason' => $outcome,
        ]));

        return array_merge($baseLog, [
            'result' => 'skipped',
            'reason' => $outcome,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function extractGatewayStatus(array $result): string
    {
        $details = $result['payment_details'] ?? null;
        if (is_array($details)) {
            $status = strtolower(trim((string) ($details['data']['status'] ?? '')));
            if ($status !== '') {
                return $status;
            }
        }

        $message = (string) ($result['message'] ?? '');
        if (preg_match('/status:\s*([a-z0-9_]+)/i', $message, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return 'unknown';
    }

    /**
     * Mark abandoned/failed gateway sessions terminal, or cool down in-progress ones.
     */
    private function disposeNotPaidSession(
        PaymentRequest $paymentRequest,
        string $gatewayStatus,
        ?string $message,
    ): string {
        $inProgress = $this->configuredStatusList('paystack.reconcile_in_progress_statuses', [
            'ongoing',
            'processing',
            'pending',
        ]);
        $terminal = $this->configuredStatusList('paystack.reconcile_terminal_not_paid_statuses', [
            'abandoned',
            'failed',
            'reversed',
            'deferred_abandoned',
        ]);

        if (in_array($gatewayStatus, $inProgress, true)) {
            $this->touchReconcileAttempt($paymentRequest);

            return 'cooldown';
        }

        // Default: treat unknown/failed/abandoned-like statuses as terminal so they
        // cannot monopolize every reconcile cycle.
        if ($gatewayStatus === 'unknown' || in_array($gatewayStatus, $terminal, true) || ! in_array($gatewayStatus, $inProgress, true)) {
            $this->markGatewayNotPaid($paymentRequest, $gatewayStatus, $message);

            return 'terminal';
        }

        $this->touchReconcileAttempt($paymentRequest);

        return 'cooldown';
    }

    private function markGatewayNotPaid(
        PaymentRequest $paymentRequest,
        string $gatewayStatus,
        ?string $message,
    ): void {
        $updated = PaymentRequest::query()
            ->where('id', $paymentRequest->id)
            ->where('is_paid', 0)
            ->whereNull('placed_order_id')
            ->where(function (Builder $builder) {
                $builder->whereNull('placement_status')
                    ->orWhereNotIn('placement_status', PaymentRequest::excludedRecoveryPlacementStatuses());
            })
            ->update([
                'placement_status' => PaymentRequest::PLACEMENT_GATEWAY_NOT_PAID,
                'placement_error' => json_encode([
                    'code' => 'paystack_gateway_not_paid',
                    'gateway_status' => $gatewayStatus,
                    'message' => $message,
                    'marked_by' => 'paystack_reconcile',
                    'marked_at' => now()->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'placement_attempted_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            Log::info('paystack.reconcile_marked_gateway_not_paid', [
                'payment_request_id' => $paymentRequest->id,
                'transaction_reference' => $paymentRequest->transaction_id,
                'gateway_status' => $gatewayStatus,
            ]);
        }
    }

    private function touchReconcileAttempt(PaymentRequest $paymentRequest): void
    {
        PaymentRequest::query()
            ->where('id', $paymentRequest->id)
            ->where('is_paid', 0)
            ->whereNull('placed_order_id')
            ->where(function (Builder $builder) {
                $builder->whereNull('placement_status')
                    ->orWhereNotIn('placement_status', PaymentRequest::excludedRecoveryPlacementStatuses());
            })
            ->update([
                'placement_attempted_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private function configuredStatusList(string $configKey, array $default): array
    {
        $configured = config($configKey, $default);
        if (! is_array($configured)) {
            return $default;
        }

        $normalized = [];
        foreach ($configured as $status) {
            if (! is_string($status)) {
                continue;
            }
            $value = strtolower(trim($status));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return $normalized !== [] ? array_values(array_unique($normalized)) : $default;
    }

    /**
     * @param  array<string, mixed>  $paystack
     */
    private function recoveryRecommendation(?PaymentRequest $paymentRequest, array $paystack, ?int $existingOrderId): string
    {
        if ($paymentRequest === null) {
            return 'No payment_requests row — manual investigation required.';
        }

        if ($existingOrderId !== null) {
            return 'Order already exists (#' . $existingOrderId . ') — no action required.';
        }

        if (($paystack['successful'] ?? false) || (int) $paymentRequest->is_paid === 1) {
            return 'php artisan paystack:reconcile-unverified --reference=' . $paymentRequest->transaction_id;
        }

        return 'Wait for customer payment or run reconcile after Paystack shows SUCCESS.';
    }

    /**
     * @return array{verified: int, orders_placed: int, order_failed: int, verify_failed: int, not_paid: int, skipped: int, scanned: int, details: list<array<string, mixed>>}
     */
    private function emptySummary(): array
    {
        return [
            'verified' => 0,
            'orders_placed' => 0,
            'order_failed' => 0,
            'verify_failed' => 0,
            'not_paid' => 0,
            'skipped' => 0,
            'scanned' => 0,
            'details' => [],
        ];
    }
}
