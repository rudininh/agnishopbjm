<?php

namespace Tests\Feature;

use App\Services\ShopeeSkuTiktokVariantCleanupService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TiktokReconciliationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_migration_persists_run_and_item_with_unique_item_key(): void
    {
        $this->assertTrue(Schema::hasTable('tiktok_reconciliation_runs'));
        $this->assertTrue(Schema::hasTable('tiktok_reconciliation_run_items'));

        DB::table('tiktok_reconciliation_runs')->insert([
            'id' => '9df1f8af-940c-48d9-a2d8-81f3e264f350',
            'revision' => str_repeat('a', 64),
            'status' => 'ready_for_review',
            'summary' => json_encode(['new_products' => 1]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tiktok_reconciliation_run_items')->insert([
            'run_id' => '9df1f8af-940c-48d9-a2d8-81f3e264f350',
            'item_key' => 'new-product:42',
            'action_type' => 'new_product',
            'status' => 'ready',
            'source_fingerprint' => str_repeat('b', 64),
            'payload' => json_encode(['seller_sku' => 'INT-42-RED']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseCount('tiktok_reconciliation_run_items', 1);
    }

    public function test_reconciliation_columns_accept_cleanup_action_and_preview_statuses(): void
    {
        $runId = 'd52d8541-d526-4731-9013-e24652d79ae4';
        $this->insertRun($runId);

        foreach (['ready', 'unchanged', 'blocked'] as $status) {
            DB::table('tiktok_reconciliation_run_items')->insert([
                'run_id' => $runId,
                'item_key' => 'sku-cleanup:item:model:product:'.$status,
                'action_type' => 'shopee_sku_tiktok_delete',
                'status' => $status,
                'source_fingerprint' => str_repeat('b', 64),
                'payload' => json_encode(['status' => $status], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(
            ['blocked', 'ready', 'unchanged'],
            DB::table('tiktok_reconciliation_run_items')
                ->where('action_type', 'shopee_sku_tiktok_delete')
                ->orderBy('status')
                ->pluck('status')
                ->all(),
        );
    }

    public function test_cleanup_execution_lease_columns_persist_owner_expiry_and_attempts(): void
    {
        $this->assertTrue(Schema::hasColumn('tiktok_reconciliation_run_items', 'execution_owner'));
        $this->assertTrue(Schema::hasColumn('tiktok_reconciliation_run_items', 'execution_lease_until'));
        $this->assertTrue(Schema::hasColumn('tiktok_reconciliation_run_items', 'execution_attempts'));

        $runId = '50d728f3-62e6-419f-8725-611821664081';
        $this->insertRun($runId);
        DB::table('tiktok_reconciliation_run_items')->insert([
            'run_id' => $runId,
            'item_key' => 'sku-cleanup:item:model:product:lease',
            'action_type' => 'shopee_sku_tiktok_delete',
            'status' => 'ready',
            'source_fingerprint' => str_repeat('e', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('tiktok_reconciliation_run_items', [
            'run_id' => $runId,
            'execution_owner' => null,
            'execution_lease_until' => null,
            'execution_attempts' => 0,
        ]);
    }

    public function test_reconciliation_rejects_a_duplicate_run_item_key(): void
    {
        $runId = 'be1ea18e-4e1f-470d-b357-d5cf2d75f4f8';
        $this->insertRun($runId);
        $row = [
            'run_id' => $runId,
            'item_key' => 'sku-cleanup:item:model:product:sku',
            'action_type' => 'shopee_sku_tiktok_delete',
            'status' => 'ready',
            'source_fingerprint' => str_repeat('c', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('tiktok_reconciliation_run_items')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('tiktok_reconciliation_run_items')->insert($row);
    }

    public function test_cleanup_preview_persistence_does_not_serialize_seeded_secret_markers(): void
    {
        $preview = app(ShopeeSkuTiktokVariantCleanupService::class)->createPreview(collect([[
            'tiktok_product_id' => '',
            'Access_Token' => 'marker-access-token',
            'app_SECRET' => 'marker-app-secret',
            'mapping_only_variants' => collect([[
                'shopee_item_id' => '',
                'shopee_model_id' => '',
                'tiktok_sku_id' => '',
                'nested' => [
                    'SIGN' => 'marker-sign',
                    'Shop_Cipher' => 'marker-shop-cipher',
                    'authorization' => 'marker-authorization',
                ],
            ]]),
        ]]));

        $payload = (string) DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $preview['run_id'])
            ->value('payload');
        foreach ([
            'marker-access-token',
            'marker-app-secret',
            'marker-sign',
            'marker-shop-cipher',
            'marker-authorization',
        ] as $marker) {
            $this->assertStringNotContainsString($marker, $payload);
        }
    }

    private function insertRun(string $runId): void
    {
        DB::table('tiktok_reconciliation_runs')->insert([
            'id' => $runId,
            'revision' => str_repeat('a', 64),
            'status' => 'ready_for_review',
            'summary' => json_encode(['eligible' => 1], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
