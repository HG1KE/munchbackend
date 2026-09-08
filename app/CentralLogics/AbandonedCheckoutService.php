<?php

namespace App\CentralLogics;

use App\Model\AbandonedCheckout;
use App\Model\Order;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Abandoned-checkout recovery via TextSMS promotional sender.
 * Isolated from OTP and transactional SMS flows.
 */
class AbandonedCheckoutService
{
    public const GATEWAY_KEY = 'textsms_ke_abandoned_cart';

    private const DEFAULT_DELAY_MINUTES = 30;
    private const DEFAULT_MAX_ATTEMPTS = 1;
    private const DEFAULT_COOLDOWN_HOURS = 24;
    private const DEFAULT_QUIET_START = '21:00';
    private const DEFAULT_QUIET_END = '08:00';
    private const REAPER_BATCH_SIZE = 50;

    private static function reaperBatchSize(): int
    {
        return max(1, (int) config('abandoned_checkout.reaper_batch_size', self::REAPER_BATCH_SIZE));
    }

    private static function staleClaimMinutes(): int
    {
        return max(15, (int) config('abandoned_checkout.stale_claim_minutes', 60));
    }

    private static function sendViaQueue(): bool
    {
        return (bool) config('abandoned_checkout.send_via_queue', false);
    }

    /**
     * Capture a checkout-intent snapshot. Returns the persisted row (or existing dedup match).
     *
     * @param array<string,mixed> $payload
     */
    public static function capture(array $payload): ?AbandonedCheckout
    {
        $phone = self::sanitizePhone((string) ($payload['phone'] ?? ''));
        if ($phone === '') {
            return null;
        }

        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;
        $guestId = isset($payload['guest_id']) ? (int) $payload['guest_id'] : null;
        $isGuest = $userId ? 0 : 1;
        $clientToken = isset($payload['client_token']) ? substr((string) $payload['client_token'], 0, 64) : null;

        if ($clientToken !== null && $clientToken !== '') {
            $existing = AbandonedCheckout::query()
                ->where('client_token', $clientToken)
                ->whereNull('converted_at')
                ->first();
            if ($existing) {
                self::refreshExisting($existing, $payload);

                return $existing;
            }
        }

        $dedupWindow = (int) self::settingNumeric('delay_minutes', self::DEFAULT_DELAY_MINUTES);
        $recent = AbandonedCheckout::query()
            ->where('phone', $phone)
            ->whereNull('converted_at')
            ->whereNull('sms_sent_at')
            ->where('created_at', '>=', Carbon::now()->subMinutes(max(1, $dedupWindow)))
            ->first();
        if ($recent) {
            self::refreshExisting($recent, $payload);

            return $recent;
        }

        // One SMS per abandonment episode: after a successful send, do not insert a new open row
        // when the checkout fingerprint is unchanged (prevents repeat SMS each cooldown for stale carts).
        $latestOpen = AbandonedCheckout::query()
            ->where('phone', $phone)
            ->whereNull('converted_at')
            ->orderByDesc('id')
            ->first();
        if ($latestOpen !== null
            && $latestOpen->sms_sent_at !== null
            && self::episodeFingerprintFromRow($latestOpen) === self::episodeFingerprintFromPayload($payload)) {
            self::refreshExisting($latestOpen, $payload);

            return $latestOpen;
        }

        $row = new AbandonedCheckout([
            'user_id' => $userId,
            'guest_id' => $guestId,
            'is_guest' => $isGuest,
            'phone' => $phone,
            'branch_id' => isset($payload['branch_id']) ? (int) $payload['branch_id'] : null,
            'cart' => is_array($payload['cart'] ?? null) ? $payload['cart'] : null,
            'item_count' => (int) ($payload['item_count'] ?? (is_array($payload['cart'] ?? null) ? count($payload['cart']) : 0)),
            'expected_total' => isset($payload['expected_total']) ? (float) $payload['expected_total'] : null,
            'currency_code' => isset($payload['currency_code']) ? substr((string) $payload['currency_code'], 0, 8) : null,
            'locale' => isset($payload['locale']) ? substr((string) $payload['locale'], 0, 8) : null,
            'source' => isset($payload['source']) ? substr((string) $payload['source'], 0, 32) : 'web',
            'client_token' => $clientToken !== '' ? $clientToken : null,
        ]);
        $row->save();

        Log::info('abandoned_cart.captured', [
            'id' => $row->id,
            'is_guest' => $row->is_guest,
            'branch_id' => $row->branch_id,
            'item_count' => $row->item_count,
            'phone_suffix' => self::phoneSuffix($row->phone),
        ]);

        return $row;
    }

    /**
     * Called by OrderObserver on Order::created. Marks the most recent matching open
     * abandoned-checkout row as converted. Safe to call always — no-op when no match.
     */
    public static function linkOrderConversion(Order $order): void
    {
        try {
            $deliveryAddress = is_array($order->delivery_address) ? $order->delivery_address : [];
            $candidatePhone = (string) ($deliveryAddress['contact_person_number'] ?? $deliveryAddress['phone'] ?? '');
            if ($candidatePhone === '' && (int) $order->is_guest === 0 && $order->user_id) {
                $customer = User::query()->find($order->user_id);
                $candidatePhone = (string) ($customer->phone ?? '');
            }
            $phone = self::sanitizePhone($candidatePhone);

            $userId = (int) $order->is_guest === 0 ? (int) $order->user_id : null;

            $query = AbandonedCheckout::query()
                ->whereNull('converted_at')
                ->orderByDesc('id');

            if ($userId) {
                $query->where(function ($q) use ($userId, $phone) {
                    $q->where('user_id', $userId);
                    if ($phone !== '') {
                        $q->orWhere('phone', $phone);
                    }
                });
            } elseif ($phone !== '') {
                $query->where('phone', $phone);
            } else {
                return;
            }

            $row = $query->first();
            if (! $row) {
                return;
            }

            $row->forceFill([
                'converted_at' => now(),
                'converted_order_id' => $order->id,
            ])->save();

            Log::info('abandoned_cart.converted', [
                'id' => $row->id,
                'order_id' => $order->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('abandoned_cart.convert_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Idempotent helper available to the storefront if it ever needs to mark a row as recovered.
     */
    public static function markRecovered(int $id, ?int $orderId = null): bool
    {
        $row = AbandonedCheckout::query()->find($id);
        if (! $row) {
            return false;
        }
        if ($row->converted_at !== null) {
            return true;
        }
        $row->forceFill([
            'converted_at' => now(),
            'converted_order_id' => $orderId,
        ])->save();

        return true;
    }

    /**
     * Scheduler entry: find due rows, claim, send inline or queue.
     *
     * @return array{claimed: int, sent: int, queued: int, skipped: int}
     */
    public static function dispatchDue(): array
    {
        $stats = ['claimed' => 0, 'sent' => 0, 'queued' => 0, 'skipped' => 0];

        $config = SMS_module::getAbandonedCartRuntimeConfig();
        if (! is_array($config) || (int) ($config['status'] ?? 0) !== 1) {
            return $stats;
        }

        $delayMinutes = (int) self::settingNumeric('delay_minutes', self::DEFAULT_DELAY_MINUTES, $config);
        $maxAttempts = (int) self::settingNumeric('max_attempts', self::DEFAULT_MAX_ATTEMPTS, $config);
        $threshold = Carbon::now()->subMinutes(max(1, $delayMinutes));
        $staleBefore = Carbon::now()->subMinutes(self::staleClaimMinutes());

        $candidates = AbandonedCheckout::query()
            ->whereNull('converted_at')
            ->whereNull('sms_sent_at')
            ->where('sms_attempts', '<', max(1, $maxAttempts))
            ->where('created_at', '<=', $threshold)
            ->where(function ($q) use ($staleBefore) {
                $q->whereNull('sms_queued_at')
                    ->orWhere('sms_queued_at', '<', $staleBefore);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::reaperBatchSize())
            ->get();

        foreach ($candidates as $row) {
            $skipReason = self::preDispatchSkipReason($row, $config);
            if ($skipReason !== null) {
                self::recordSkipReason($row, $skipReason);
                $stats['skipped']++;

                continue;
            }

            if (! self::claimRow((int) $row->id, $staleBefore)) {
                continue;
            }

            $stats['claimed']++;
            $row->refresh();

            if (self::sendViaQueue()) {
                $stats['queued']++;
                \App\Jobs\SendAbandonedCheckoutSmsJob::dispatch((int) $row->id);
            } else {
                $result = self::sendForRow((int) $row->id);
                if ($result === 'success') {
                    $stats['sent']++;
                }
            }
        }

        if ($stats['claimed'] > 0) {
            Log::info('abandoned_cart.reaper_dispatched', array_merge($stats, [
                'send_via_queue' => self::sendViaQueue(),
            ]));
        }

        return $stats;
    }

    /**
     * @deprecated Use dispatchDue(); kept for tests/custom dispatchers.
     *
     * @param  callable(AbandonedCheckout): void  $dispatcher
     */
    public static function reap(callable $dispatcher): int
    {
        $count = 0;
        $config = SMS_module::getAbandonedCartRuntimeConfig();
        if (! is_array($config) || (int) ($config['status'] ?? 0) !== 1) {
            return 0;
        }

        $delayMinutes = (int) self::settingNumeric('delay_minutes', self::DEFAULT_DELAY_MINUTES, $config);
        $threshold = Carbon::now()->subMinutes(max(1, $delayMinutes));
        $staleBefore = Carbon::now()->subMinutes(self::staleClaimMinutes());

        $candidates = AbandonedCheckout::query()
            ->whereNull('converted_at')
            ->whereNull('sms_sent_at')
            ->where('created_at', '<=', $threshold)
            ->where(function ($q) use ($staleBefore) {
                $q->whereNull('sms_queued_at')->orWhere('sms_queued_at', '<', $staleBefore);
            })
            ->orderBy('id')
            ->limit(self::reaperBatchSize())
            ->get();

        foreach ($candidates as $row) {
            if (! self::passesPreDispatchGuards($row, $config)) {
                continue;
            }
            if (self::claimRow((int) $row->id, $staleBefore)) {
                $dispatcher($row);
                $count++;
            }
        }

        return $count;
    }

    private static function claimRow(int $id, Carbon $staleBefore): bool
    {
        return AbandonedCheckout::query()
            ->where('id', $id)
            ->whereNull('converted_at')
            ->whereNull('sms_sent_at')
            ->where(function ($q) use ($staleBefore) {
                $q->whereNull('sms_queued_at')
                    ->orWhere('sms_queued_at', '<', $staleBefore);
            })
            ->update(['sms_queued_at' => now()]) === 1;
    }

    private static function recordSkipReason(AbandonedCheckout $row, string $reason): void
    {
        if ($row->last_skip_reason === $reason) {
            return;
        }

        $row->forceFill(['last_skip_reason' => $reason])->save();

        Log::debug('abandoned_cart.skipped', [
            'id' => $row->id,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private static function preDispatchSkipReason(AbandonedCheckout $row, array $config): ?string
    {
        if (! self::passesPreDispatchGuards($row, $config)) {
            $maxAttempts = (int) self::settingNumeric('max_attempts', self::DEFAULT_MAX_ATTEMPTS, $config);
            if ((int) $row->sms_attempts >= max(1, $maxAttempts)) {
                return 'max_attempts';
            }

            $cooldownHours = (int) self::settingNumeric('cooldown_hours', self::DEFAULT_COOLDOWN_HOURS, $config);
            if ($cooldownHours > 0) {
                $recentForPhone = AbandonedCheckout::query()
                    ->where('phone', $row->phone)
                    ->where('id', '!=', $row->id)
                    ->whereNotNull('sms_sent_at')
                    ->where('sms_sent_at', '>=', Carbon::now()->subHours($cooldownHours))
                    ->exists();
                if ($recentForPhone) {
                    return 'cooldown';
                }
            }

            if (self::isQuietHoursNow($config)) {
                return 'quiet_hours';
            }

            return 'guard';
        }

        return null;
    }

    /**
     * Send SMS for a single row. Intended to be invoked from the queued job.
     * All guards are re-checked here to avoid race conditions.
     */
    public static function sendForRow(int $id): string
    {
        $row = AbandonedCheckout::query()->find($id);
        if (! $row) {
            return 'not_found';
        }
        if ($row->converted_at !== null || $row->sms_sent_at !== null) {
            $row->forceFill(['sms_processed_at' => now()])->save();

            return 'skipped_already_handled';
        }

        $config = SMS_module::getAbandonedCartRuntimeConfig();
        if (! is_array($config) || (int) ($config['status'] ?? 0) !== 1) {
            self::recordSkipReason($row, 'gateway_disabled');

            return 'skipped_gateway_disabled';
        }
        if (! self::passesPreDispatchGuards($row, $config)) {
            self::recordSkipReason($row, self::preDispatchSkipReason($row, $config) ?? 'guard');

            return 'skipped_guard';
        }

        $vars = self::buildTemplateVars($row, $config);
        $template = (string) ($config['message_template'] ?? '');
        if (trim($template) === '') {
            return 'skipped_no_template';
        }

        $row->forceFill(['sms_attempts' => (int) $row->sms_attempts + 1])->save();

        $result = SMS_module::textsms_ke_abandoned_cart((string) $row->phone, $vars);

        $processedAt = now();
        $row->forceFill([
            'last_provider_status' => substr($result, 0, 32),
            'sms_sent_at' => $result === 'success' ? $processedAt : $row->sms_sent_at,
            'sms_processed_at' => $processedAt,
            'last_error' => $result === 'success' ? null : 'provider_error',
            'last_skip_reason' => $result === 'success' ? null : $row->last_skip_reason,
        ])->save();

        $delayMinutes = $row->created_at
            ? $row->created_at->diffInMinutes($processedAt)
            : null;

        Log::info('abandoned_cart.sms_attempt', [
            'id' => $row->id,
            'result' => $result,
            'attempt' => $row->sms_attempts,
            'phone_suffix' => self::phoneSuffix($row->phone),
            'minutes_since_created' => $delayMinutes,
            'minutes_since_queued' => $row->sms_queued_at
                ? $row->sms_queued_at->diffInMinutes($processedAt)
                : null,
        ]);

        return $result;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private static function refreshExisting(AbandonedCheckout $row, array $payload): void
    {
        $patch = [];
        if (isset($payload['cart']) && is_array($payload['cart'])) {
            $patch['cart'] = $payload['cart'];
            $patch['item_count'] = (int) ($payload['item_count'] ?? count($payload['cart']));
        }
        if (isset($payload['expected_total'])) {
            $patch['expected_total'] = (float) $payload['expected_total'];
        }
        if (isset($payload['branch_id']) && (int) $payload['branch_id'] > 0) {
            $patch['branch_id'] = (int) $payload['branch_id'];
        }
        if (isset($payload['currency_code'])) {
            $patch['currency_code'] = substr((string) $payload['currency_code'], 0, 8);
        }
        if (isset($payload['locale'])) {
            $patch['locale'] = substr((string) $payload['locale'], 0, 8);
        }
        if ($patch) {
            $row->forceFill($patch)->save();
        }
    }

    /**
     * @param  array<string,mixed>|null  $config
     */
    private static function passesPreDispatchGuards(AbandonedCheckout $row, ?array $config = null): bool
    {
        $config = $config ?? SMS_module::getAbandonedCartRuntimeConfig();
        if (! is_array($config)) {
            return false;
        }

        $maxAttempts = (int) self::settingNumeric('max_attempts', self::DEFAULT_MAX_ATTEMPTS, $config);
        if ((int) $row->sms_attempts >= max(1, $maxAttempts)) {
            return false;
        }

        $cooldownHours = (int) self::settingNumeric('cooldown_hours', self::DEFAULT_COOLDOWN_HOURS, $config);
        if ($cooldownHours > 0) {
            $recentForPhone = AbandonedCheckout::query()
                ->where('phone', $row->phone)
                ->where('id', '!=', $row->id)
                ->whereNotNull('sms_sent_at')
                ->where('sms_sent_at', '>=', Carbon::now()->subHours($cooldownHours))
                ->exists();
            if ($recentForPhone) {
                return false;
            }
        }

        if (self::isQuietHoursNow($config)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private static function isQuietHoursNow(array $config): bool
    {
        $start = (string) ($config['quiet_hours_start'] ?? self::DEFAULT_QUIET_START);
        $end = (string) ($config['quiet_hours_end'] ?? self::DEFAULT_QUIET_END);
        if ($start === '' || $end === '' || $start === $end) {
            return false;
        }

        try {
            $now = Carbon::now();
            $today = $now->copy()->startOfDay();
            $startAt = $today->copy()->setTimeFromTimeString($start);
            $endAt = $today->copy()->setTimeFromTimeString($end);

            if ($endAt->lessThanOrEqualTo($startAt)) {
                // Crossing midnight (e.g. 21:00 → 08:00): quiet if now >= start (today) OR now < end (today).
                return $now->greaterThanOrEqualTo($startAt) || $now->lessThan($endAt);
            }

            return $now->greaterThanOrEqualTo($startAt) && $now->lessThan($endAt);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,string>
     */
    private static function buildTemplateVars(AbandonedCheckout $row, array $config): array
    {
        $customerName = self::resolveCustomerName($row);
        $branchName = '';
        if ($row->branch_id && $row->branch) {
            $branchName = (string) $row->branch->name;
        }
        $currency = (string) ($row->currency_code ?? '');
        $total = $row->expected_total !== null ? number_format((float) $row->expected_total, 2) : '';
        $recoveryUrl = (string) ($config['recovery_url'] ?? '');

        return [
            'customer_name' => $customerName !== '' ? $customerName : 'there',
            'branch_name' => $branchName,
            'item_count' => (string) (int) $row->item_count,
            'order_amount' => $total,
            'currency' => $currency,
            'recovery_url' => $recoveryUrl,
        ];
    }

    /**
     * Rendered promotional SMS body for admin preview (same placeholders as send path; no HTTP).
     */
    public static function renderAbandonedCheckoutPromotionalBody(AbandonedCheckout $row): string
    {
        $config = SMS_module::getAbandonedCartRuntimeConfig();
        if (! is_array($config)) {
            return '';
        }
        $template = trim((string) ($config['message_template'] ?? ''));
        if ($template === '') {
            return '';
        }
        $vars = self::buildTemplateVars($row, $config);

        return SMS_module::renderMarketingNotificationTemplate($template, $vars);
    }

    private static function resolveCustomerName(AbandonedCheckout $row): string
    {
        if ((int) $row->is_guest === 0 && $row->user_id && $row->customer) {
            return trim(((string) ($row->customer->f_name ?? '')).' '.((string) ($row->customer->l_name ?? '')));
        }

        return '';
    }

    /**
     * Deterministic episode key from cart, totals, branch, session token, and currency.
     * Used only to decide "same abandonment" vs a new eligible episode after SMS was sent.
     *
     * @param  array<string,mixed>|null  $cart
     */
    private static function episodeFingerprint(
        ?array $cart,
        int $itemCount,
        ?float $expectedTotal,
        ?int $branchId,
        ?string $clientToken,
        ?string $currencyCode
    ): string {
        $cartNorm = self::normalizeCartJsonForFingerprint($cart);
        $totalStr = $expectedTotal !== null ? sprintf('%.2f', $expectedTotal) : '';
        $branchStr = $branchId !== null && $branchId > 0 ? (string) $branchId : '';
        $tokenStr = $clientToken !== null && $clientToken !== '' ? trim($clientToken) : '';
        $currencyStr = $currencyCode !== null && $currencyCode !== '' ? strtoupper(trim($currencyCode)) : '';

        return hash('sha256', $cartNorm.'|'.$itemCount.'|'.$totalStr.'|'.$branchStr.'|'.$tokenStr.'|'.$currencyStr);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private static function episodeFingerprintFromPayload(array $payload): string
    {
        $cart = is_array($payload['cart'] ?? null) ? $payload['cart'] : null;
        $itemCount = (int) ($payload['item_count'] ?? ($cart !== null ? count($cart) : 0));
        $expectedTotal = isset($payload['expected_total']) ? (float) $payload['expected_total'] : null;
        $branchId = isset($payload['branch_id']) ? (int) $payload['branch_id'] : null;
        $branchId = $branchId > 0 ? $branchId : null;
        $clientToken = isset($payload['client_token']) ? substr(trim((string) $payload['client_token']), 0, 64) : null;
        $clientToken = $clientToken !== '' ? $clientToken : null;
        $currency = isset($payload['currency_code']) ? substr((string) $payload['currency_code'], 0, 8) : null;
        $currency = $currency !== '' ? $currency : null;

        return self::episodeFingerprint($cart, $itemCount, $expectedTotal, $branchId, $clientToken, $currency);
    }

    private static function episodeFingerprintFromRow(AbandonedCheckout $row): string
    {
        $cart = is_array($row->cart) ? $row->cart : null;
        $itemCount = (int) $row->item_count;
        $expectedTotal = $row->expected_total !== null ? (float) $row->expected_total : null;
        $branchId = $row->branch_id ? (int) $row->branch_id : null;
        $clientToken = $row->client_token ? trim((string) $row->client_token) : null;
        $currency = $row->currency_code ? (string) $row->currency_code : null;

        return self::episodeFingerprint($cart, $itemCount, $expectedTotal, $branchId, $clientToken, $currency);
    }

    /**
     * @param  array<string,mixed>|null  $cart
     */
    private static function normalizeCartJsonForFingerprint(?array $cart): string
    {
        if ($cart === null || $cart === []) {
            return '';
        }
        $tree = json_decode(json_encode($cart), true);
        if (! is_array($tree)) {
            return '';
        }
        self::sortArrayRecursive($tree);

        return json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string,mixed>  $arr
     */
    private static function sortArrayRecursive(array &$arr): void
    {
        ksort($arr, SORT_STRING);
        foreach ($arr as &$v) {
            if (is_array($v)) {
                self::sortArrayRecursive($v);
            }
        }
    }

    private static function sanitizePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        // Strip everything except leading + and digits
        $hasPlus = str_starts_with($phone, '+');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return ($hasPlus ? '+' : '').$digits;
    }

    private static function phoneSuffix(?string $phone): string
    {
        $phone = (string) $phone;

        return strlen($phone) >= 4 ? substr($phone, -4) : '****';
    }

    /**
     * @param  array<string,mixed>|null  $config
     */
    private static function settingNumeric(string $key, int $default, ?array $config = null): int
    {
        $config = $config ?? SMS_module::getAbandonedCartRuntimeConfig();
        if (! is_array($config) || ! isset($config[$key]) || $config[$key] === '') {
            return $default;
        }
        $val = (int) $config[$key];

        return $val > 0 ? $val : $default;
    }
}
