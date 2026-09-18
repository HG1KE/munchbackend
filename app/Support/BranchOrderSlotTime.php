<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Splits customer ordering availability from kitchen ready-time.
 *
 * Eligibility uses the customer's requested/current time against the branch
 * scheduled slot. Preparation minutes are applied only after that check,
 * when computing the stored kitchen ready-time.
 */
class BranchOrderSlotTime
{
    /**
     * @return array{date: string, time: string}
     */
    public static function customerSlot(string $deliveryTime, string $deliveryDate, ?CarbonInterface $now = null): array
    {
        $now = Carbon::instance($now ?? Carbon::now());

        if ($deliveryTime === 'now') {
            return [
                'date' => $now->format('Y-m-d'),
                'time' => $now->format('H:i:s'),
            ];
        }

        return [
            'date' => $deliveryDate,
            'time' => Carbon::parse($deliveryTime)->format('H:i:s'),
        ];
    }

    public static function kitchenReadyTime(string $customerSlotTime, int $preparationMinutes): string
    {
        return Carbon::parse($customerSlotTime)
            ->add(max(0, $preparationMinutes), 'minute')
            ->format('H:i:s');
    }
}
