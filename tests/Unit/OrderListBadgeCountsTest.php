<?php

namespace Tests\Unit;

use App\Support\OrderListBadgeCounts;
use PHPUnit\Framework\TestCase;

class OrderListBadgeCountsTest extends TestCase
{
    public function test_status_list_covers_admin_badges(): void
    {
        $this->assertSame(
            [
                'pending',
                'confirmed',
                'processing',
                'out_for_delivery',
                'delivered',
                'canceled',
                'returned',
                'failed',
            ],
            OrderListBadgeCounts::STATUSES
        );
    }
}
