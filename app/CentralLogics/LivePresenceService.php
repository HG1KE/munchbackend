<?php

namespace App\CentralLogics;

use App\Model\LivePresence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LivePresenceService
{
    public const VISITOR_WINDOW_MINUTES = 5;

    public const CART_WINDOW_MINUTES = 10;

    public const CHECKOUT_WINDOW_MINUTES = 10;

    /** @var list<string> */
    public const ALLOWED_STATES = [
        LivePresence::STATE_BROWSING,
        LivePresence::STATE_CART_ACTIVE,
        LivePresence::STATE_CHECKOUT,
    ];

    /**
     * Upsert a single presence row per client_token.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function recordPing(array $payload): ?LivePresence
    {
        $clientToken = isset($payload['client_token'])
            ? substr(trim((string) $payload['client_token']), 0, 64)
            : '';

        if ($clientToken === '') {
            return null;
        }

        $state = isset($payload['state']) ? strtolower(trim((string) $payload['state'])) : LivePresence::STATE_BROWSING;
        if (! in_array($state, self::ALLOWED_STATES, true)) {
            $state = LivePresence::STATE_BROWSING;
        }

        $branchId = isset($payload['branch_id']) && $payload['branch_id'] !== '' && $payload['branch_id'] !== null
            ? (int) $payload['branch_id']
            : null;

        $currentPath = isset($payload['current_path'])
            ? substr(trim((string) $payload['current_path']), 0, 255)
            : null;

        $userId = isset($payload['user_id']) && $payload['user_id'] !== '' && $payload['user_id'] !== null
            ? (int) $payload['user_id']
            : null;

        $now = Carbon::now();

        return LivePresence::query()->updateOrCreate(
            ['client_token' => $clientToken],
            [
                'user_id' => $userId,
                'branch_id' => $branchId > 0 ? $branchId : null,
                'current_state' => $state,
                'current_path' => $currentPath !== '' ? $currentPath : null,
                'last_seen_at' => $now,
            ]
        );
    }

    /**
     * @return array{active_visitors: int, active_carts: int, active_checkouts: int, generated_at: string}
     */
    public static function adminStats(): array
    {
        $visitorCutoff = Carbon::now()->subMinutes(self::VISITOR_WINDOW_MINUTES);
        $cartCutoff = Carbon::now()->subMinutes(self::CART_WINDOW_MINUTES);
        $checkoutCutoff = Carbon::now()->subMinutes(self::CHECKOUT_WINDOW_MINUTES);

        $activeVisitors = (int) DB::table('live_presence')
            ->where('last_seen_at', '>=', $visitorCutoff)
            ->count(DB::raw('DISTINCT client_token'));

        $activeCarts = (int) DB::table('live_presence')
            ->where('current_state', LivePresence::STATE_CART_ACTIVE)
            ->where('last_seen_at', '>=', $cartCutoff)
            ->count();

        $activeCheckouts = (int) DB::table('live_presence')
            ->where('current_state', LivePresence::STATE_CHECKOUT)
            ->where('last_seen_at', '>=', $checkoutCutoff)
            ->count();

        return [
            'active_visitors' => $activeVisitors,
            'active_carts' => $activeCarts,
            'active_checkouts' => $activeCheckouts,
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    public static function pruneStale(int $olderThanHours = 24): int
    {
        $cutoff = Carbon::now()->subHours($olderThanHours);

        return LivePresence::query()
            ->where('last_seen_at', '<', $cutoff)
            ->delete();
    }
}
