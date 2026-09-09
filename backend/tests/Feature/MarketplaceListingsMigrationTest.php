<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketplaceListingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketplace_listings_are_account_aware_and_backfill_primary_mappings(): void
    {
        $this->createStockMasterTable();
        Schema::dropIfExists('marketplace_listings');

        $now = now();
        DB::table('stock_master')->insert([
            'id' => 10,
            'internal_sku' => 'INT-RED',
            'stock_qty' => 7,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('sku_mappings')->insert([
            'stock_master_id' => 10,
            'shopee_item_id' => 'item-1',
            'shopee_model_id' => 'model-1',
            'tiktok_product_id' => 'product-1',
            'tiktok_sku_id' => 'sku-1',
            'seller_sku' => 'INT-RED',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('sku_mappings')->insert([
            'stock_master_id' => 11,
            'shopee_item_id' => 'incomplete-item',
            'shopee_model_id' => '',
            'tiktok_product_id' => 'incomplete-product',
            'tiktok_sku_id' => null,
            'seller_sku' => 'INT-INCOMPLETE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('sku_mappings')->insert([
            'stock_master_id' => 12,
            'shopee_item_id' => 'ambiguous-item',
            'shopee_model_id' => 'ambiguous-model',
            'seller_sku' => 'INT-AMBIGUOUS-ONE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('sku_mappings')->insert([
            'stock_master_id' => 13,
            'shopee_item_id' => 'ambiguous-item',
            'shopee_model_id' => 'ambiguous-model',
            'seller_sku' => 'INT-AMBIGUOUS-TWO',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration = require database_path('migrations/2026_08_31_000001_create_marketplace_listings_table.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('marketplace_listings', [
            'stock_master_id' => 10,
            'account_key' => 'shopee-agnishopbjm',
            'channel' => 'shopee',
            'remote_product_id' => 'item-1',
            'remote_variant_id' => 'model-1',
            'remote_identity_hash' => '369d2a1cca285adf4d8508948a173fd4665c6fbf5dd9c7ca9bfd6713d4376cd9',
            'seller_sku' => 'INT-RED',
        ]);
        $this->assertDatabaseHas('marketplace_listings', [
            'stock_master_id' => 10,
            'account_key' => 'tiktok-agnishopbjm',
            'channel' => 'tiktok',
            'remote_product_id' => 'product-1',
            'remote_variant_id' => 'sku-1',
            'remote_identity_hash' => '7c56cce89f9ce4ec6d36f875551e4bb8a7bf69412028d03a6026b13a7deeb6bd',
            'seller_sku' => 'INT-RED',
        ]);
        $this->assertDatabaseMissing('marketplace_listings', [
            'stock_master_id' => 11,
        ]);
        $this->assertDatabaseMissing('marketplace_listings', [
            'stock_master_id' => 12,
        ]);
        $this->assertDatabaseMissing('marketplace_listings', [
            'stock_master_id' => 13,
        ]);
        $this->assertSame(2, DB::table('marketplace_listings')->count());
    }

    public function test_marketplace_listing_constraints_scope_remote_identity_by_account(): void
    {
        $this->createStockMasterTable();
        Schema::dropIfExists('marketplace_listings');

        $migration = require database_path('migrations/2026_08_31_000001_create_marketplace_listings_table.php');
        $migration->up();

        $now = now();
        $listing = [
            'stock_master_id' => 10,
            'channel' => 'shopee',
            'remote_product_id' => 'item-1',
            'remote_variant_id' => 'model-1',
            'remote_identity_hash' => hash('sha256', json_encode(['shopee', 'item-1', 'model-1'], JSON_THROW_ON_ERROR)),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('marketplace_listings')->insert($listing + ['account_key' => 'shopee-agnishopbjm']);
        DB::table('marketplace_listings')->insert($listing + [
            'stock_master_id' => 11,
            'account_key' => 'shopee-gitacollectionbjm',
        ]);

        $this->assertSame(2, DB::table('marketplace_listings')->count());

        try {
            DB::table('marketplace_listings')->insert($listing + [
                'account_key' => 'shopee-agnishopbjm',
                'remote_product_id' => 'item-2',
                'remote_variant_id' => 'model-2',
                'remote_identity_hash' => hash('sha256', json_encode(['shopee', 'item-2', 'model-2'], JSON_THROW_ON_ERROR)),
            ]);
            $this->fail('Expected duplicate stock master and account key to be rejected.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table('marketplace_listings')->insert($listing + [
                'stock_master_id' => 12,
                'account_key' => 'shopee-agnishopbjm',
            ]);
            $this->fail('Expected duplicate remote identity in one account to be rejected.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table('marketplace_listings')->insert($listing + [
                'stock_master_id' => 13,
                'account_key' => 'shopee-another-account',
                'remote_product_id' => 'item-3',
                'remote_variant_id' => 'model-3',
                'remote_identity_hash' => hash('sha256', json_encode(['shopee', 'item-3', 'model-3'], JSON_THROW_ON_ERROR)),
            ]);
            $this->fail('Expected an unregistered account key to be rejected.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_backfill_rerun_preserves_existing_listing_state(): void
    {
        $this->createStockMasterTable();
        Schema::dropIfExists('marketplace_listings');

        $now = now();
        DB::table('sku_mappings')->insert([
            'stock_master_id' => 20,
            'shopee_item_id' => 'stateful-item',
            'shopee_model_id' => 'stateful-model',
            'seller_sku' => 'LEGACY-SKU',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration = require database_path('migrations/2026_08_31_000001_create_marketplace_listings_table.php');
        $migration->up();
        DB::table('marketplace_listings')
            ->where('stock_master_id', 20)
            ->where('account_key', 'shopee-agnishopbjm')
            ->update([
                'seller_sku' => 'CURATED-SKU',
                'warehouse_id' => 'WAREHOUSE-1',
                'is_active' => false,
                'updated_at' => now()->subDay(),
            ]);
        $before = DB::table('marketplace_listings')
            ->where('stock_master_id', 20)
            ->where('account_key', 'shopee-agnishopbjm')
            ->first();

        $migration->up();

        $after = DB::table('marketplace_listings')
            ->where('stock_master_id', 20)
            ->where('account_key', 'shopee-agnishopbjm')
            ->first();

        $this->assertEquals($before, $after);
    }

    public function test_backfill_skips_a_remote_identity_owned_by_another_stock_master(): void
    {
        $this->createStockMasterTable();
        Schema::dropIfExists('marketplace_listings');

        $migration = require database_path('migrations/2026_08_31_000001_create_marketplace_listings_table.php');
        $migration->up();

        $now = now();
        DB::table('marketplace_listings')->insert([
            'stock_master_id' => 30,
            'account_key' => 'shopee-agnishopbjm',
            'channel' => 'shopee',
            'remote_product_id' => 'owned-item',
            'remote_variant_id' => 'owned-model',
            'remote_identity_hash' => hash('sha256', json_encode(['shopee', 'owned-item', 'owned-model'], JSON_THROW_ON_ERROR)),
            'seller_sku' => 'CURATED-OWNER',
            'warehouse_id' => 'WAREHOUSE-2',
            'is_active' => false,
            'created_at' => $now,
            'updated_at' => now()->subDay(),
        ]);
        DB::table('sku_mappings')->insert([
            'stock_master_id' => 31,
            'shopee_item_id' => 'owned-item',
            'shopee_model_id' => 'owned-model',
            'seller_sku' => 'LEGACY-CONFLICT',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $before = DB::table('marketplace_listings')->where('stock_master_id', 30)->first();

        $migration->up();

        $this->assertEquals($before, DB::table('marketplace_listings')->where('stock_master_id', 30)->first());
        $this->assertDatabaseMissing('marketplace_listings', [
            'stock_master_id' => 31,
            'account_key' => 'shopee-agnishopbjm',
        ]);
    }

    private function createStockMasterTable(): void
    {
        if (! Schema::hasTable('stock_master')) {
            Schema::create('stock_master', function (Blueprint $table): void {
                $table->id();
                $table->string('internal_sku')->unique();
                $table->integer('stock_qty')->default(0);
                $table->timestamps();
            });
        }
    }
}
