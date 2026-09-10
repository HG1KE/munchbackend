<?php

namespace App\CentralLogics;

use App\Model\Order;
use App\Model\OrderAutomationRun;
use App\Model\OrderAutomationSetting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class OrderAutomationService
{
    /**
     * @return array{count: int, order_ids: array<int, int>}
     */
    public static function previewEligible(bool $manualRun, ?OrderAutomationSetting $settings = null): array
    {
        $settings = $settings ?? OrderAutomationSetting::current();
        $ids = self::eligibleQuery($settings, $manualRun)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'count' => count($ids),
            'order_ids' => $ids,
        ];
    }

    /**
     * @return array{run: OrderAutomationRun, message: string}
     */
    public static function run(string $runType, bool $manualRun, ?int $adminId = null, ?bool $forceDryRun = null): array
    {
        $settings = OrderAutomationSetting::current();
        $dryRun = $forceDryRun ?? ($manualRun ? false : (bool) $settings->dry_run);

        if ($runType === 'scheduled' && ! $settings->is_enabled && $forceDryRun !== true) {
            return [
                'run' => new OrderAutomationRun,
                'message' => 'Automation is disabled',
            ];
        }

        $preview = self::previewEligible($manualRun, $settings);

        $run = OrderAutomationRun::query()->create([
            'run_type' => $runType,
            'dry_run' => $dryRun,
            'admin_id' => $adminId,
            'eligible_count' => $preview['count'],
            'settings_snapshot' => self::settingsSnapshot($settings, $manualRun),
            'started_at' => now(),
            'entries' => [],
        ]);

        $entries = [];
        $completed = 0;
        $skipped = 0;
        $failed = 0;

        Log::info('order_automation.run_started', [
            'run_id' => $run->id,
            'run_type' => $runType,
            'dry_run' => $dryRun,
            'eligible_count' => $preview['count'],
            'require_delivery_man' => (bool) $settings->require_delivery_man,
        ]);

        if ($preview['count'] === 0) {
            $run->update([
                'completed_count' => 0,
                'skipped_count' => 0,
                'failed_count' => 0,
                'entries' => [],
                'finished_at' => now(),
            ]);

            return ['run' => $run->fresh(), 'message' => 'No eligible orders found'];
        }

        $chunkSize = max(10, (int) config('order_automation.chunk_size', 50));

        self::eligibleQuery($settings, $manualRun)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($orders) use ($dryRun, $runType, $settings, &$entries, &$completed, &$skipped, &$failed) {
                foreach ($orders as $order) {
                    $handler = 'order_automation.'.$runType;
                    $transitionOptions = self::transitionOptionsForOrder($order, $settings);
                    $hadDeliveryMan = $order->delivery_man_id !== null;

                    if ($dryRun) {
                        $validation = OrderDeliveredTransitionService::validateForDelivered($order, $transitionOptions);
                        $entry = self::buildAutomationEntry($order, $validation, $hadDeliveryMan, true);
                        $entries[] = $entry;
                        if ($validation['allowed']) {
                            $completed++;
                        } else {
                            $skipped++;
                        }

                        continue;
                    }

                    $previousStatus = $order->order_status;
                    $transition = OrderDeliveredTransitionService::transitionToDelivered($order, $handler, $transitionOptions);
                    $entry = self::buildAutomationEntry($order, $transition, $hadDeliveryMan, false, $previousStatus);
                    $entries[] = $entry;

                    if ($transition['success']) {
                        $completed++;
                    } elseif (($entry['reason'] ?? '') === 'exception') {
                        $failed++;
                    } else {
                        $skipped++;
                    }
                }
            });

        if (count($entries) > 200) {
            $entries = array_slice($entries, -200);
        }

        $run->update([
            'completed_count' => $completed,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'entries' => $entries,
            'finished_at' => now(),
        ]);

        Log::info('order_automation.run_finished', [
            'run_id' => $run->id,
            'run_type' => $runType,
            'dry_run' => $dryRun,
            'completed' => $completed,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);

        $message = $dryRun
            ? "Dry run: {$completed} would be completed, {$skipped} skipped, {$failed} failed"
            : "Completed {$completed} orders, {$skipped} skipped, {$failed} failed";

        return ['run' => $run->fresh(), 'message' => $message];
    }

    /**
     * @return array{require_delivery_man: bool, delivery_man_exempt_order_types: array<int, string>}
     */
    public static function transitionOptionsForOrder(Order $order, OrderAutomationSetting $settings): array
    {
        $exemptTypes = config('order_automation.delivery_man_exempt_order_types', ['take_away', 'dine_in', 'pos']);

        if (in_array($order->order_type, $exemptTypes, true)) {
            return [
                'require_delivery_man' => false,
                'delivery_man_exempt_order_types' => $exemptTypes,
            ];
        }

        return [
            'require_delivery_man' => (bool) $settings->require_delivery_man,
            'delivery_man_exempt_order_types' => $exemptTypes,
        ];
    }

    /**
     * @param  array{allowed?: bool, reason?: string, success?: bool}  $outcome
     * @return array{order_id: int, previous_status: string, result: string, reason: string}
     */
    private static function buildAutomationEntry(
        Order $order,
        array $outcome,
        bool $hadDeliveryMan,
        bool $dryRun,
        ?string $previousStatus = null
    ): array {
        $base = [
            'order_id' => $order->id,
            'previous_status' => $previousStatus ?? $order->order_status,
        ];

        if ($dryRun) {
            if ($outcome['allowed'] ?? false) {
                return $base + [
                    'result' => $hadDeliveryMan ? 'would_complete_with_delivery_man' : 'would_complete_without_delivery_man',
                    'reason' => $hadDeliveryMan ? 'would_complete_with_delivery_man' : 'would_complete_without_delivery_man',
                ];
            }

            return $base + [
                'result' => 'skipped',
                'reason' => self::normalizeSkipReason($outcome['reason'] ?? 'skipped'),
            ];
        }

        if ($outcome['success'] ?? false) {
            return $base + [
                'result' => $hadDeliveryMan ? 'completed_with_delivery_man' : 'completed_without_delivery_man',
                'reason' => $hadDeliveryMan ? 'completed_with_delivery_man' : 'completed_without_delivery_man',
            ];
        }

        $reason = $outcome['reason'] ?? 'skipped';

        return $base + [
            'result' => $reason === 'exception' ? 'failed' : 'skipped',
            'reason' => self::normalizeSkipReason($reason),
        ];
    }

    private static function normalizeSkipReason(string $reason): string
    {
        return $reason === 'missing_delivery_man' ? 'skipped_missing_delivery_man' : $reason;
    }

    private static function eligibleQuery(OrderAutomationSetting $settings, bool $manualRun): Builder
    {
        $eligible = array_values(array_filter(
            (array) $settings->eligible_statuses,
            static fn ($s) => is_string($s) && $s !== ''
        ));
        $excluded = array_values(array_unique(array_merge(
            (array) config('order_automation.default_excluded_statuses'),
            (array) $settings->excluded_statuses
        )));

        if ($eligible === []) {
            $eligible = config('order_automation.default_eligible_statuses');
        }

        $query = Order::query()
            ->onlineOrders()
            ->notSchedule()
            ->whereIn('order_status', $eligible)
            ->whereNotIn('order_status', $excluded);

        if (! $manualRun) {
            $hours = max(1, (int) $settings->auto_complete_hours);
            $threshold = Carbon::now()->subHours($hours);
            $query->where('created_at', '<=', $threshold);
        }

        $branchIds = self::parseBranchIds($settings->branch_ids);
        if ($branchIds !== []) {
            $query->whereIn('branch_id', $branchIds);
        }

        return $query;
    }

    /**
     * @return array<int, int>
     */
    private static function parseBranchIds(?string $branchIds): array
    {
        if ($branchIds === null || trim($branchIds) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($id) => (int) trim($id),
            explode(',', $branchIds)
        ), static fn ($id) => $id > 0));
    }

    /**
     * @return array<string, mixed>
     */
    private static function settingsSnapshot(OrderAutomationSetting $settings, bool $manualRun): array
    {
        return [
            'manual_run' => $manualRun,
            'is_enabled' => (bool) $settings->is_enabled,
            'auto_complete_hours' => (int) $settings->auto_complete_hours,
            'eligible_statuses' => $settings->eligible_statuses,
            'excluded_statuses' => $settings->excluded_statuses,
            'branch_ids' => $settings->branch_ids,
            'dry_run' => (bool) $settings->dry_run,
            'require_delivery_man' => (bool) $settings->require_delivery_man,
        ];
    }
}
