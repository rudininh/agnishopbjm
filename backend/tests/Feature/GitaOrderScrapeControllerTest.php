<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
}
