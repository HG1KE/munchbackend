<?php

namespace App\Services\Paystack;

use App\Models\PaymentRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * @deprecated Use PaystackUnverifiedReconciliationService (unified safety net).
 */
class PaystackPaidOrderRecoveryService
{
    public function __construct(
        private readonly PaystackUnverifiedReconciliationService $reconciliation,
    ) {}

    /**
     * @return array{recovered: int, still_stuck: int, scanned: int, skipped: int, details: list<array<string, mixed>>}
     */
    public function recoverStuckSessions(int $minAgeMinutes = 2, int $limit = 25, ?string $referenceFilter = null): array
    {
        if (! Schema::hasTable('payment_requests')) {
            return ['recovered' => 0, 'still_stuck' => 0, 'scanned' => 0, 'skipped' => 0, 'details' => []];
        }

        $result = $this->reconciliation->reconcile($minAgeMinutes, $limit, $referenceFilter);

        $details = array_map(function (array $row) {
            $mapped = [
                'payment_id' => $row['payment_request_id'] ?? null,
                'reference' => $row['transaction_reference'] ?? null,
                'payer_id' => $row['payer_id'] ?? null,
                'payment_amount' => $row['payment_amount'] ?? null,
                'result' => match ($row['result'] ?? '') {
                    'recovered', 'order_placed' => 'recovered',
                    'order_failed' => 'still_stuck',
                    'skipped' => 'skipped_already_placed_or_ineligible',
                    default => $row['result'] ?? 'unknown',
                },
            ];

            if (isset($row['order_id'])) {
                $mapped['order_id'] = $row['order_id'];
            }
            if (isset($row['placement_error'])) {
                $mapped['placement_error'] = $row['placement_error'];
            }

            return $mapped;
        }, $result['details']);

        return [
            'recovered' => $result['orders_placed'],
            'still_stuck' => $result['order_failed'],
            'scanned' => $result['scanned'],
            'skipped' => $result['skipped'],
            'details' => $details,
        ];
    }

    public function stuckSessionsQuery(int $minAgeMinutes = 2, int $limit = 25, ?string $referenceFilter = null)
    {
        return $this->reconciliation->safetyNetCandidatesQuery($minAgeMinutes, $limit, $referenceFilter);
    }

    /**
     * @return Collection<int, PaymentRequest>
     */
    public function stuckSessions(int $minAgeMinutes = 2, int $limit = 25): Collection
    {
        return $this->stuckSessionsQuery($minAgeMinutes, $limit)->get();
    }
}
