<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceApiService;
use App\Services\MarketplaceSyncService;
use App\Services\ThreeMarketplaceStockReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ThreeMarketplaceStockReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_reads_shopee_source_without_stock_master_and_pushes_targets(): void
    {
        Schema::dropIfExists('marketplace_listings');
        Schema::create('marketplace_listings', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('stock_master_id'); $table->string('account_key'); $table->string('remote_product_id'); $table->string('remote_variant_id'); $table->boolean('is_active')->default(true);
        });
        DB::table('marketplace_listings')->insert([
            ['stock_master_id' => 1, 'account_key' => 'shopee-agnishopbjm', 'remote_product_id' => '11', 'remote_variant_id' => '111', 'is_active' => true],
            ['stock_master_id' => 1, 'account_key' => 'shopee-gitacollectionbjm', 'remote_product_id' => '22', 'remote_variant_id' => '222', 'is_active' => true],
            ['stock_master_id' => 1, 'account_key' => 'tiktok-agnishopbjm', 'remote_product_id' => '33', 'remote_variant_id' => '333', 'is_active' => true],
        ]);
        $api = Mockery::mock(MarketplaceApiService::class);
        $sync = Mockery::mock(MarketplaceSyncService::class);
        $api->shouldReceive('fetchShopeeModelStockForAccount')->once()->with('shopee-agnishopbjm', '11', '111')->andReturn(['status' => 'success', 'stock' => 6]);
        $api->shouldReceive('fetchShopeeModelStockForAccount')->once()->with('shopee-gitacollectionbjm', '22', '222')->andReturn(['status' => 'success', 'stock' => 6]);
        Schema::create('tiktok_products', function (Blueprint $table): void { $table->id(); $table->string('product_id'); $table->string('sku_id'); $table->integer('stock_qty')->nullable(); $table->boolean('is_active')->default(true); });
        DB::table('tiktok_products')->insert(['product_id' => '33', 'sku_id' => '333', 'stock_qty' => 6, 'is_active' => true]);
        $sync->shouldReceive('pushTargetStockForAccount')->twice()->andReturn(['status' => 'success']);

        $result = (new ThreeMarketplaceStockReconciliationService($api, $sync))->reconcile();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(2, $result['pushed']);
        $this->assertSame(0, $result['failed']);
    }

    public function test_reconciliation_skips_conflicting_valid_source_stocks(): void
    {
        Schema::dropIfExists('marketplace_listings');
        Schema::create('marketplace_listings', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('stock_master_id'); $table->string('account_key'); $table->string('remote_product_id'); $table->string('remote_variant_id'); $table->boolean('is_active')->default(true);
        });
        DB::table('marketplace_listings')->insert([
            ['stock_master_id' => 1, 'account_key' => 'shopee-agnishopbjm', 'remote_product_id' => '11', 'remote_variant_id' => '111', 'is_active' => true],
            ['stock_master_id' => 1, 'account_key' => 'shopee-gitacollectionbjm', 'remote_product_id' => '22', 'remote_variant_id' => '222', 'is_active' => true],
        ]);
        $api = Mockery::mock(MarketplaceApiService::class);
        $sync = Mockery::mock(MarketplaceSyncService::class);
        $api->shouldReceive('fetchShopeeModelStockForAccount')->with('shopee-agnishopbjm', '11', '111')->andReturn(['status' => 'success', 'stock' => 6]);
        $api->shouldReceive('fetchShopeeModelStockForAccount')->with('shopee-gitacollectionbjm', '22', '222')->andReturn(['status' => 'success', 'stock' => 4]);
        $sync->shouldNotReceive('pushTargetStockForAccount');

        $result = (new ThreeMarketplaceStockReconciliationService($api, $sync))->reconcile();

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['pushed']);
    }

    public function test_reconciliation_skips_when_source_stock_is_unavailable_without_writing_zero(): void
    {
        Schema::dropIfExists('marketplace_listings');
        Schema::create('marketplace_listings', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('stock_master_id'); $table->string('account_key'); $table->string('remote_product_id'); $table->string('remote_variant_id'); $table->boolean('is_active')->default(true);
        });
        DB::table('marketplace_listings')->insert(['stock_master_id' => 1, 'account_key' => 'shopee-agnishopbjm', 'remote_product_id' => '11', 'remote_variant_id' => '111', 'is_active' => true]);
        $api = Mockery::mock(MarketplaceApiService::class);
        $sync = Mockery::mock(MarketplaceSyncService::class);
        $api->shouldReceive('fetchShopeeModelStockForAccount')->once()->andReturn(['status' => 'error', 'message' => 'API unavailable']);
        $sync->shouldNotReceive('pushTargetStockForAccount');

        $result = (new ThreeMarketplaceStockReconciliationService($api, $sync))->reconcile();

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['pushed']);
    }
}
