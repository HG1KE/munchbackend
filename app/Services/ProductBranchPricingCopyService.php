<?php

namespace App\Services;

use App\Support\ProductPricingChannels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProductBranchPricingCopyService
{
    public const MODE_OVERWRITE = 'overwrite';

    public const MODE_FILL_MISSING = 'fill_missing';

    public const MODE_SKIP_EXISTING = 'skip_existing';

    public const SOURCE = 'copy_branch_pricing';

    public const CHUNK = 400;

    public function __construct(
        private ProductPricingAuditLogger $auditLogger,
        private ProductChannelPricingService $pricing,
    ) {
    }

    /**
     * @param  list<int>  $destinationIds
     * @param  list<string>  $priceChannels
     * @param  list<string>  $availabilityChannels
     * @return array<string, mixed>
     */
    public function preview(int $sourceId, array $destinationIds, array $priceChannels, array $availabilityChannels, string $mode): array
    {
        $plan = $this->plan($sourceId, $destinationIds, $priceChannels, $availabilityChannels, $mode);
        if (! empty($plan['error'])) {
            return $plan;
        }

        return $this->publicPlan($plan);
    }

    /**
     * @param  list<int>  $destinationIds
     * @param  list<string>  $priceChannels
     * @param  list<string>  $availabilityChannels
     * @return array<string, mixed>
     */
    public function apply(int $sourceId, array $destinationIds, array $priceChannels, array $availabilityChannels, string $mode): array
    {
        $plan = $this->plan($sourceId, $destinationIds, $priceChannels, $availabilityChannels, $mode);
        if (! empty($plan['error'])) {
            return $plan;
        }

        try {
            DB::transaction(function () use ($plan, $sourceId, $destinationIds) {
                $this->persist($plan, $sourceId, $destinationIds);
            });
        } catch (Throwable $e) {
            return ['error' => translate('Pricing copy failed and was rolled back')];
        }

        foreach ($destinationIds as $destId) {
            $this->pricing->bustCache(null, (int) $destId);
        }

        return [
            'copied' => true,
            'products_updated' => $plan['products_affected'],
            'branches_updated' => count($plan['destinations']),
            'rows_affected' => $plan['rows_affected'],
            'prices_changing' => $plan['prices_changing'],
            'availability_changing' => $plan['availability_changing'],
            'message' => translate('Copied'),
        ];
    }

    /**
     * @param  list<int>  $destinationIds
     * @param  list<string>  $priceChannels
     * @param  list<string>  $availabilityChannels
     * @return array<string, mixed>
     */
    public function plan(int $sourceId, array $destinationIds, array $priceChannels, array $availabilityChannels, string $mode): array
    {
        $destinationIds = array_values(array_unique(array_map('intval', $destinationIds)));
        $priceChannels = $this->normalizeChannels($priceChannels);
        $availabilityChannels = $this->normalizeChannels($availabilityChannels);
        $mode = $this->normalizeMode($mode);

        if ($sourceId < 1) {
            return ['error' => translate('Select a source branch')];
        }
        if ($destinationIds === []) {
            return ['error' => translate('Select at least one destination branch')];
        }
        if (in_array($sourceId, $destinationIds, true)) {
            return ['error' => translate('Destination branches cannot include the source branch')];
        }
        if ($priceChannels === [] && $availabilityChannels === []) {
            return ['error' => translate('Choose at least one price or availability field to copy')];
        }
        if (! $this->pricing->tableReady()) {
            return ['error' => translate('Pricing tables are not ready')];
        }

        $neededChannels = array_values(array_unique(array_merge($priceChannels, $availabilityChannels)));
        $branchNames = DB::table('branches')->whereIn('id', array_merge([$sourceId], $destinationIds))->pluck('name', 'id');
        if (! $branchNames->has($sourceId)) {
            return ['error' => translate('Select a source branch')];
        }
        foreach ($destinationIds as $destId) {
            if (! $branchNames->has($destId)) {
                return ['error' => translate('Select at least one destination branch')];
            }
        }

        $sourceRows = $this->sourceRows($sourceId, $neededChannels, $priceChannels, $availabilityChannels);
        if ($sourceRows === []) {
            return ['error' => translate('Nothing to copy for this selection')];
        }

        $productIds = array_values(array_unique(array_map(fn ($row) => (int) $row['product_id'], $sourceRows)));
        $destRows = $this->destinationRows($destinationIds, $productIds, $neededChannels);
        $defaults = DB::table('products')->whereIn('id', $productIds)->pluck('price', 'id');
        $destPbb = [];
        if (in_array(ProductPricingChannels::POS, $neededChannels, true) && Schema::hasTable('product_by_branches')) {
            $pbbRows = DB::table('product_by_branches')
                ->whereIn('branch_id', $destinationIds)
                ->whereIn('product_id', $productIds)
                ->get();
            foreach ($pbbRows as $row) {
                $destPbb[$row->branch_id.':'.$row->product_id] = $row;
            }
        }

        $upserts = [];
        $audits = [];
        $posProductIds = [];
        $perDest = [];
        foreach ($destinationIds as $destId) {
            $perDest[$destId] = [
                'branch_id' => $destId,
                'branch_name' => (string) $branchNames[$destId],
                'products' => [],
                'rows_affected' => 0,
                'prices_changing' => 0,
                'availability_changing' => 0,
            ];
        }

        foreach ($destinationIds as $destId) {
            foreach ($sourceRows as $source) {
                $productId = (int) $source['product_id'];
                $channel = (string) $source['channel'];
                $dest = $destRows[$destId][$productId][$channel] ?? null;
                $copyPrice = in_array($channel, $priceChannels, true);
                $copyAvail = in_array($channel, $availabilityChannels, true);
                $priceChange = $copyPrice && $this->shouldCopyPrice($mode, $source, $dest);
                $availChange = $copyAvail && $this->shouldCopyAvailability($mode, $source, $dest);
                if (! $priceChange && ! $availChange) {
                    continue;
                }

                $nextPrice = $dest['price'] ?? null;
                $nextAvail = $dest['is_available'] ?? 1;
                $oldPrice = $dest['price'] ?? null;
                $oldAvail = $dest === null ? null : (int) $dest['is_available'];

                if ($priceChange) {
                    $nextPrice = $source['price'];
                } elseif ($dest === null && $channel === ProductPricingChannels::POS) {
                    $pbb = $destPbb[$destId.':'.$productId] ?? null;
                    $default = (float) ($defaults[$productId] ?? 0);
                    $nextPrice = ($pbb && abs((float) $pbb->price - $default) > 0.009)
                        ? (float) $pbb->price
                        : null;
                } elseif ($dest === null) {
                    $nextPrice = null;
                }
                if ($availChange) {
                    $nextAvail = (int) $source['is_available'];
                } elseif ($dest === null) {
                    $nextAvail = (int) ($source['is_available'] ?? 1);
                }

                $priceActuallyChanges = $priceChange && ! $this->samePrice($oldPrice, $nextPrice);
                $availActuallyChanges = $availChange && (int) $oldAvail !== (int) $nextAvail;
                if (! $priceActuallyChanges && ! $availActuallyChanges && $dest !== null) {
                    continue;
                }

                $upserts[] = [
                    'product_id' => $productId,
                    'branch_id' => $destId,
                    'channel' => $channel,
                    'price' => $nextPrice,
                    'is_available' => $nextAvail ? 1 : 0,
                ];

                if ($priceActuallyChanges || ($priceChange && $dest === null)) {
                    $audits[] = [
                        'product_id' => $productId,
                        'branch_id' => $destId,
                        'source_branch_id' => $sourceId,
                        'channel' => $channel,
                        'field' => 'price',
                        'old_value' => $oldPrice,
                        'new_value' => $nextPrice,
                    ];
                    $perDest[$destId]['prices_changing']++;
                }
                if ($availActuallyChanges || ($availChange && $dest === null && $oldAvail === null)) {
                    $audits[] = [
                        'product_id' => $productId,
                        'branch_id' => $destId,
                        'source_branch_id' => $sourceId,
                        'channel' => $channel,
                        'field' => 'is_available',
                        'old_value' => $oldAvail,
                        'new_value' => $nextAvail ? 1 : 0,
                    ];
                    $perDest[$destId]['availability_changing']++;
                }

                $perDest[$destId]['rows_affected']++;
                $perDest[$destId]['products'][$productId] = true;
                if ($channel === ProductPricingChannels::POS) {
                    $posProductIds[$productId] = true;
                }
            }
        }

        $destinations = [];
        $productSet = [];
        $rows = 0;
        $prices = 0;
        $avails = 0;
        foreach ($perDest as $row) {
            if ($row['rows_affected'] === 0) {
                continue;
            }
            $productCount = count($row['products']);
            foreach (array_keys($row['products']) as $productId) {
                $productSet[$productId] = true;
            }
            $rows += $row['rows_affected'];
            $prices += $row['prices_changing'];
            $avails += $row['availability_changing'];
            $destinations[] = [
                'branch_id' => $row['branch_id'],
                'branch_name' => $row['branch_name'],
                'products_affected' => $productCount,
                'rows_affected' => $row['rows_affected'],
                'prices_changing' => $row['prices_changing'],
                'availability_changing' => $row['availability_changing'],
            ];
        }

        if ($upserts === []) {
            return ['error' => translate('Nothing to copy for this selection')];
        }

        return [
            'source_branch_id' => $sourceId,
            'source_branch_name' => (string) $branchNames[$sourceId],
            'mode' => $mode,
            'destinations' => $destinations,
            'products_affected' => count($productSet),
            'rows_affected' => $rows,
            'prices_changing' => $prices,
            'availability_changing' => $avails,
            'upserts' => $upserts,
            'audits' => $audits,
            'pos_product_ids' => array_map('intval', array_keys($posProductIds)),
            'copy_pos_price' => in_array(ProductPricingChannels::POS, $priceChannels, true),
            'copy_pos_availability' => in_array(ProductPricingChannels::POS, $availabilityChannels, true),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<int>  $destinationIds
     */
    private function persist(array $plan, int $sourceId, array $destinationIds): void
    {
        $now = now();
        foreach (array_chunk($plan['upserts'], self::CHUNK) as $chunk) {
            $rows = [];
            foreach ($chunk as $row) {
                $rows[] = [
                    'product_id' => $row['product_id'],
                    'branch_id' => $row['branch_id'],
                    'channel' => $row['channel'],
                    'price' => $row['price'],
                    'is_available' => $row['is_available'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('product_channel_prices')->upsert(
                $rows,
                ['product_id', 'branch_id', 'channel'],
                ['price', 'is_available', 'updated_at']
            );
        }

        if (($plan['copy_pos_price'] || $plan['copy_pos_availability']) && $plan['pos_product_ids'] !== []) {
            $this->syncPosBranchRows(
                $sourceId,
                $destinationIds,
                $plan['pos_product_ids'],
                (bool) $plan['copy_pos_price'],
                (bool) $plan['copy_pos_availability']
            );
        }

        $this->auditLogger->record($plan['audits'], self::SOURCE);
    }

    /**
     * @param  list<int>  $destinationIds
     * @param  list<int>  $productIds
     */
    private function syncPosBranchRows(int $sourceId, array $destinationIds, array $productIds, bool $copyPrice, bool $copyAvail): void
    {
        foreach (array_chunk($productIds, self::CHUNK) as $chunk) {
            $this->insertMissingBranchProducts($sourceId, $destinationIds, $chunk);
            $this->updateDestinationPosFromChannel($destinationIds, $chunk, $copyPrice, $copyAvail);
        }
    }

    /**
     * @param  list<int>  $destinationIds
     * @param  list<int>  $productIds
     */
    private function insertMissingBranchProducts(int $sourceId, array $destinationIds, array $productIds): void
    {
        $existing = DB::table('product_by_branches')
            ->whereIn('branch_id', $destinationIds)
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'branch_id']);
        $have = [];
        foreach ($existing as $row) {
            $have[$row->branch_id.':'.$row->product_id] = true;
        }

        $sources = DB::table('product_by_branches')
            ->where('branch_id', $sourceId)
            ->whereIn('product_id', $productIds)
            ->get();
        if ($sources->isEmpty()) {
            return;
        }

        $now = now();
        $inserts = [];
        foreach ($destinationIds as $destId) {
            foreach ($sources as $src) {
                if (isset($have[$destId.':'.$src->product_id])) {
                    continue;
                }
                $inserts[] = [
                    'product_id' => $src->product_id,
                    'branch_id' => $destId,
                    'price' => $src->price,
                    'discount_type' => $src->discount_type ?? 'percent',
                    'discount' => $src->discount ?? 0,
                    'is_available' => $src->is_available ?? 1,
                    'variations' => $src->variations,
                    'stock_type' => $src->stock_type ?? 'unlimited',
                    'stock' => $src->stock ?? 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($inserts, 200) as $part) {
            DB::table('product_by_branches')->insert($part);
        }
    }

    /**
     * @param  list<int>  $destinationIds
     * @param  list<int>  $productIds
     */
    private function updateDestinationPosFromChannel(array $destinationIds, array $productIds, bool $copyPrice, bool $copyAvail): void
    {
        $channels = DB::table('product_channel_prices')
            ->whereIn('branch_id', $destinationIds)
            ->whereIn('product_id', $productIds)
            ->where('channel', ProductPricingChannels::POS)
            ->get();
        if ($channels->isEmpty()) {
            return;
        }

        $defaults = DB::table('products')->whereIn('id', $productIds)->pluck('price', 'id');

        foreach ($destinationIds as $destId) {
            $rows = $channels->where('branch_id', $destId);
            if ($rows->isEmpty()) {
                continue;
            }
            $ids = [];
            $priceSql = 'CASE product_id';
            $availSql = 'CASE product_id';
            foreach ($rows as $row) {
                $productId = (int) $row->product_id;
                $ids[] = $productId;
                $price = $row->price !== null
                    ? (float) $row->price
                    : (float) ($defaults[$productId] ?? 0);
                $priceSql .= ' WHEN '.$productId.' THEN '.$this->pricing->money($price);
                $availSql .= ' WHEN '.$productId.' THEN '.(int) $row->is_available;
            }
            $priceSql .= ' END';
            $availSql .= ' END';

            $update = ['updated_at' => now()];
            if ($copyPrice) {
                $update['price'] = DB::raw($priceSql);
            }
            if ($copyAvail) {
                $update['is_available'] = DB::raw($availSql);
            }
            DB::table('product_by_branches')
                ->where('branch_id', $destId)
                ->whereIn('product_id', $ids)
                ->update($update);
        }
    }

    /**
     * @param  list<string>  $channels
     * @param  list<string>  $priceChannels
     * @param  list<string>  $availabilityChannels
     * @return list<array{product_id: int, channel: string, price: float|null, is_available: int}>
     */
    private function sourceRows(int $sourceId, array $channels, array $priceChannels, array $availabilityChannels): array
    {
        $rows = DB::table('product_channel_prices')
            ->where('branch_id', $sourceId)
            ->whereIn('channel', $channels)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->product_id.':'.$row->channel] = [
                'product_id' => (int) $row->product_id,
                'channel' => (string) $row->channel,
                'price' => $row->price === null ? null : (float) $row->price,
                'is_available' => (int) $row->is_available,
            ];
        }

        $needsPos = in_array(ProductPricingChannels::POS, $channels, true);
        if ($needsPos) {
            $defaults = DB::table('products')->pluck('price', 'id');
            $branchProducts = DB::table('product_by_branches')->where('branch_id', $sourceId)->get();
            foreach ($branchProducts as $row) {
                $key = $row->product_id.':'.ProductPricingChannels::POS;
                if (isset($out[$key])) {
                    continue;
                }
                $default = (float) ($defaults[$row->product_id] ?? 0);
                $hasPriceOverride = abs((float) $row->price - $default) > 0.009;
                $copyPrice = in_array(ProductPricingChannels::POS, $priceChannels, true) && $hasPriceOverride;
                $copyAvail = in_array(ProductPricingChannels::POS, $availabilityChannels, true);
                if (! $copyPrice && ! $copyAvail) {
                    continue;
                }
                $out[$key] = [
                    'product_id' => (int) $row->product_id,
                    'channel' => ProductPricingChannels::POS,
                    'price' => $copyPrice ? (float) $row->price : null,
                    'is_available' => (int) $row->is_available,
                ];
            }
        }

        return array_values($out);
    }

    /**
     * @param  list<int>  $destinationIds
     * @param  list<int>  $productIds
     * @param  list<string>  $channels
     * @return array<int, array<int, array<string, array{price: float|null, is_available: int}>>>
     */
    private function destinationRows(array $destinationIds, array $productIds, array $channels): array
    {
        $rows = DB::table('product_channel_prices')
            ->whereIn('branch_id', $destinationIds)
            ->whereIn('product_id', $productIds)
            ->whereIn('channel', $channels)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->branch_id][(int) $row->product_id][(string) $row->channel] = [
                'price' => $row->price === null ? null : (float) $row->price,
                'is_available' => (int) $row->is_available,
            ];
        }

        return $out;
    }

    /**
     * @param  array{price: float|null, is_available?: int}  $source
     * @param  array{price: float|null, is_available: int}|null  $dest
     */
    private function shouldCopyPrice(string $mode, array $source, ?array $dest): bool
    {
        $destHasOverride = $dest !== null && $dest['price'] !== null;

        return match ($mode) {
            self::MODE_OVERWRITE => true,
            self::MODE_SKIP_EXISTING => $dest === null,
            default => ! $destHasOverride && $source['price'] !== null,
        };
    }

    /**
     * @param  array{price: float|null, is_available: int}  $source
     * @param  array{price: float|null, is_available: int}|null  $dest
     */
    private function shouldCopyAvailability(string $mode, array $source, ?array $dest): bool
    {
        if ($mode === self::MODE_OVERWRITE) {
            return true;
        }
        if ($mode === self::MODE_SKIP_EXISTING) {
            return $dest === null;
        }

        return $dest === null;
    }

    private function samePrice(mixed $left, mixed $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }
        if ($left === null || $right === null) {
            return false;
        }

        return abs((float) $left - (float) $right) <= 0.009;
    }

    /**
     * @param  list<string>|mixed  $channels
     * @return list<string>
     */
    private function normalizeChannels(mixed $channels): array
    {
        if (! is_array($channels)) {
            return [];
        }
        $out = [];
        foreach ($channels as $channel) {
            if (ProductPricingChannels::isOverrideChannel((string) $channel)) {
                $out[] = (string) $channel;
            }
        }

        return array_values(array_unique($out));
    }

    private function normalizeMode(string $mode): string
    {
        return in_array($mode, [self::MODE_OVERWRITE, self::MODE_FILL_MISSING, self::MODE_SKIP_EXISTING], true)
            ? $mode
            : self::MODE_SKIP_EXISTING;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function publicPlan(array $plan): array
    {
        unset($plan['upserts'], $plan['audits'], $plan['pos_product_ids'], $plan['copy_pos_price'], $plan['copy_pos_availability']);

        return $plan;
    }
}
