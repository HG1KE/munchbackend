<?php

namespace Tests\Unit;

use App\Model\StockExportEvent;
use App\Services\Stock\StockExportArchiveService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockExportArchiveServiceTest extends TestCase
{
    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = config('database.default');
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('stock_export_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->date('count_date');
            $table->unsignedBigInteger('stock_manager_id')->nullable();
            $table->string('exporter_role', 32)->default('stocks');
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->string('pdf_hash', 64)->nullable();
            $table->json('omitted')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('stock_export_events');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_archive_stores_payload_and_hashes(): void
    {
        $report = [
            'branch_name' => 'Bamburi',
            'date' => '2026-11-13',
            'from' => '2026-11-13',
            'to' => '2026-11-13',
            'date_label' => 'Thursday, 13th November 2026',
            'rows' => [['stock_item_id' => 1, 'name' => 'Buns', 'sold_qty' => 2]],
            'sections' => [],
            'filename_base' => 'Munch Bamburi Stocks Audit',
        ];
        $omitted = [['stock_item_id' => 9, 'name' => 'Hidden', 'variance_label' => '1 missing']];
        $pdf = '%PDF-1.4 fake';

        $event = (new StockExportArchiveService())->archive(
            4,
            '2026-11-13',
            $report,
            $omitted,
            $pdf,
            'stocks',
            12,
            null
        );

        $this->assertInstanceOf(StockExportEvent::class, $event);
        $this->assertSame(4, (int) $event->branch_id);
        $this->assertSame(64, strlen((string) $event->payload_hash));
        $this->assertSame(hash('sha256', $pdf), $event->pdf_hash);
        $this->assertSame('Hidden', $event->omitted[0]['name']);
        $this->assertSame(1, StockExportEvent::query()->count());
    }
}
