<?php

namespace App\Services\MunchOrdersWebhook;

/**
 * Grok Bot webhook branch scope for portal.munch.co.ke.
 *
 * Authoritative mapping uses `branches.id` from production:
 * 1 Nyali, 10 Bamburi, 11 Makadara (excluded), 13 Mtwapa, 14 Kilimani.
 */
class MunchOrderWebhookBranch
{
    public const MAKADARA_BRANCH_ID = 11;

    /**
     * @return array<int, string> branch_id => Grok payload label
     */
    public static function idToWebhookLabel(): array
    {
        return [
            1 => 'Nyali',
            10 => 'Bamburi',
            13 => 'Mtwapa',
            14 => 'Kilimani',
        ];
    }

    public static function qualifiesBranchId(?int $branchId): bool
    {
        if ($branchId === null || $branchId < 1) {
            return false;
        }

        if ($branchId === self::MAKADARA_BRANCH_ID) {
            return false;
        }

        return array_key_exists($branchId, self::idToWebhookLabel());
    }

    public static function webhookLabelForBranchId(?int $branchId): ?string
    {
        if (! self::qualifiesBranchId($branchId)) {
            return null;
        }

        return self::idToWebhookLabel()[(int) $branchId];
    }

    /**
     * Fallback when only branch name is available (dry-run / legacy rows).
     */
    public static function webhookLabelFromBranchName(?string $branchName): ?string
    {
        $normalized = strtolower(trim((string) $branchName));
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, 'makadara')) {
            return null;
        }

        foreach (self::idToWebhookLabel() as $label) {
            if (str_contains($normalized, strtolower($label))) {
                return $label;
            }
        }

        return null;
    }
}
