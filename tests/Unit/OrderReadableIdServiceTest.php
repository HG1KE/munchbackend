<?php

namespace Tests\Unit;

use App\Services\OrderReadableIdService;
use App\Support\OrderPublicNumber;
use RuntimeException;
use Tests\TestCase;

class OrderReadableIdServiceTest extends TestCase
{
    public function test_format_matches_spec(): void
    {
        $service = new OrderReadableIdService;

        $this->assertSame('A10001', $service->format('A', 10001));
        $this->assertSame('A99999', $service->format('a', 99999));
        $this->assertSame('B10001', $service->format('B', 10001));
    }

    public function test_increment_starts_at_a10001(): void
    {
        $service = new OrderReadableIdService;

        $this->assertSame(['A', 10001], $service->increment('A', 10000));
    }

    public function test_increment_rolls_letter_after_99999(): void
    {
        $service = new OrderReadableIdService;

        $this->assertSame(['B', 10001], $service->increment('A', 99999));
        $this->assertSame(['C', 10001], $service->increment('B', 99999));
    }

    public function test_increment_exhausts_after_z99999(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Z99999');

        (new OrderReadableIdService)->increment('Z', 99999);
    }

    public function test_parse_accepts_canonical_and_hashed_values(): void
    {
        $service = new OrderReadableIdService;

        $this->assertSame('A10001', $service->parse('#a10001')['formatted']);
        $this->assertNull($service->parse('6700067'));
        $this->assertNull($service->parse('A00001'));
    }

    public function test_public_number_normalizes_readable_ids(): void
    {
        $this->assertSame('A10084', OrderPublicNumber::normalizeReadable('a10084'));
        $this->assertSame('A10084', OrderPublicNumber::normalizeReadable('#A10084'));
        $this->assertNull(OrderPublicNumber::normalizeReadable('113814439'));
    }
}
