<?php

namespace Tests\Unit;

use App\Jobs\DeliverMunchOrderWebhookJob;
use App\Services\MunchOrdersWebhook\MunchOrderWebhookJobDispatcher;
use App\Model\Order;
use App\Models\MunchOrderWebhookOutbox;
use App\Services\MunchOrdersWebhook\MunchOrderRingingWebhookRecorder;
use App\Services\MunchOrdersWebhook\MunchOrderWebhookBranch;
use App\Services\MunchOrdersWebhook\MunchOrderWebhookHttpClient;
use App\Services\MunchOrdersWebhook\MunchOrderWebhookOutboxPayloadRefresher;
use App\Services\MunchOrdersWebhook\MunchOrderWebhookPayloadBuilder;
use App\Services\MunchOrdersWebhook\OnlineOrderRingingEligibility;
use App\Support\PosOrderTypes;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MunchOrderRingingWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
        Config::set('munch_orders_webhook.url', 'https://webhook.test/munch/orders');
        Config::set('munch_orders_webhook.authorization', 'Bearer test-token');
    }

    public function test_online_pending_order_creates_one_outbox_row(): void
    {
        $order = $this->persistOrder(['order_type' => 'delivery', 'order_status' => 'pending']);
        $recorder = app(MunchOrderRingingWebhookRecorder::class);

        $first = $recorder->recordForOrder($order);
        $second = $recorder->recordForOrder($order);

        $this->assertNotNull($first);
        $this->assertSame(1, MunchOrderWebhookOutbox::query()->count());
        $this->assertSame($first->id, $second?->id);
        $this->assertSame('order.ringing', $first->event_type);
    }

    public function test_pos_order_does_not_create_outbox(): void
    {
        $order = $this->persistOrder([
            'order_type' => 'pos',
            'sales_channel' => PosOrderTypes::DELIVERY,
            'order_status' => 'pending',
        ]);

        $this->assertNull(app(MunchOrderRingingWebhookRecorder::class)->recordForOrder($order));
        $this->assertSame(0, MunchOrderWebhookOutbox::query()->count());
    }

    public function test_marketplace_order_does_not_create_outbox(): void
    {
        $order = $this->persistOrder([
            'order_type' => 'pos',
            'sales_channel' => PosOrderTypes::GLOVO,
            'order_status' => 'pending',
        ]);

        $this->assertNull(app(MunchOrderRingingWebhookRecorder::class)->recordForOrder($order));
    }

    public function test_processing_status_does_not_create_outbox(): void
    {
        $order = $this->persistOrder(['order_status' => 'processing']);
        $this->assertNull(app(MunchOrderRingingWebhookRecorder::class)->recordForOrder($order));
    }

    public function test_makadara_branch_does_not_create_outbox(): void
    {
        $order = $this->persistOrder([
            'branch_id' => MunchOrderWebhookBranch::MAKADARA_BRANCH_ID,
            'order_status' => 'pending',
        ]);

        $this->assertNull(app(MunchOrderRingingWebhookRecorder::class)->recordForOrder($order));
    }

    /**
     * @dataProvider webhookBranchLabelProvider
     */
    public function test_branch_payload_labels_for_eligible_branches(int $branchId, string $expectedLabel): void
    {
        $order = $this->persistOrder(['branch_id' => $branchId]);
        $payload = app(MunchOrderWebhookPayloadBuilder::class)->build($order->load('branch'));

        $this->assertSame($expectedLabel, $payload['branch']);
        $this->assertNotNull(app(MunchOrderRingingWebhookRecorder::class)->recordForOrder($order));
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function webhookBranchLabelProvider(): array
    {
        return [
            'nyali' => [1, 'Nyali'],
            'bamburi' => [10, 'Bamburi'],
            'mtwapa' => [13, 'Mtwapa'],
            'kilimani' => [14, 'Kilimani'],
        ];
    }

    public function test_retry_refreshes_stale_branch_in_existing_outbox_and_posts_bamburi(): void
    {
        Http::fake(['https://webhook.test/*' => Http::response([], 200)]);

        $order = $this->persistOrder(['id' => 130814618, 'branch_id' => 10]);
        $outbox = MunchOrderWebhookOutbox::query()->create([
            'event_type' => MunchOrderWebhookOutbox::EVENT_ORDER_RINGING,
            'order_id' => 130814618,
            'event_key' => MunchOrderWebhookOutbox::eventKeyForOrder(130814618),
            'payload' => [
                'event' => 'order.ringing',
                'order_id' => '130814618',
                'branch' => 'Munch Bamburi',
                'occurred_at' => '2026-09-28T13:08:34+03:00',
            ],
            'status' => MunchOrderWebhookOutbox::STATUS_FAILED,
            'last_error' => 'webhook_url_not_configured',
        ]);
        $originalOutboxId = $outbox->id;
        $originalEventKey = $outbox->event_key;

        app(MunchOrderWebhookOutboxPayloadRefresher::class)->refresh($outbox->fresh());
        $outbox->update([
            'status' => MunchOrderWebhookOutbox::STATUS_PENDING,
            'last_error' => null,
        ]);

        $this->runWebhookDeliveryJob((int) $originalOutboxId);

        $fresh = MunchOrderWebhookOutbox::query()->findOrFail($originalOutboxId);
        $this->assertSame(1, MunchOrderWebhookOutbox::query()->count());
        $this->assertSame($originalOutboxId, $fresh->id);
        $this->assertSame(130814618, $fresh->order_id);
        $this->assertSame($originalEventKey, $fresh->event_key);
        $this->assertSame('Bamburi', $fresh->payload['branch']);
        $this->assertSame('2026-09-28T13:08:34+03:00', $fresh->payload['occurred_at']);

        Http::assertSent(function ($request) {
            return ($request->data()['branch'] ?? null) === 'Bamburi'
                && ($request->header('Idempotency-Key')[0] ?? '') === 'order.ringing:130814618';
        });
    }

    public function test_duplicate_retry_does_not_create_second_outbox_row(): void
    {
        $order = $this->persistOrder(['id' => 130814619, 'branch_id' => 10]);
        $outbox = MunchOrderWebhookOutbox::query()->create([
            'event_type' => MunchOrderWebhookOutbox::EVENT_ORDER_RINGING,
            'order_id' => 130814619,
            'event_key' => MunchOrderWebhookOutbox::eventKeyForOrder(130814619),
            'payload' => ['event' => 'order.ringing', 'order_id' => '130814619', 'branch' => 'Munch Bamburi'],
            'status' => MunchOrderWebhookOutbox::STATUS_FAILED,
        ]);

        $refresher = app(MunchOrderWebhookOutboxPayloadRefresher::class);
        $refresher->refresh($outbox->fresh());
        $refresher->refresh($outbox->fresh());

        $this->assertSame(1, MunchOrderWebhookOutbox::query()->count());
        $this->assertSame('Bamburi', $outbox->fresh()->payload['branch']);
    }

    public function test_missing_webhook_url_is_retryable_and_keeps_outbox_pending(): void
    {
        Config::set('munch_orders_webhook.url', '');
        $outbox = $this->createOutboxForOrder($this->persistOrder());

        try {
            $this->runWebhookDeliveryJob((int) $outbox->id);
        } catch (RuntimeException) {
            //
        }

        $this->assertSame(MunchOrderWebhookOutbox::STATUS_PENDING, $outbox->fresh()->status);
    }

    public function test_scheduled_future_delivery_does_not_create_outbox(): void
    {
        $order = $this->persistOrder([
            'delivery_date' => Carbon::now()->addDay()->format('Y-m-d'),
        ]);
        $this->assertFalse(OnlineOrderRingingEligibility::qualifies($order));
        $this->assertNull(app(MunchOrderRingingWebhookRecorder::class)->recordForOrder($order));
    }

    public function test_outbox_not_persisted_when_order_transaction_rolls_back(): void
    {
        $attrs = $this->orderRow(['id' => 100501]);

        try {
            DB::beginTransaction();
            DB::table('orders')->insert($attrs);
            app(MunchOrderRingingWebhookRecorder::class)->recordFromAttributes($attrs);
            DB::rollBack();
        } catch (\Throwable) {
            DB::rollBack();
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, MunchOrderWebhookOutbox::query()->count());
    }

    public function test_committed_order_leaves_durable_outbox(): void
    {
        $attrs = $this->orderRow(['id' => 100502]);

        DB::beginTransaction();
        DB::table('orders')->insert($attrs);
        $outbox = app(MunchOrderRingingWebhookRecorder::class)->recordFromAttributes($attrs);
        DB::commit();

        $this->assertNotNull($outbox);
        $this->assertSame(MunchOrderWebhookOutbox::STATUS_PENDING, $outbox->fresh()->status);
    }

    public function test_eligible_online_order_commits_order_and_outbox_atomically(): void
    {
        $attrs = $this->orderRow(['id' => 100503]);

        DB::beginTransaction();
        DB::table('orders')->insert($attrs);
        $outbox = app(MunchOrderRingingWebhookRecorder::class)->recordFromAttributes($attrs);
        DB::commit();

        $this->assertSame(1, Order::query()->where('id', 100503)->count());
        $this->assertSame(1, MunchOrderWebhookOutbox::query()->where('order_id', 100503)->count());
        $this->assertSame((string) $outbox->id, (string) MunchOrderWebhookOutbox::query()->where('order_id', 100503)->value('id'));
    }

    public function test_outbox_insert_failure_rolls_back_order_transaction(): void
    {
        Schema::drop('munch_order_webhook_outbox');
        $attrs = $this->orderRow(['id' => 100504]);
        $threw = false;

        try {
            DB::beginTransaction();
            DB::table('orders')->insert($attrs);
            app(MunchOrderRingingWebhookRecorder::class)->recordFromAttributes($attrs);
            DB::commit();
        } catch (\Throwable) {
            $threw = true;
            DB::rollBack();
        } finally {
            Schema::create('munch_order_webhook_outbox', function (Blueprint $table) {
                $table->id();
                $table->string('event_type', 64);
                $table->unsignedBigInteger('order_id');
                $table->string('event_key', 128);
                $table->json('payload');
                $table->string('status', 32)->default('pending');
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->timestamp('occurred_at')->nullable();
                $table->timestamp('last_attempted_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->unsignedSmallInteger('last_http_status')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->unique(['event_type', 'order_id']);
            });
        }

        $this->assertTrue($threw);
        $this->assertSame(0, Order::query()->where('id', 100504)->count());
        $this->assertSame(0, MunchOrderWebhookOutbox::query()->where('order_id', 100504)->count());
    }

    public function test_outbox_database_failure_cannot_leave_order_without_outbox(): void
    {
        $recorder = \Mockery::mock(MunchOrderRingingWebhookRecorder::class);
        $recorder->shouldReceive('recordFromAttributes')
            ->once()
            ->andThrow(new \RuntimeException('simulated_outbox_database_failure'));
        $this->instance(MunchOrderRingingWebhookRecorder::class, $recorder);

        $attrs = $this->orderRow(['id' => 100505]);
        $threw = false;

        try {
            DB::beginTransaction();
            DB::table('orders')->insert($attrs);
            app(MunchOrderRingingWebhookRecorder::class)->recordFromAttributes($attrs);
            DB::commit();
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertSame('simulated_outbox_database_failure', $e->getMessage());
            DB::rollBack();
        }

        $this->assertTrue($threw);
        $this->assertSame(0, Order::query()->where('id', 100505)->count());
    }

    public function test_webhook_delivery_failure_leaves_committed_order_and_pending_outbox(): void
    {
        Http::fake(['https://webhook.test/*' => Http::response([], 500)]);
        $attrs = $this->orderRow(['id' => 100506]);

        DB::beginTransaction();
        DB::table('orders')->insert($attrs);
        $outbox = app(MunchOrderRingingWebhookRecorder::class)->recordFromAttributes($attrs);
        DB::commit();

        try {
            $this->runWebhookDeliveryJob((int) $outbox->id);
        } catch (RuntimeException) {
            //
        }

        $this->assertSame(1, Order::query()->where('id', 100506)->count());
        $fresh = $outbox->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(MunchOrderWebhookOutbox::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->delivered_at);
    }

    public function test_payload_minimum_fields_and_optional_fields(): void
    {
        DB::table('users')->insert([
            'id' => 10,
            'f_name' => 'Faith',
            'l_name' => 'Murugi',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = $this->persistOrder([
            'branch_id' => 14,
            'user_id' => 10,
            'is_guest' => 0,
            'order_amount' => 450,
            'delivery_charge' => 100,
            'order_type' => 'delivery',
            'sales_channel' => null,
        ]);
        $order->load(['branch', 'customer']);

        $payload = app(MunchOrderWebhookPayloadBuilder::class)->build($order);

        $this->assertSame('order.ringing', $payload['event']);
        $this->assertSame((string) $order->id, $payload['order_id']);
        $this->assertSame('Kilimani', $payload['branch']);
        $this->assertNotEmpty($payload['occurred_at']);
        $this->assertNotFalse(strtotime($payload['occurred_at']));
        $this->assertSame('pending', $payload['status']);
        $this->assertSame('delivery', $payload['channel']);
        $this->assertSame('Faith Murugi', $payload['customer_name']);
        $this->assertSame(550.0, $payload['total']);
    }

    public function test_delivery_job_posts_json_with_configured_authorization(): void
    {
        Http::fake([
            'https://webhook.test/*' => Http::response(['ok' => true], 200),
        ]);

        $order = $this->persistOrder();
        $outbox = app(MunchOrderRingingWebhookRecorder::class)->recordForOrder($order);
        $this->assertNotNull($outbox);

        $this->runWebhookDeliveryJob((int) $outbox->id);

        Http::assertSent(function ($request) use ($order) {
            $body = $request->data();
            $auth = $request->header('Authorization')[0] ?? '';

            return $request->url() === 'https://webhook.test/munch/orders'
                && $request->method() === 'POST'
                && ($body['event'] ?? null) === 'order.ringing'
                && ($body['order_id'] ?? null) === (string) $order->id
                && isset($body['branch'], $body['occurred_at'])
                && $auth === 'Bearer test-token'
                && ($request->header('Idempotency-Key')[0] ?? '') === 'order.ringing:'.$order->id;
        });

        $this->assertSame(MunchOrderWebhookOutbox::STATUS_DELIVERED, $outbox->fresh()->status);
    }

    public function test_http_500_is_retryable_and_401_is_permanent(): void
    {
        $client = app(MunchOrderWebhookHttpClient::class);
        $this->assertTrue($client->isRetryableHttpStatus(500));
        $this->assertTrue($client->isRetryableHttpStatus(429));
        $this->assertFalse($client->isRetryableHttpStatus(401));
        $this->assertFalse($client->isRetryableHttpStatus(403));
    }

    public function test_job_marks_delivered_on_2xx(): void
    {
        Http::fake(['https://webhook.test/*' => Http::response([], 204)]);
        $outbox = $this->createOutboxForOrder($this->persistOrder());
        $this->runWebhookDeliveryJob((int) $outbox->id);
        $this->assertSame(MunchOrderWebhookOutbox::STATUS_DELIVERED, $outbox->fresh()->status);
    }

    public function test_job_marks_failed_on_401_without_success(): void
    {
        Http::fake(['https://webhook.test/*' => Http::response([], 401)]);
        $outbox = $this->createOutboxForOrder($this->persistOrder());

        try {
            $this->runWebhookDeliveryJob((int) $outbox->id);
            $this->fail('Expected permanent failure');
        } catch (RuntimeException) {
            //
        }

        $fresh = $outbox->fresh();
        $this->assertSame(MunchOrderWebhookOutbox::STATUS_FAILED, $fresh->status);
        $this->assertSame(1, $fresh->attempt_count);
    }

    public function test_job_throws_on_503_for_queue_retry(): void
    {
        Http::fake(['https://webhook.test/*' => Http::response([], 503)]);
        $outbox = $this->createOutboxForOrder($this->persistOrder());

        $this->expectException(RuntimeException::class);
        $this->runWebhookDeliveryJob((int) $outbox->id);
    }

    public function test_retry_reuses_same_outbox_identity(): void
    {
        Http::fake([
            'https://webhook.test/*' => Http::sequence()
                ->push([], 503)
                ->push([], 200),
        ]);

        $outbox = $this->createOutboxForOrder($this->persistOrder());
        try {
            $this->runWebhookDeliveryJob((int) $outbox->id);
        } catch (RuntimeException) {
            //
        }

        $this->assertSame(1, MunchOrderWebhookOutbox::query()->count());
        $this->runWebhookDeliveryJob((int) $outbox->id);
        $this->assertSame(MunchOrderWebhookOutbox::STATUS_DELIVERED, $outbox->fresh()->status);
        $this->assertSame(2, $outbox->fresh()->attempt_count);
    }

    public function test_webhook_job_failure_does_not_remove_order(): void
    {
        Http::fake(['https://webhook.test/*' => Http::response([], 500)]);
        $order = $this->persistOrder();
        $outbox = $this->createOutboxForOrder($order);

        try {
            $this->runWebhookDeliveryJob((int) $outbox->id);
        } catch (RuntimeException) {
            //
        }

        $this->assertNotNull(Order::query()->find($order->id));
    }

    public function test_webhook_dispatcher_uses_dedicated_redis_queue(): void
    {
        Config::set('munch_orders_webhook.queue_connection', 'redis');
        Config::set('munch_orders_webhook.queue_name', 'munch-webhooks');
        Queue::fake();

        MunchOrderWebhookJobDispatcher::dispatch(99);

        Queue::assertPushedOn('munch-webhooks', DeliverMunchOrderWebhookJob::class);
        Queue::assertPushed(DeliverMunchOrderWebhookJob::class, function (DeliverMunchOrderWebhookJob $job) {
            return $job->outboxId === 99 && $job->connection === 'redis' && $job->queue === 'munch-webhooks';
        });
    }

    public function test_failed_handler_keeps_pending_for_missing_webhook_url(): void
    {
        $outbox = MunchOrderWebhookOutbox::query()->create([
            'event_type' => MunchOrderWebhookOutbox::EVENT_ORDER_RINGING,
            'order_id' => 999001,
            'event_key' => 'order.ringing:999001',
            'payload' => ['event' => 'order.ringing', 'order_id' => '999001', 'branch' => 'Bamburi'],
            'status' => MunchOrderWebhookOutbox::STATUS_PENDING,
            'last_error' => 'webhook_url_not_configured',
        ]);

        $job = new DeliverMunchOrderWebhookJob((int) $outbox->id);
        $job->failed(new RuntimeException('retryable_webhook_failure'));

        $this->assertSame(MunchOrderWebhookOutbox::STATUS_PENDING, $outbox->fresh()->status);
    }

    public function test_place_order_records_outbox_before_commit_and_queues_webhook_after(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/OrderController.php'));

        $this->assertStringContainsString('MunchOrderRingingWebhookRecorder', $controller);
        $this->assertStringContainsString('recordFromAttributes($or)', $controller);
        $this->assertStringContainsString('MunchOrderWebhookJobDispatcher::dispatch($webhookOutboxId)', $controller);
        $this->assertStringNotContainsString('munch_orders_webhook.outbox_record_failed', $controller);
        $this->assertStringNotContainsString('webhookRecordException', $controller);
        $this->assertTrue(
            strpos($controller, 'recordFromAttributes($or)') < strpos($controller, 'DB::commit()'),
            'outbox must be written before commit'
        );
        $this->assertTrue(
            strpos($controller, 'DB::commit()') < strpos($controller, 'finishOnlineOrderPlacement($order_id, $request, $webhookOutboxId)'),
            'webhook dispatch must run only after commit'
        );
    }

    public function test_backoff_schedule_matches_requirement(): void
    {
        $job = new DeliverMunchOrderWebhookJob(1);
        $this->assertSame([60, 300, 900, 1800], $job->backoff());
    }

    private function createOutboxForOrder(Order $order): MunchOrderWebhookOutbox
    {
        $outbox = app(MunchOrderRingingWebhookRecorder::class)->recordForOrder($order);
        $this->assertNotNull($outbox);

        return $outbox;
    }

    private function runWebhookDeliveryJob(int $outboxId): void
    {
        (new DeliverMunchOrderWebhookJob($outboxId))->handle(
            app(MunchOrderWebhookHttpClient::class),
            app(MunchOrderWebhookOutboxPayloadRefresher::class),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function persistOrder(array $overrides = []): Order
    {
        $row = $this->orderRow($overrides);
        DB::table('orders')->insert($row);

        return Order::query()->findOrFail($row['id']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function orderRow(array $overrides = []): array
    {
        $id = (int) ($overrides['id'] ?? ((int) (DB::table('orders')->max('id') ?? 100000) + 1));

        return array_merge([
            'id' => $id,
            'user_id' => 10,
            'is_guest' => 0,
            'order_amount' => 590,
            'payment_status' => 'unpaid',
            'order_status' => 'pending',
            'payment_method' => 'cash_on_delivery',
            'order_type' => 'delivery',
            'sales_channel' => null,
            'branch_id' => 13,
            'delivery_charge' => 100,
            'delivery_date' => Carbon::now()->format('Y-m-d'),
            'readable_order_id' => 'A'.$id,
            'placed_at' => now()->utc()->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    private function ensureSchema(): void
    {
        foreach (['munch_order_webhook_outbox', 'orders', 'branches', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('branches', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedTinyInteger('is_guest')->default(0);
            $table->float('order_amount')->default(0);
            $table->string('payment_status')->nullable();
            $table->string('order_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('order_type')->nullable();
            $table->string('sales_channel')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->float('delivery_charge')->default(0);
            $table->date('delivery_date')->nullable();
            $table->string('readable_order_id')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('munch_order_webhook_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64);
            $table->unsignedBigInteger('order_id');
            $table->string('event_key', 128);
            $table->json('payload');
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['event_type', 'order_id']);
        });

        foreach ([
            [1, 'Munch Nyali'],
            [10, 'Munch Bamburi'],
            [11, 'Munch Makadara'],
            [13, 'Munch Mtwapa'],
            [14, 'Munch Kilimani'],
        ] as [$id, $name]) {
            DB::table('branches')->insert([
                'id' => $id,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
