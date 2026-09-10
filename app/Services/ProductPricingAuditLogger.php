<?php

namespace App\Services;

use App\Model\ProductPriceAuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;

class ProductPricingAuditLogger
{
    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function record(array $entries, string $source = 'drawer'): void
    {
        if ($entries === [] || ! Schema::hasTable('product_price_audit_logs')) {
            return;
        }

        $adminId = Auth::guard('admin')->id();
        $branchActorId = Auth::guard('branch')->id();
        $now = now();
        $ip = Request::ip();
        $rows = [];

        foreach ($entries as $entry) {
            $old = $this->stringify($entry['old_value'] ?? null);
            $new = $this->stringify($entry['new_value'] ?? null);
            if ($old === $new) {
                continue;
            }

            $row = [
                'admin_id' => $adminId,
                'actor_type' => $adminId ? 'admin' : ($branchActorId ? 'branch' : 'system'),
                'actor_id' => $adminId ?: $branchActorId,
                'product_id' => (int) ($entry['product_id'] ?? 0),
                'branch_id' => isset($entry['branch_id']) ? (int) $entry['branch_id'] : null,
                'channel' => (string) ($entry['channel'] ?? 'pos'),
                'field' => (string) ($entry['field'] ?? 'price'),
                'old_value' => $old,
                'new_value' => $new,
                'source' => $source,
                'ip_address' => $ip,
                'created_at' => $now,
            ];
            if (Schema::hasColumn('product_price_audit_logs', 'source_branch_id')) {
                $row['source_branch_id'] = isset($entry['source_branch_id']) ? (int) $entry['source_branch_id'] : null;
            }
            $rows[] = $row;
        }

        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            ProductPriceAuditLog::query()->insert($chunk);
        }
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
