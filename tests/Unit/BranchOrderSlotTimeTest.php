<?php

namespace Tests\Unit;

use App\CentralLogics\Helpers;
use App\Support\BranchOrderSlotTime;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchOrderSlotTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_now_delivery_near_close_keeps_customer_slot_inside_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 21:31:47'));

        $slot = BranchOrderSlotTime::customerSlot('now', '2026-09-17');
        $ready = BranchOrderSlotTime::kitchenReadyTime($slot['time'], 40);

        $this->assertSame('2026-09-17', $slot['date']);
        $this->assertSame('21:31:47', $slot['time']);
        $this->assertSame('22:11:47', $ready);
    }

    public function test_scheduled_slot_does_not_include_preparation_minutes(): void
    {
        $slot = BranchOrderSlotTime::customerSlot('21:00', '2026-09-17');
        $ready = BranchOrderSlotTime::kitchenReadyTime($slot['time'], 40);

        $this->assertSame('2026-09-17', $slot['date']);
        $this->assertSame('21:00:00', $slot['time']);
        $this->assertSame('21:40:00', $ready);
    }

    public function test_kilimani_style_slot_accepts_customer_time_even_when_ready_time_is_after_close(): void
    {
        DB::table('branches')->insert([
            'id' => 3,
            'name' => 'Kilimani',
            'status' => 1,
            'preparation_time' => 40,
        ]);
        DB::table('branch_time_schedules')->insert([
            'branch_id' => 3,
            'day' => Carbon::parse('2026-09-17')->dayOfWeek,
            'opening_time' => '10:00:00',
            'closing_time' => '22:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-17 21:31:00'));
        $slot = BranchOrderSlotTime::customerSlot('now', '2026-09-17');
        $ready = BranchOrderSlotTime::kitchenReadyTime($slot['time'], 40);

        $this->assertTrue(Helpers::isBranchAvailable(3, $slot['date'], $slot['time']));
        $this->assertFalse(Helpers::isBranchAvailable(3, $slot['date'], $ready));
        $this->assertTrue(Helpers::canStillOrderFromBranchToday(3));
    }

    public function test_place_order_checks_customer_slot_not_kitchen_ready_time(): void
    {
        $place = file_get_contents(app_path('Http/Controllers/Api/V1/OrderController.php'));

        $this->assertStringContainsString('BranchOrderSlotTime::customerSlot', $place);
        $this->assertStringContainsString('Helpers::isBranchAvailable($request[\'branch_id\'], $deliveryDate, $customerSlotTime)', $place);
        $this->assertStringContainsString('BranchOrderSlotTime::kitchenReadyTime($customerSlotTime, $preparation_time)', $place);
        $this->assertStringContainsString("'delivery_time' => \$deliveryTime", $place);
        $this->assertStringNotContainsString('delivery keeps ready-time semantics', $place);
        $this->assertStringNotContainsString('$availabilitySlotTime = $deliveryTimeWithPrep', $place);
        $this->assertStringNotContainsString("order_type'] === 'take_away' && (string) \$request['delivery_time'] === 'now'", $place);

        $config = file_get_contents(app_path('CentralLogics/StorefrontConfigService.php'));
        $this->assertStringContainsString("'preparation_time'", $config);
        $this->assertStringNotContainsString('preparation_time, \'minute\'', $config);
        $this->assertStringNotContainsString('add($preparation_time', $config);
    }

    private function ensureSchema(): void
    {
        Schema::dropIfExists('branch_time_schedules');
        Schema::dropIfExists('branches');

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->integer('status')->default(1);
            $table->integer('preparation_time')->nullable();
        });

        Schema::create('branch_time_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->integer('day');
            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();
        });
    }
}
