<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Support\OrderDispatchedTime;
use App\Support\OrderPlacementTime;
use App\Support\OrderViewBootstrap;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OnlineOrdersTimerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('readable_order_id')->nullable();
            $table->boolean('is_guest')->default(true);
            $table->string('order_status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->string('order_type')->nullable();
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
        });

        OrderPlacementTime::resetColumnCache();
        OrderDispatchedTime::resetColumnCache();
    }

    public function test_sla_thresholds_match_meatco(): void
    {
        $this->assertSame(1800, OrderPlacementTime::SLA_DELAY_SECONDS);
        $this->assertSame(2700, OrderPlacementTime::SLA_ESCALATED_SECONDS);
        $this->assertSame(3600, OrderPlacementTime::SLA_CRITICAL_SECONDS);
        $this->assertSame('normal', OrderPlacementTime::slaTier(0));
        $this->assertSame('normal', OrderPlacementTime::slaTier(1799));
        $this->assertSame('delay', OrderPlacementTime::slaTier(1800));
        $this->assertSame('escalated', OrderPlacementTime::slaTier(2700));
        $this->assertSame('critical', OrderPlacementTime::slaTier(3600));
        $this->assertSame('meatco-express-card--sla-delay', OrderPlacementTime::slaCardClass(1800));
        $this->assertSame('meatco-express-card--sla-escalated', OrderPlacementTime::slaCardClass(2700));
        $this->assertSame('meatco-express-card--sla-critical', OrderPlacementTime::slaCardClass(3600));
    }

    public function test_timer_falls_back_to_created_at_when_placed_at_is_empty(): void
    {
        $created = '2026-06-30 09:00:00';
        $order = $this->onlineOrder('pending', [
            'placed_at' => null,
            'created_at' => $created,
        ]);

        $this->assertSame(OrderPlacementTime::unixFromRaw($created), OrderPlacementTime::unix($order));
    }

    public function test_active_online_order_runs_a_live_incrementing_timer(): void
    {
        $placed = gmdate('Y-m-d H:i:s', time() - 90);
        $order = $this->onlineOrder('pending', ['placed_at' => $placed, 'created_at' => $placed]);

        $ctx = OrderViewBootstrap::expressTimerContext($order);

        $this->assertFalse($ctx['timer_frozen']);
        $this->assertSame('live', $ctx['timer_state']);
        $this->assertTrue($ctx['show_timer']);
        $this->assertGreaterThan(0, $ctx['elapsed_seconds']);

        $placedUnix = OrderPlacementTime::unix($order);
        $this->assertSame(100, OrderPlacementTime::elapsedSeconds($order, $placedUnix + 100));
        $this->assertSame(160, OrderPlacementTime::elapsedSeconds($order, $placedUnix + 160));
    }

    public function test_processing_order_still_runs_live_timer(): void
    {
        $placed = gmdate('Y-m-d H:i:s', time() - 300);
        $order = $this->onlineOrder('processing', ['placed_at' => $placed, 'created_at' => $placed]);

        $ctx = OrderViewBootstrap::expressTimerContext($order);

        $this->assertFalse($ctx['timer_frozen']);
        $this->assertSame('live', $ctx['timer_state']);
        $this->assertTrue($ctx['show_timer']);
    }

    public function test_out_for_delivery_freezes_the_prep_to_dispatch_timer(): void
    {
        $placed = '2026-06-30 09:00:00';
        $dispatched = '2026-06-30 09:25:00';
        $order = $this->onlineOrder('out_for_delivery', [
            'placed_at' => $placed,
            'created_at' => $placed,
            'dispatched_at' => $dispatched,
            'updated_at' => $dispatched,
        ]);

        $ctx = OrderViewBootstrap::expressTimerContext($order);

        $this->assertTrue($ctx['timer_frozen']);
        $this->assertSame('dispatched', $ctx['timer_state']);
        $this->assertTrue($ctx['show_timer']);
        $this->assertSame(1500, $ctx['elapsed_seconds']);
        $this->assertSame('25:00', $ctx['elapsed_display']);
    }

    public function test_delivered_order_shows_final_fulfillment_duration_and_stops(): void
    {
        $placed = '2026-06-30 09:00:00';
        $delivered = '2026-06-30 10:12:00';
        $order = $this->onlineOrder('delivered', [
            'placed_at' => $placed,
            'created_at' => $placed,
            'updated_at' => $delivered,
        ]);

        $ctx = OrderViewBootstrap::expressTimerContext($order);

        $this->assertTrue($ctx['timer_frozen']);
        $this->assertSame('completed', $ctx['timer_state']);
        $this->assertTrue($ctx['show_timer']);
        $this->assertSame(72 * 60, $ctx['elapsed_seconds']);
        $this->assertSame('1:12:00', $ctx['elapsed_display']);
    }

    public function test_delivered_order_without_reliable_timestamp_hides_the_timer(): void
    {
        $placed = '2026-06-30 09:00:00';
        $order = $this->onlineOrder('delivered', [
            'placed_at' => $placed,
            'created_at' => $placed,
            'updated_at' => null,
        ]);

        $ctx = OrderViewBootstrap::expressTimerContext($order);

        $this->assertFalse($ctx['show_timer']);
        $this->assertSame('hidden', $ctx['timer_state']);
    }

    /**
     * @dataProvider terminalNonDeliveredStatuses
     */
    public function test_terminal_statuses_do_not_run_a_live_timer(string $status): void
    {
        $placed = gmdate('Y-m-d H:i:s', time() - 3600);
        $order = $this->onlineOrder($status, ['placed_at' => $placed, 'created_at' => $placed]);

        $ctx = OrderViewBootstrap::expressTimerContext($order);

        $this->assertFalse($ctx['show_timer'], "{$status} must hide the timer card");
        $this->assertSame('hidden', $ctx['timer_state']);
    }

    public static function terminalNonDeliveredStatuses(): array
    {
        return [
            'canceled' => ['canceled'],
            'returned' => ['returned'],
            'failed' => ['failed'],
        ];
    }

    public function test_pos_and_dine_in_orders_hide_the_timer(): void
    {
        $placed = gmdate('Y-m-d H:i:s', time() - 90);
        $pos = $this->onlineOrder('pending', [
            'order_type' => 'pos',
            'placed_at' => $placed,
            'created_at' => $placed,
        ]);
        $dineIn = $this->onlineOrder('pending', [
            'order_type' => 'dine_in',
            'placed_at' => $placed,
            'created_at' => $placed,
        ]);

        $this->assertFalse(OrderViewBootstrap::expressTimerContext($pos)['show_timer']);
        $this->assertFalse(OrderViewBootstrap::expressTimerContext($dineIn)['show_timer']);
    }

    public function test_detail_panel_markup_uses_meatco_timer_hooks(): void
    {
        $placed = gmdate('Y-m-d H:i:s', time() - 90);
        $order = $this->onlineOrder('pending', ['placed_at' => $placed, 'created_at' => $placed]);
        $html = view('partials.order-operations._online-order-timer-panel', array_merge(
            ['order' => $order],
            OrderViewBootstrap::expressTimerViewVars($order)
        ))->render();

        $this->assertStringContainsString('data-meatco-order-timer', $html);
        $this->assertStringContainsString('Time Since Order Placed', $html);
        $this->assertStringContainsString('Dispatch ASAP', $html);
        $this->assertStringNotContainsString('select', $html);
    }

    public function test_saving_out_for_delivery_stamps_dispatched_at(): void
    {
        $placed = '2026-06-30 09:00:00';
        \Illuminate\Support\Facades\DB::table('orders')->insert([
            'id' => 42,
            'readable_order_id' => 'A10042',
            'is_guest' => 1,
            'order_status' => 'processing',
            'payment_status' => 'unpaid',
            'order_type' => 'delivery',
            'order_amount' => 100,
            'placed_at' => $placed,
            'dispatched_at' => null,
            'created_at' => $placed,
            'updated_at' => $placed,
        ]);

        $order = Order::query()->find(42);
        $order->order_status = 'out_for_delivery';
        $order->save();

        $fresh = Order::query()->find(42);
        $this->assertNotNull($fresh->getRawOriginal('dispatched_at'));
    }

    private function onlineOrder(string $status, array $times = []): Order
    {
        $order = new Order();
        $order->setRawAttributes(array_merge([
            'id' => 1,
            'is_guest' => 1,
            'order_status' => $status,
            'payment_status' => 'paid',
            'order_type' => $times['order_type'] ?? 'delivery',
            'placed_at' => null,
            'dispatched_at' => null,
            'created_at' => $times['placed_at'] ?? null,
            'updated_at' => $times['updated_at'] ?? null,
        ], $times), true);

        return $order;
    }
}
