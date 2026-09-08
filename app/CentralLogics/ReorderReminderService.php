<?php

namespace App\CentralLogics;

use App\Model\Branch;
use App\Model\Order;
use App\Model\OrderDetail;
use App\Model\ReorderReminderLog;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repeat-customer reorder reminders via TextSMS promotional sender.
 */
class ReorderReminderService
{
    public const GATEWAY_KEY = 'textsms_ke_reorder_reminder';

    private const DEFAULT_DELAY_DAYS = 14;
    private const DEFAULT_MIN_ORDERS = 2;
    private const DEFAULT_MAX_ATTEMPTS = 1;
    private const DEFAULT_COOLDOWN_DAYS = 30;
    private const DEFAULT_QUIET_START = '21:00';
    private const DEFAULT_QUIET_END = '08:00';
    private const REAPER_BATCH_SIZE = 50;

    /** @var array<int, string> */
    private const COMPLETED_STATUSES = ['delivered', 'completed'];

    /**
     * Scheduler entry. Finds eligible customers and queues SMS jobs.
     *
     * @param  callable(ReorderReminderLog): void  $dispatcher
     */
    public static function reap(callable $dispatcher): int
    {
        $config = SMS_module::getReorderReminderRuntimeConfig();
        if (! is_array($config) || (int) ($config['status'] ?? 0) !== 1) {
            return 0;
        }

        if (self::isQuietHoursNow($config)) {
            return 0;
        }

        $delayDays = (int) self::settingNumeric('delay_days', self::DEFAULT_DELAY_DAYS, $config);
        $minOrders = (int) self::settingNumeric('minimum_completed_orders', self::DEFAULT_MIN_ORDERS, $config);
        $threshold = Carbon::now()->subDays(max(1, $delayDays));
        $dispatched = 0;
        $lastUserId = 0;

        while (true) {
            $candidates = self::fetchEligibleCandidateBatch($lastUserId, $minOrders, $threshold);
            if ($candidates->isEmpty()) {
                break;
            }

            $userIds = $candidates->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $users = User::query()
                ->whereIn('id', $userIds)
                ->get(['id', 'f_name', 'l_name', 'phone'])
                ->keyBy('id');

            $orderIds = $candidates->pluck('last_order_id')->map(fn ($id) => (int) $id)->all();
            $orders = Order::query()
                ->with('branch:id,name')
                ->whereIn('id', $orderIds)
                ->get()
                ->keyBy('id');

            foreach ($candidates as $row) {
                $lastUserId = (int) $row->user_id;
                $user = $users->get($lastUserId);
                $order = $orders->get((int) $row->last_order_id);

                if (! $user || ! $order) {
                    continue;
                }

                $snapshot = self::buildSnapshot($user, $order, (int) $row->completed_count);
                $guard = self::evaluateGuards($snapshot, $config);

                if ($guard !== null) {
                    self::recordSkip($snapshot, $guard, $config);

                    continue;
                }

                $log = self::createQueuedLog($snapshot);
                $dispatcher($log);
                $dispatched++;
            }

            if ($candidates->count() < self::REAPER_BATCH_SIZE) {
                break;
            }
        }

        if ($dispatched > 0) {
            Log::info('reorder_reminder.reaper_dispatched', ['count' => $dispatched]);
        }

        return $dispatched;
    }

    /**
     * Send SMS for a queued log row (invoked from the job).
     */
    public static function sendForRow(int $id): string
    {
        $log = ReorderReminderLog::query()->find($id);
        if (! $log) {
            return 'not_found';
        }
        if ($log->status === 'sent' && $log->sms_sent_at !== null) {
            return 'skipped_already_sent';
        }
        if ($log->status === 'skipped') {
            return 'skipped_already_skipped';
        }

        $config = SMS_module::getReorderReminderRuntimeConfig();
        if (! is_array($config) || (int) ($config['status'] ?? 0) !== 1) {
            $log->forceFill([
                'status' => 'skipped',
                'skip_reason' => 'gateway_disabled',
            ])->save();

            return 'skipped_gateway_disabled';
        }

        $user = User::query()->find($log->user_id);
        $order = Order::query()->with('branch')->find($log->last_delivered_order_id);
        if (! $user || ! $order) {
            $log->forceFill([
                'status' => 'skipped',
                'skip_reason' => 'missing_context',
            ])->save();

            return 'skipped_missing_context';
        }

        $snapshot = self::buildSnapshot($user, $order);
        $guard = self::evaluateGuards($snapshot, $config, $log);
        if ($guard !== null) {
            $log->forceFill([
                'status' => 'skipped',
                'skip_reason' => $guard,
            ])->save();

            return 'skipped_guard';
        }

        $template = trim((string) ($config['message_template'] ?? ''));
        if ($template === '') {
            $log->forceFill([
                'status' => 'skipped',
                'skip_reason' => 'no_template',
            ])->save();

            return 'skipped_no_template';
        }

        $log->forceFill(['sms_attempts' => (int) $log->sms_attempts + 1])->save();

        $vars = self::buildTemplateVars($log, $config);
        $result = SMS_module::textsms_ke_reorder_reminder((string) $log->phone, $vars);

        $log->forceFill([
            'last_provider_status' => substr($result, 0, 32),
            'status' => $result === 'success' ? 'sent' : 'failed',
            'sms_sent_at' => $result === 'success' ? now() : $log->sms_sent_at,
            'last_error' => $result === 'success' ? null : 'provider_error',
            'skip_reason' => $result === 'success' ? null : $log->skip_reason,
        ])->save();

        Log::info('reorder_reminder.sms_attempt', [
            'id' => $log->id,
            'user_id' => $log->user_id,
            'result' => $result,
            'attempt' => $log->sms_attempts,
            'phone_suffix' => self::phoneSuffix($log->phone),
        ]);

        return $result;
    }

    /**
     * Admin test send (does not create a log row).
     *
     * @return array{ok: bool, message: string}
     */
    public static function sendTestSms(string $phone, ?array $config = null): array
    {
        $phoneSuffix = self::phoneSuffix($phone);

        try {
            $phone = self::validatePhoneForSms($phone);
            if ($phone === null) {
                Log::warning('reorder_reminder.test_validation_failed', [
                    'reason' => 'invalid_phone',
                    'phone_suffix' => $phoneSuffix,
                ]);

                return ['ok' => false, 'message' => 'Please enter a valid phone number'];
            }

            $config = $config ?? SMS_module::getReorderReminderTestRuntimeConfig();
            if (! is_array($config)) {
                Log::warning('reorder_reminder.test_gateway_not_configured', [
                    'phone_suffix' => self::phoneSuffix($phone),
                ]);

                return [
                    'ok' => false,
                    'message' => 'SMS gateway is not configured. Set up Promotional SMS gateway first.',
                ];
            }

            $template = trim((string) ($config['message_template'] ?? ''));
            if ($template === '') {
                Log::warning('reorder_reminder.test_validation_failed', [
                    'reason' => 'empty_template',
                    'phone_suffix' => self::phoneSuffix($phone),
                ]);

                return ['ok' => false, 'message' => 'Message template is empty'];
            }

            if ((int) ($config['status'] ?? 0) !== 1) {
                Log::warning('reorder_reminder.test_gateway_inactive', [
                    'phone_suffix' => self::phoneSuffix($phone),
                ]);

                return [
                    'ok' => false,
                    'message' => 'Promotional SMS credentials are missing or inactive. Enable Promotional SMS gateway.',
                ];
            }

            $vars = [
                'customer_name' => 'Test Customer',
                'branch_name' => 'Munch',
                'currency' => self::safeCurrencySymbol(),
                'last_order_total' => number_format(1200, 2),
                'recovery_url' => (string) ($config['recovery_url'] ?? ''),
                'favorite_item' => 'Sample Burger',
                'last_order_date' => Carbon::now()->subDays(14)->format('jS F Y'),
            ];

            Log::info('reorder_reminder.test_attempt', [
                'phone_suffix' => self::phoneSuffix($phone),
            ]);

            $message = SMS_module::renderMarketingNotificationTemplate($template, $vars);
            $result = SMS_module::textsms_ke_send_reorder_reminder_test($phone, $message);

            if ($result === 'success') {
                Log::info('reorder_reminder.test_sent', [
                    'phone_suffix' => self::phoneSuffix($phone),
                ]);

                return ['ok' => true, 'message' => 'Test SMS sent successfully'];
            }

            Log::warning('reorder_reminder.test_gateway_failed', [
                'phone_suffix' => self::phoneSuffix($phone),
                'provider_result' => $result,
            ]);

            return [
                'ok' => false,
                'message' => 'SMS provider returned: '.$result.'. Check Promotional SMS gateway credentials.',
            ];
        } catch (Throwable $e) {
            Log::error('reorder_reminder.test_exception', [
                'phone_suffix' => $phoneSuffix,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'Test SMS could not be sent. Please try again.'];
        }
    }

    private static function validatePhoneForSms(string $phone): ?string
    {
        $sanitized = self::sanitizePhone($phone);
        $digits = preg_replace('/\D+/', '', $sanitized) ?? '';

        if (strlen($digits) < 9) {
            return null;
        }

        return $sanitized;
    }

    private static function safeCurrencySymbol(): string
    {
        try {
            $symbol = Helpers::currency_symbol();

            return $symbol !== null && $symbol !== '' ? (string) $symbol : 'Ksh';
        } catch (Throwable $e) {
            return 'Ksh';
        }
    }

    public static function renderPromotionalBody(ReorderReminderLog $log): string
    {
        $config = SMS_module::getReorderReminderRuntimeConfig();
        if (! is_array($config)) {
            return '';
        }
        $template = trim((string) ($config['message_template'] ?? ''));
        if ($template === '') {
            return '';
        }

        return SMS_module::renderMarketingNotificationTemplate($template, self::buildTemplateVars($log, $config));
    }

    /**
     * @return Collection<int, object{user_id: int, last_order_id: int, last_order_at: string, completed_count: int}>
     */
    private static function fetchEligibleCandidateBatch(int $afterUserId, int $minOrders, Carbon $threshold): Collection
    {
        return DB::table('orders')
            ->select([
                'user_id',
                DB::raw('MAX(id) as last_order_id'),
                DB::raw('MAX(created_at) as last_order_at'),
                DB::raw('COUNT(*) as completed_count'),
            ])
            ->where('is_guest', 0)
            ->whereNotNull('user_id')
            ->whereIn('order_status', self::COMPLETED_STATUSES)
            ->where('user_id', '>', $afterUserId)
            ->groupBy('user_id')
            ->having('completed_count', '>=', $minOrders)
            ->having('last_order_at', '<=', $threshold)
            ->orderBy('user_id')
            ->limit(self::REAPER_BATCH_SIZE)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildSnapshot(User $user, Order $order, ?int $completedCount = null): array
    {
        $phone = self::sanitizePhone((string) ($user->phone ?? ''));
        $name = trim(((string) ($user->f_name ?? '')).' '.((string) ($user->l_name ?? '')));
        $branchName = $order->branch ? (string) $order->branch->name : '';

        return [
            'user_id' => (int) $user->id,
            'phone' => $phone,
            'customer_name' => $name,
            'branch_id' => $order->branch_id ? (int) $order->branch_id : null,
            'branch_name' => $branchName,
            'last_delivered_order_id' => (int) $order->id,
            'last_order_total' => (float) ($order->order_amount ?? 0),
            'last_order_date' => $order->created_at ? $order->created_at->toDateString() : null,
            'favorite_item' => self::resolveFavoriteItem((int) $user->id),
            'completed_count' => $completedCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function evaluateGuards(array $snapshot, array $config, ?ReorderReminderLog $existingLog = null): ?string
    {
        $phone = (string) ($snapshot['phone'] ?? '');
        if ($phone === '') {
            return 'no_phone';
        }

        $userId = (int) ($snapshot['user_id'] ?? 0);
        $orderId = (int) ($snapshot['last_delivered_order_id'] ?? 0);
        if ($userId <= 0 || $orderId <= 0) {
            return 'invalid_snapshot';
        }

        if (self::isQuietHoursNow($config)) {
            return 'quiet_hours';
        }

        $maxAttempts = (int) self::settingNumeric('max_attempts', self::DEFAULT_MAX_ATTEMPTS, $config);
        $sentForEpisode = ReorderReminderLog::query()
            ->where('user_id', $userId)
            ->where('last_delivered_order_id', $orderId)
            ->where('status', 'sent')
            ->count();
        if ($sentForEpisode >= max(1, $maxAttempts)) {
            return 'max_attempts_reached';
        }

        $cooldownDays = (int) self::settingNumeric('cooldown_days', self::DEFAULT_COOLDOWN_DAYS, $config);
        if ($cooldownDays > 0) {
            $recentSent = ReorderReminderLog::query()
                ->where('user_id', $userId)
                ->where('status', 'sent')
                ->whereNotNull('sms_sent_at')
                ->where('sms_sent_at', '>=', Carbon::now()->subDays($cooldownDays))
                ->when($existingLog, fn ($q) => $q->where('id', '!=', $existingLog->id))
                ->exists();
            if ($recentSent) {
                return 'cooldown_active';
            }
        }

        $pending = ReorderReminderLog::query()
            ->where('user_id', $userId)
            ->where('last_delivered_order_id', $orderId)
            ->whereIn('status', ['queued'])
            ->when($existingLog, fn ($q) => $q->where('id', '!=', $existingLog->id))
            ->exists();
        if ($pending) {
            return 'already_queued';
        }

        $branchIds = self::parseBranchIds((string) ($config['branch_ids'] ?? ''));
        if ($branchIds !== [] && isset($snapshot['branch_id']) && ! in_array((int) $snapshot['branch_id'], $branchIds, true)) {
            return 'branch_filtered';
        }

        $delayDays = (int) self::settingNumeric('delay_days', self::DEFAULT_DELAY_DAYS, $config);
        $minOrders = (int) self::settingNumeric('minimum_completed_orders', self::DEFAULT_MIN_ORDERS, $config);
        if (! self::stillMeetsEligibility($userId, $orderId, $minOrders, $delayDays)) {
            return 'no_longer_eligible';
        }

        return null;
    }

    private static function stillMeetsEligibility(int $userId, int $lastOrderId, int $minOrders, int $delayDays): bool
    {
        $threshold = Carbon::now()->subDays(max(1, $delayDays));

        $stats = DB::table('orders')
            ->select([
                DB::raw('MAX(id) as last_order_id'),
                DB::raw('MAX(created_at) as last_order_at'),
                DB::raw('COUNT(*) as completed_count'),
            ])
            ->where('user_id', $userId)
            ->where('is_guest', 0)
            ->whereIn('order_status', self::COMPLETED_STATUSES)
            ->first();

        if (! $stats || (int) $stats->completed_count < $minOrders) {
            return false;
        }
        if ((int) $stats->last_order_id !== $lastOrderId) {
            return false;
        }
        if (Carbon::parse($stats->last_order_at)->gt($threshold)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function recordSkip(array $snapshot, string $reason, array $config): void
    {
        $userId = (int) ($snapshot['user_id'] ?? 0);
        $orderId = (int) ($snapshot['last_delivered_order_id'] ?? 0);
        if ($userId <= 0 || $orderId <= 0) {
            return;
        }

        $recentSkip = ReorderReminderLog::query()
            ->where('user_id', $userId)
            ->where('last_delivered_order_id', $orderId)
            ->where('status', 'skipped')
            ->where('skip_reason', $reason)
            ->where('created_at', '>=', Carbon::now()->subHours(24))
            ->exists();
        if ($recentSkip) {
            return;
        }

        $attemptNumber = self::nextAttemptNumber($userId, $orderId);

        ReorderReminderLog::query()->create([
            'user_id' => $userId,
            'phone' => (string) ($snapshot['phone'] ?? ''),
            'branch_id' => $snapshot['branch_id'] ?? null,
            'last_delivered_order_id' => $orderId,
            'customer_name' => $snapshot['customer_name'] ?? null,
            'branch_name' => $snapshot['branch_name'] ?? null,
            'last_order_total' => $snapshot['last_order_total'] ?? null,
            'favorite_item' => $snapshot['favorite_item'] ?? null,
            'last_order_date' => $snapshot['last_order_date'] ?? null,
            'status' => 'skipped',
            'skip_reason' => $reason,
            'attempt_number' => $attemptNumber,
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function createQueuedLog(array $snapshot): ReorderReminderLog
    {
        $userId = (int) $snapshot['user_id'];
        $orderId = (int) $snapshot['last_delivered_order_id'];

        return ReorderReminderLog::query()->create([
            'user_id' => $userId,
            'phone' => (string) $snapshot['phone'],
            'branch_id' => $snapshot['branch_id'] ?? null,
            'last_delivered_order_id' => $orderId,
            'customer_name' => $snapshot['customer_name'] ?? null,
            'branch_name' => $snapshot['branch_name'] ?? null,
            'last_order_total' => $snapshot['last_order_total'] ?? null,
            'favorite_item' => $snapshot['favorite_item'] ?? null,
            'last_order_date' => $snapshot['last_order_date'] ?? null,
            'status' => 'queued',
            'attempt_number' => self::nextAttemptNumber($userId, $orderId),
        ]);
    }

    private static function nextAttemptNumber(int $userId, int $orderId): int
    {
        $max = (int) ReorderReminderLog::query()
            ->where('user_id', $userId)
            ->where('last_delivered_order_id', $orderId)
            ->max('attempt_number');

        return max(1, $max + 1);
    }

    private static function resolveFavoriteItem(int $userId): string
    {
        $top = OrderDetail::query()
            ->select('product_id', DB::raw('SUM(quantity) as total_qty'))
            ->whereIn('order_id', function ($q) use ($userId) {
                $q->select('id')
                    ->from('orders')
                    ->where('user_id', $userId)
                    ->where('is_guest', 0)
                    ->whereIn('order_status', self::COMPLETED_STATUSES);
            })
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit(1)
            ->first();

        if (! $top || ! $top->product_id) {
            return '';
        }

        $detail = OrderDetail::query()
            ->where('product_id', $top->product_id)
            ->whereIn('order_id', function ($q) use ($userId) {
                $q->select('id')
                    ->from('orders')
                    ->where('user_id', $userId)
                    ->whereIn('order_status', self::COMPLETED_STATUSES);
            })
            ->orderByDesc('id')
            ->first();

        if (! $detail) {
            return '';
        }

        $productDetails = json_decode($detail->product_details ?? '{}', true);
        if (is_array($productDetails) && ! empty($productDetails['name'])) {
            return (string) $productDetails['name'];
        }

        if ($detail->relationLoaded('product') && $detail->product) {
            return (string) ($detail->product->name ?? '');
        }

        $product = \App\Model\Product::query()->find($top->product_id);

        return $product ? (string) $product->name : '';
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,string>
     */
    private static function buildTemplateVars(ReorderReminderLog $log, array $config): array
    {
        $customerName = trim((string) ($log->customer_name ?? ''));
        $lastOrderDate = '';
        if ($log->last_order_date) {
            try {
                $lastOrderDate = Carbon::parse($log->last_order_date)->format('jS F Y');
            } catch (\Throwable $e) {
                $lastOrderDate = (string) $log->last_order_date;
            }
        }

        return [
            'customer_name' => $customerName !== '' ? $customerName : 'there',
            'branch_name' => (string) ($log->branch_name ?? ''),
            'currency' => (string) Helpers::currency_symbol(),
            'last_order_total' => $log->last_order_total !== null ? number_format((float) $log->last_order_total, 2) : '',
            'recovery_url' => (string) ($config['recovery_url'] ?? ''),
            'favorite_item' => (string) ($log->favorite_item ?? ''),
            'last_order_date' => $lastOrderDate,
        ];
    }

    /**
     * @return array<int, int>
     */
    private static function parseBranchIds(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_filter(array_map('intval', $parts), fn ($id) => $id > 0));
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
                return $now->greaterThanOrEqualTo($startAt) || $now->lessThan($endAt);
            }

            return $now->greaterThanOrEqualTo($startAt) && $now->lessThan($endAt);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function sanitizePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
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
        $config = $config ?? SMS_module::getReorderReminderRuntimeConfig();
        if (! is_array($config) || ! isset($config[$key]) || $config[$key] === '') {
            return $default;
        }
        $val = (int) $config[$key];

        return $val > 0 ? $val : $default;
    }
}
