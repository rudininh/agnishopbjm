<?php

namespace Tests\Feature;

use App\Services\MarketplaceOperationLeaseService;
use App\Services\MarketplaceSyncService;
use App\Services\MarketplaceTokenRefreshService;
use App\Services\GitaOrderScrapeWorkerLauncher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class GitaOrderScrapeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('stock_master')) {
            Schema::create('stock_master', function (Blueprint $table): void {
                $table->id();
                $table->string('internal_sku');
                $table->integer('stock_qty')->default(0);
            });
        }

        config(['gita_order_scraper.ingest_token' => 'worker-secret']);

        $tokens = Mockery::mock(MarketplaceTokenRefreshService::class);
        $tokens->shouldReceive('refreshDueTokens')->zeroOrMoreTimes()->andReturn(['status' => 'ok']);
        $this->app->instance(MarketplaceTokenRefreshService::class, $tokens);
    }

    public function test_authorized_worker_persists_a_read_only_order_line(): void
    {
        DB::table('stock_master')->insert([
            'internal_sku' => 'INT-40908729245-SAGEE',
            'stock_qty' => 7,
        ]);

        $this->withToken('worker-secret')
            ->postJson('/api/gita-order-scrapes/runs', $this->successPayload())
            ->assertCreated();

        $this->assertDatabaseHas('gita_order_scrape_items', [
            'seller_order_id' => '260808T15MHC24',
            'tab_status' => 'to_ship',
            'seller_sku' => 'INT-40908729245-SAGEE',
            'variant_label' => 'Sagee',
            'quantity' => 1,
            'match_status' => 'matched',
        ]);
        $this->assertDatabaseHas('stock_master', [
            'internal_sku' => 'INT-40908729245-SAGEE',
            'stock_qty' => 7,
        ]);
    }

    public function test_worker_matches_a_master_sku_when_seller_centre_concatenates_a_rendered_price(): void
    {
        DB::table('stock_master')->insert([
            'internal_sku' => 'INT-40908729245-BLUSH-1',
            'stock_qty' => 3,
        ]);
        $payload = $this->successPayload();
        $payload['items'][0]['seller_sku'] = 'INT-40908729245-BLUSH-127.900';
        $payload['items'][0]['variant_label'] = 'Blush 1';

        $this->withToken('worker-secret')
            ->postJson('/api/gita-order-scrapes/runs', $payload)
            ->assertCreated()
            ->assertJsonPath('data.summary.matched_count', 1);

        $this->assertDatabaseHas('gita_order_scrape_items', [
            'seller_sku' => 'INT-40908729245-BLUSH-1',
            'match_status' => 'matched',
        ]);
    }

    public function test_worker_does_not_guess_when_a_concatenated_price_can_match_multiple_master_skus(): void
    {
        DB::table('stock_master')->insert([
            ['internal_sku' => 'INT-40908729245-SAGEE', 'stock_qty' => 3],
            ['internal_sku' => 'INT-40908729245-SAGEE2', 'stock_qty' => 3],
        ]);
        $payload = $this->successPayload();
        $payload['items'][0]['seller_sku'] = 'INT-40908729245-SAGEE27.900';

        $this->withToken('worker-secret')
            ->postJson('/api/gita-order-scrapes/runs', $payload)
            ->assertCreated()
            ->assertJsonPath('data.summary.matched_count', 0)
            ->assertJsonPath('data.summary.unmatched_count', 1);

        $this->assertDatabaseHas('gita_order_scrape_items', [
            'seller_sku' => 'INT-40908729245-SAGEE27.900',
            'match_status' => 'unmatched',
        ]);
    }

    public function test_worker_run_endpoint_rejects_missing_or_invalid_bearer_token(): void
    {
        $this->postJson('/api/gita-order-scrapes/runs', $this->successPayload())
            ->assertUnauthorized();

        $this->withToken('wrong')->postJson('/api/gita-order-scrapes/runs', $this->successPayload())
            ->assertUnauthorized();

        config(['gita_order_scraper.ingest_token' => '']);
        $this->withToken('worker-secret')->postJson('/api/gita-order-scrapes/runs', $this->successPayload())
            ->assertUnauthorized();
    }

    public function test_worker_can_claim_renew_and_release_the_gita_scraper_marketplace_lease(): void
    {
        $this->postJson('/api/gita-order-scrapes/worker/lease')
            ->assertUnauthorized();

        $claim = $this->withToken('worker-secret')
            ->postJson('/api/gita-order-scrapes/worker/lease')
            ->assertOk()
            ->assertJsonPath('data.status', 'claimed')
            ->assertJsonMissingPath('data.lease_token')
            ->json('data');

        $this->withToken('worker-secret')
            ->postJson('/api/gita-order-scrapes/worker/lease')
            ->assertConflict()
            ->assertJsonPath('data.status', 'already_running')
            ->assertJsonMissingPath('data.lease_token');

        $this->withToken('worker-secret')
            ->postJson('/api/gita-order-scrapes/worker/lease/renew', ['lease_token' => $claim['token']])
            ->assertOk()
            ->assertJsonPath('data.status', 'renewed');

        $this->withToken('worker-secret')
            ->postJson('/api/gita-order-scrapes/worker/lease/release', ['lease_token' => $claim['token']])
            ->assertOk()
            ->assertJsonPath('data.status', 'released');
    }

    public function test_worker_lease_reports_marketplace_busy_without_exposing_the_lock_token(): void
    {
        $lease = app(MarketplaceOperationLeaseService::class)->acquire('stb_marketplace_sync', 300);

        $this->assertTrue($lease['acquired']);

        $this->withToken('worker-secret')
            ->postJson('/api/gita-order-scrapes/worker/lease')
            ->assertStatus(423)
            ->assertJsonPath('data.status', 'marketplace_busy')
            ->assertJsonPath('data.operation', 'stb_marketplace_sync')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.lease_token');
    }

    public function test_dashboard_wakes_the_local_gita_scraper_worker_without_exposing_worker_details(): void
    {
        $launcher = Mockery::mock(GitaOrderScrapeWorkerLauncher::class);
        $launcher->shouldReceive('wake')->once()->andReturn(['status' => 'started']);
        $this->app->instance(GitaOrderScrapeWorkerLauncher::class, $launcher);

        $this->postJson('/api/gita-order-scrapes/worker/wake')
            ->assertOk()
            ->assertJsonPath('data.status', 'started')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.lease_token');
    }

    public function test_dashboard_wake_reports_an_active_gita_scraper_safely(): void
    {
        $launcher = Mockery::mock(GitaOrderScrapeWorkerLauncher::class);
        $launcher->shouldReceive('wake')->once()->andReturn(['status' => 'already_running']);
        $this->app->instance(GitaOrderScrapeWorkerLauncher::class, $launcher);

        $this->postJson('/api/gita-order-scrapes/worker/wake')
            ->assertConflict()
            ->assertJsonPath('data.status', 'already_running')
            ->assertJsonMissingPath('data.token');
    }

    public function test_dashboard_wake_reports_a_busy_marketplace_safely(): void
    {
        $launcher = Mockery::mock(GitaOrderScrapeWorkerLauncher::class);
        $launcher->shouldReceive('wake')->once()->andReturn(['status' => 'marketplace_busy']);
        $this->app->instance(GitaOrderScrapeWorkerLauncher::class, $launcher);

        $this->postJson('/api/gita-order-scrapes/worker/wake')
            ->assertStatus(423)
            ->assertJsonPath('data.status', 'marketplace_busy')
            ->assertJsonMissingPath('data.token');
    }

    public function test_dashboard_wake_reports_when_manual_start_is_required(): void
    {
        $launcher = Mockery::mock(GitaOrderScrapeWorkerLauncher::class);
        $launcher->shouldReceive('wake')->once()->andReturn(['status' => 'manual_required']);
        $this->app->instance(GitaOrderScrapeWorkerLauncher::class, $launcher);

        $this->postJson('/api/gita-order-scrapes/worker/wake')
            ->assertStatus(503)
            ->assertJsonPath('data.status', 'manual_required')
            ->assertJsonMissingPath('data.token');
    }

    public function test_public_reports_return_order_lines_and_validate_filters(): void
    {
        DB::table('stock_master')->insert([
            'internal_sku' => 'INT-40908729245-SAGEE',
            'stock_qty' => 7,
        ]);

        $this->withToken('worker-secret')
            ->postJson('/api/gita-order-scrapes/runs', $this->successPayload())
            ->assertCreated();

        $this->getJson('/api/gita-order-scrapes/latest')
            ->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.summary.quantity_count', 1);

        $this->getJson('/api/gita-order-scrapes/items?tab_status=to_ship&match_status=matched')
            ->assertOk()
            ->assertJsonPath('items.0.seller_order_id', '260808T15MHC24')
            ->assertJsonPath('items.0.seller_sku', 'INT-40908729245-SAGEE')
            ->assertJsonPath('items.0.quantity', 1)
            ->assertJsonPath('items.0.match_status', 'matched');

        $this->getJson('/api/gita-order-scrapes/items?tab_status=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tab_status');

        $this->getJson('/api/gita-order-scrapes/items?page=0&per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page', 'per_page']);
    }

    public function test_public_report_items_expose_pending_and_persisted_sync_state(): void
    {
        $stockMasterId = DB::table('stock_master')->insertGetId([
            'internal_sku' => 'INT-40908729245-SAGEE',
            'stock_qty' => 7,
        ]);

        $this->withToken('worker-secret')
            ->postJson('/api/gita-order-scrapes/runs', $this->successPayload())
            ->assertCreated();

        $this->getJson('/api/gita-order-scrapes/items')
            ->assertOk()
            ->assertJsonPath('items.0.sync_status', 'pending')
            ->assertJsonPath('items.0.sync_message', 'Belum Disinkronkan');

        $itemId = (int) DB::table('gita_order_scrape_items')->value('id');
        DB::table('gita_order_stock_syncs')->insert([
            'seller_order_id' => '260808T15MHC24',
            'seller_sku' => 'INT-40908729245-SAGEE',
            'stock_master_id' => $stockMasterId,
            'collector_item_id' => $itemId,
            'quantity' => 1,
            'status' => 'synced',
            'message' => 'Sudah Disinkronkan',
            'old_stock' => 7,
            'new_stock' => 6,
            'synced_at' => '2026-08-10 10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/gita-order-scrapes/items')
            ->assertOk()
            ->assertJsonPath('items.0.sync_status', 'synced')
            ->assertJsonPath('items.0.sync_message', 'Sudah Disinkronkan')
            ->assertJsonPath('items.0.old_stock', 7)
            ->assertJsonPath('items.0.new_stock', 6)
            ->assertJsonPath('items.0.synced_at', '2026-08-10 10:00:00');
    }

    public function test_syncing_a_matched_latest_item_updates_stock_once_and_logs_both_targets(): void
    {
        $stockMasterId = DB::table('stock_master')->insertGetId([
            'internal_sku' => 'INT-40908729245-SAGEE',
            'stock_qty' => 7,
        ]);
        $this->withToken('worker-secret')->postJson('/api/gita-order-scrapes/runs', $this->successPayload())->assertCreated();
        $itemId = (int) DB::table('gita_order_scrape_items')->value('id');
        $this->bindSuccessfulMarketplaceSync($stockMasterId);

        $this->postJson('/api/gita-order-scrapes/items/'.$itemId.'/sync')
            ->assertOk()
            ->assertJsonPath('data.status', 'synced')
            ->assertJsonPath('data.old_stock', 7)
            ->assertJsonPath('data.new_stock', 6);

        $this->assertDatabaseHas('stock_master', ['id' => $stockMasterId, 'stock_qty' => 6]);
        $this->assertDatabaseHas('gita_order_stock_syncs', ['status' => 'synced', 'quantity' => 1]);
        $this->assertDatabaseCount('marketplace_sync_logs', 2);

        $this->postJson('/api/gita-order-scrapes/items/'.$itemId.'/sync')
            ->assertOk()
            ->assertJsonPath('data.status', 'synced')
            ->assertJsonPath('data.idempotent', true);

        $this->assertDatabaseHas('stock_master', ['id' => $stockMasterId, 'stock_qty' => 6]);
        $this->assertDatabaseCount('marketplace_sync_logs', 2);
    }

    public function test_syncing_all_latest_items_processes_each_pending_order_sku_once(): void
    {
        $stockMasterId = DB::table('stock_master')->insertGetId([
            'internal_sku' => 'INT-40908729245-SAGEE',
            'stock_qty' => 7,
        ]);
        $this->withToken('worker-secret')->postJson('/api/gita-order-scrapes/runs', $this->successPayload())->assertCreated();
        $this->bindSuccessfulMarketplaceSync($stockMasterId);

        $this->postJson('/api/gita-order-scrapes/sync')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.synced', 1);

        $this->assertDatabaseHas('stock_master', ['id' => $stockMasterId, 'stock_qty' => 6]);
        $this->assertDatabaseCount('marketplace_sync_logs', 2);
    }

    public function test_syncing_all_latest_items_refreshes_marketplace_tokens_once_before_pushing_stock(): void
    {
        $stockMasterId = DB::table('stock_master')->insertGetId([
            'internal_sku' => 'INT-40908729245-SAGEE',
            'stock_qty' => 7,
        ]);
        $this->withToken('worker-secret')->postJson('/api/gita-order-scrapes/runs', $this->successPayload())->assertCreated();
        $this->bindSuccessfulMarketplaceSync($stockMasterId);

        $tokens = Mockery::mock(MarketplaceTokenRefreshService::class);
        $tokens->shouldReceive('refreshDueTokens')->once()->andReturn(['status' => 'ok']);
        $this->app->instance(MarketplaceTokenRefreshService::class, $tokens);

        $this->postJson('/api/gita-order-scrapes/sync')
            ->assertOk()
            ->assertJsonPath('data.summary.synced', 1);
    }

    public function test_public_report_items_are_empty_when_the_latest_run_failed(): void
    {
        $this->withToken('worker-secret')
            ->postJson('/api/gita-order-scrapes/runs', $this->successPayload())
            ->assertCreated();

        $this->withToken('worker-secret')
            ->postJson('/api/gita-order-scrapes/runs', [
                'status' => 'failed',
                'started_at' => '2026-08-09T00:01:00.000Z',
                'finished_at' => '2026-08-09T00:01:10.000Z',
                'message' => 'Pengambilan pesanan Gita gagal.',
            ])
            ->assertCreated();

        $this->getJson('/api/gita-order-scrapes/latest')
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->getJson('/api/gita-order-scrapes/items')
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('pagination.total', 0);
    }

    public function test_worker_rejects_partial_or_duplicate_order_line_payloads(): void
    {
        $this->withToken('worker-secret')
            ->postJson('/api/gita-order-scrapes/runs', [
                'status' => 'success',
                'started_at' => '2026-08-09T00:00:00.000Z',
                'finished_at' => '2026-08-09T00:00:10.000Z',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $duplicate = $this->successPayload();
        $duplicate['items'][] = $duplicate['items'][0];

        $this->withToken('worker-secret')
            ->postJson('/api/gita-order-scrapes/runs', $duplicate)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $completed = $this->successPayload();
        $completed['items'][0]['tab_status'] = 'completed';

        $this->withToken('worker-secret')
            ->postJson('/api/gita-order-scrapes/runs', $completed)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.tab_status');

        $this->assertDatabaseCount('gita_order_scrape_runs', 0);
    }

    private function successPayload(): array
    {
        return [
            'status' => 'success',
            'started_at' => '2026-08-09T00:00:00.000Z',
            'finished_at' => '2026-08-09T00:00:10.000Z',
            'items' => [[
                'seller_order_id' => '260808T15MHC24',
                'tab_status' => 'to_ship',
                'seller_sku' => 'INT-40908729245-SAGEE',
                'product_title' => 'PARIS LEGEND HIJABERIES Segiempat',
                'variant_label' => 'Sagee',
                'quantity' => 1,
                'captured_at' => '2026-08-09T00:00:05.000Z',
            ]],
        ];
    }

    private function bindSuccessfulMarketplaceSync(int $stockMasterId): void
    {
        $mapping = (object) ['id' => $stockMasterId, 'stock_qty' => 7, 'internal_sku' => 'INT-40908729245-SAGEE'];
        $service = Mockery::mock(MarketplaceSyncService::class);
        $service->shouldReceive('findSkuMappingByStockMasterId')->andReturn($mapping);
        $service->shouldReceive('pushTargetStock')->twice()->andReturn(['status' => 'success', 'message' => 'ok']);
        $service->shouldReceive('updateLocalStock')->twice()->andReturnUsing(function (object $row, string $marketplace, int $stock): void {
            DB::table('stock_master')->where('id', $row->id)->update(['stock_qty' => $stock]);
        });
        $service->shouldReceive('logSync')->twice()->andReturnUsing(function (?string $source, ?string $target, ?string $sku, ?int $oldStock, ?int $newStock, string $status, ?string $message): int {
            return (int) DB::table('marketplace_sync_logs')->insertGetId([
                'source_marketplace' => $source,
                'target_marketplace' => $target,
                'sku' => $sku,
                'old_stock' => $oldStock,
                'new_stock' => $newStock,
                'status' => $status,
                'message' => $message,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $service->shouldReceive('updateStatus')->twice();
        $service->shouldReceive('canonicalSku')->andReturn('INT-40908729245-SAGEE');
        $this->app->instance(MarketplaceSyncService::class, $service);
    }
}
