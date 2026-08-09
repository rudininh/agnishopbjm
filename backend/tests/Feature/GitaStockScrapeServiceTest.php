<?php

namespace Tests\Feature;

use App\Services\GitaStockScrapeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GitaStockScrapeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('gita_stock_scrape_items');
        Schema::dropIfExists('gita_stock_scrape_runs');
        Schema::dropIfExists('stock_master');

        Schema::create('stock_master', function (Blueprint $table): void {
            $table->id();
            $table->string('internal_sku');
            $table->integer('stock_qty')->default(0);
        });

        Schema::create('gita_stock_scrape_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 32);
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->text('message')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->unsignedInteger('duplicate_master_count')->default(0);
            $table->unsignedInteger('changed_count')->default(0);
            $table->timestamps();
        });

        Schema::create('gita_stock_scrape_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('gita_stock_scrape_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('stock_master_id')->nullable();
            $table->string('sku', 150);
            $table->integer('stock');
            $table->string('gita_product_id', 100)->nullable();
            $table->string('gita_variant_id', 100)->nullable();
            $table->integer('previous_stock')->nullable();
            $table->string('match_status', 32);
            $table->timestamp('captured_at');
            $table->timestamps();
        });
    }

    public function test_successful_capture_persists_exact_match_without_updating_stock_master(): void
    {
        DB::table('stock_master')->insert(['internal_sku' => 'GITA-RED-S', 'stock_qty' => 7]);

        $result = app(GitaStockScrapeService::class)->record($this->successPayload([
            ['sku' => 'GITA-RED-S', 'stock' => 12],
        ]));

        $this->assertSame('success', $result['status']);
        $this->assertDatabaseHas('gita_stock_scrape_items', [
            'sku' => 'GITA-RED-S',
            'stock_master_id' => 1,
            'match_status' => 'matched',
            'stock' => 12,
        ]);
        $this->assertDatabaseHas('stock_master', ['id' => 1, 'stock_qty' => 7]);
    }

    public function test_duplicate_source_sku_rejects_the_entire_success_payload(): void
    {
        try {
            app(GitaStockScrapeService::class)->record($this->successPayload([
                ['sku' => 'GITA-RED-S', 'stock' => 12],
                ['sku' => 'GITA-RED-S', 'stock' => 9],
            ]));

            $this->fail('A duplicate source SKU must reject the complete capture.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('gita_stock_scrape_runs', 0);
            $this->assertDatabaseCount('gita_stock_scrape_items', 0);
        }

        $this->assertDatabaseCount('gita_stock_scrape_runs', 0);
    }

    public function test_needs_login_records_a_terminal_run_without_items(): void
    {
        $result = app(GitaStockScrapeService::class)->record([
            'status' => 'needs_login',
            'started_at' => '2026-08-09T00:00:00.000Z',
            'finished_at' => '2026-08-09T00:00:10.000Z',
            'message' => 'Login Gita diperlukan.',
        ]);

        $this->assertSame('needs_login', $result['status']);
        $this->assertDatabaseCount('gita_stock_scrape_items', 0);
    }

    public function test_exact_sku_matching_marks_unmatched_and_duplicate_master_skus_without_fallbacks(): void
    {
        DB::table('stock_master')->insert([
            ['internal_sku' => 'GITA-RED-S', 'stock_qty' => 7],
            ['internal_sku' => 'GITA-RED-S', 'stock_qty' => 4],
            ['internal_sku' => 'GITA-BLUE-M', 'stock_qty' => 3],
        ]);

        $result = app(GitaStockScrapeService::class)->record($this->successPayload([
            ['sku' => ' GITA-BLUE-M ', 'stock' => 10],
            ['sku' => 'gita-blue-m', 'stock' => 9],
            ['sku' => 'GITA-RED-S', 'stock' => 8],
        ]));

        $this->assertSame([
            'item_count' => 3,
            'matched_count' => 1,
            'unmatched_count' => 1,
            'duplicate_master_count' => 1,
            'changed_count' => 0,
        ], $result['summary']);
        $this->assertDatabaseHas('gita_stock_scrape_items', [
            'sku' => 'GITA-BLUE-M',
            'stock_master_id' => 3,
            'match_status' => 'matched',
        ]);
        $this->assertDatabaseHas('gita_stock_scrape_items', [
            'sku' => 'gita-blue-m',
            'stock_master_id' => null,
            'match_status' => 'unmatched',
        ]);
        $this->assertDatabaseHas('gita_stock_scrape_items', [
            'sku' => 'GITA-RED-S',
            'stock_master_id' => null,
            'match_status' => 'duplicate_master_sku',
        ]);
    }

    public function test_later_successful_capture_records_previous_stock_and_changed_count_without_mutating_master(): void
    {
        DB::table('stock_master')->insert(['internal_sku' => 'GITA-RED-S', 'stock_qty' => 7]);

        app(GitaStockScrapeService::class)->record($this->successPayload([
            ['sku' => 'GITA-RED-S', 'stock' => 12],
        ]));

        $result = app(GitaStockScrapeService::class)->record($this->successPayload([
            ['sku' => 'GITA-RED-S', 'stock' => 9],
        ], '2026-08-09T00:01:00.000Z'));

        $this->assertSame(1, $result['summary']['changed_count']);
        $this->assertDatabaseHas('gita_stock_scrape_items', [
            'run_id' => $result['run_id'],
            'sku' => 'GITA-RED-S',
            'stock' => 9,
            'previous_stock' => 12,
            'match_status' => 'matched',
        ]);
        $this->assertDatabaseHas('stock_master', ['id' => 1, 'stock_qty' => 7]);
    }

    public function test_terminal_and_success_payloads_reject_partial_or_invalid_captures_before_writes(): void
    {
        foreach ([
            ['status' => 'failed', 'started_at' => '2026-08-09T00:00:00.000Z', 'finished_at' => '2026-08-09T00:00:10.000Z', 'items' => []],
            $this->successPayload([]),
            $this->successPayload([['sku' => ' ', 'stock' => 1]]),
            $this->successPayload([['sku' => 'GITA-RED-S', 'stock' => -1]]),
            $this->successPayload([['sku' => 'GITA-RED-S', 'stock' => '12']]),
            ['status' => 'success', 'started_at' => 'invalid', 'finished_at' => '2026-08-09T00:00:10.000Z', 'items' => [['sku' => 'GITA-RED-S', 'stock' => 1, 'captured_at' => '2026-08-09T00:00:05.000Z']]],
        ] as $payload) {
            try {
                app(GitaStockScrapeService::class)->record($payload);
                $this->fail('Partial or invalid capture payload must be rejected.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('gita_stock_scrape_runs', 0);
                $this->assertDatabaseCount('gita_stock_scrape_items', 0);
            }
        }
    }

    public function test_read_models_expose_the_newest_run_and_filtered_immutable_items(): void
    {
        DB::table('stock_master')->insert(['internal_sku' => 'GITA-RED-S', 'stock_qty' => 7]);
        $service = app(GitaStockScrapeService::class);

        $service->record($this->successPayload([
            ['sku' => 'GITA-RED-S', 'stock' => 12],
            ['sku' => 'GITA-UNKNOWN', 'stock' => 2],
        ]));
        $latestRun = $service->record([
            'status' => 'needs_login',
            'started_at' => '2026-08-09T00:01:00.000Z',
            'finished_at' => '2026-08-09T00:01:10.000Z',
            'message' => 'Login Gita diperlukan.',
        ]);

        $this->assertSame([
            'id' => $latestRun['run_id'],
            'status' => 'needs_login',
            'summary' => [
                'item_count' => 0,
                'matched_count' => 0,
                'unmatched_count' => 0,
                'duplicate_master_count' => 0,
                'changed_count' => 0,
            ],
        ], array_intersect_key($service->latestRun(), array_flip(['id', 'status', 'summary'])));

        $page = $service->items(['match_status' => 'matched'], 1, 10);

        $this->assertSame(1, $page['pagination']['total']);
        $this->assertSame('GITA-RED-S', $page['items'][0]['sku']);
        $this->assertSame(12, $page['items'][0]['stock']);
        $this->assertSame('matched', $page['items'][0]['match_status']);
    }

    private function successPayload(array $items, string $startedAt = '2026-08-09T00:00:00.000Z'): array
    {
        $finishedAt = $startedAt === '2026-08-09T00:00:00.000Z'
            ? '2026-08-09T00:00:10.000Z'
            : '2026-08-09T00:01:10.000Z';
        $capturedAt = $startedAt === '2026-08-09T00:00:00.000Z'
            ? '2026-08-09T00:00:05.000Z'
            : '2026-08-09T00:01:05.000Z';

        return [
            'status' => 'success',
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'items' => array_map(fn (array $item): array => [
                'sku' => $item['sku'],
                'stock' => $item['stock'],
                'gita_product_id' => '1001',
                'gita_variant_id' => '2001',
                'captured_at' => $capturedAt,
            ], $items),
        ];
    }
}
