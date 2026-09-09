<?php

namespace App\Services;

use App\Model\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OrderReadableIdService
{
    private const MIN_NUMBER = 10001;

    private const MAX_NUMBER = 99999;

    private const SEQUENCE_ROW_ID = 1;

    /**
     * Assign the next readable ID to an existing order (concurrency-safe).
     */
    public function assignToExistingOrder(int $orderId): ?string
    {
        $order = Order::query()->find($orderId);
        if (! $order) {
            return null;
        }

        if (! empty($order->readable_order_id)) {
            return $order->readable_order_id;
        }

        return DB::transaction(function () use ($orderId): string {
            $readableId = $this->reserveNextReadableId();

            $updated = Order::query()
                ->where('id', $orderId)
                ->whereNull('readable_order_id')
                ->update(['readable_order_id' => $readableId]);

            if ($updated === 0) {
                $existing = Order::query()->where('id', $orderId)->value('readable_order_id');
                if (is_string($existing) && $existing !== '') {
                    return $existing;
                }

                throw new RuntimeException('Failed to assign readable order id for order '.$orderId);
            }

            Log::info('order.readable_id.assigned', [
                'order_id' => $orderId,
                'readable_order_id' => $readableId,
            ]);

            return $readableId;
        });
    }

    public function reserveNextReadableId(): string
    {
        return DB::transaction(function (): string {
            $row = DB::table('order_readable_id_sequences')
                ->where('id', self::SEQUENCE_ROW_ID)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                throw new RuntimeException('Order readable ID sequence row is missing.');
            }

            [$letter, $number] = $this->increment($row->letter, (int) $row->last_number);

            DB::table('order_readable_id_sequences')
                ->where('id', self::SEQUENCE_ROW_ID)
                ->update([
                    'letter' => $letter,
                    'last_number' => $number,
                    'updated_at' => now(),
                ]);

            return $this->format($letter, $number);
        });
    }

    /**
     * @return array{0: string, 1: int}
     */
    public function increment(string $letter, int $number): array
    {
        $letter = strtoupper($letter);
        if (! preg_match('/^[A-Z]$/', $letter)) {
            $letter = 'A';
        }

        if ($number < self::MIN_NUMBER - 1) {
            $number = self::MIN_NUMBER - 1;
        }

        $number++;

        if ($number > self::MAX_NUMBER) {
            $nextLetter = chr(ord($letter) + 1);
            if ($nextLetter > 'Z') {
                throw new RuntimeException('Readable order ID series exhausted (Z99999).');
            }
            $letter = $nextLetter;
            $number = self::MIN_NUMBER;
        }

        return [$letter, $number];
    }

    public function format(string $letter, int $number): string
    {
        return strtoupper($letter).str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }

    public function parse(string $readableId): ?array
    {
        $normalized = strtoupper(trim(ltrim($readableId, '#')));
        if (! preg_match('/^([A-Z])(\d{5})$/', $normalized, $matches)) {
            return null;
        }

        $number = (int) $matches[2];
        if ($number < self::MIN_NUMBER || $number > self::MAX_NUMBER) {
            return null;
        }

        return [
            'letter' => $matches[1],
            'number' => $number,
            'formatted' => $this->format($matches[1], $number),
        ];
    }

    public static function applyTerm(Builder $query, string $term): Builder
    {
        $normalized = strtoupper(ltrim(trim($term), '#'));

        return $query->orWhere('id', 'like', '%'.$term.'%')
            ->orWhere('readable_order_id', 'like', '%'.$normalized.'%')
            ->orWhere('order_status', 'like', '%'.$term.'%')
            ->orWhere('transaction_reference', 'like', '%'.$term.'%');
    }

    public static function applySearch(Builder $query, ?string $search): Builder
    {
        if ($search === null || trim($search) === '') {
            return $query;
        }

        $terms = preg_split('/\s+/', trim($search)) ?: [];

        return $query->where(function (Builder $outer) use ($terms): void {
            foreach ($terms as $term) {
                $term = trim((string) $term);
                if ($term === '') {
                    continue;
                }

                $outer->where(function (Builder $inner) use ($term): void {
                    self::applyTerm($inner, $term);
                });
            }
        });
    }
}
