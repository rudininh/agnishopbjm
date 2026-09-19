<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceApiService;
use App\Services\MarketplaceOrderSyncService;
use App\Services\MarketplaceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ThreeMarketplaceOrderSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('marketplace_sync_logs');
        Schema::create('marketplace_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('source_marketplace')->nullable();
            $table->string('target_marketplace')->nullable();
            $table->string('sku')->nullable();
            $table->integer('old_stock')->nullable();
            $table->integer('new_stock')->nullable();
            $table->string('status')->default('pending');
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function test_gita_shopee_order_reads_gita_stock_and_delivers_to_both_other_accounts(): void
    {
        $api = Mockery::mock(MarketplaceApiService::class);
        $sync = Mockery::mock(MarketplaceSyncService::class);
        $mapping = (object) ['stock_master_id' => null, 'shopee_product_id' => '11', 'shopee_sku' => '22', 'tiktok_product_id' => '33', 'tiktok_sku' => '44'];

        $api->shouldReceive('fetchShopeeOrderDetailForAccount')->once()->with('shopee-gitacollectionbjm', 'GITA-1')->andReturn([
            'status' => 'success', 'order' => ['order_status' => 'READY_TO_SHIP', 'item_list' => [['item_id' => '99', 'model_id' => '88', 'model_sku' => 'SKU-1']]],
        ]);
        $api->shouldReceive('fetchShopeeModelStockForAccount')->once()->with('shopee-gitacollectionbjm', '99', '88')->andReturn(['status' => 'success', 'stock' => 5]);
        $sync->shouldReceive('findSkuMappingByShopeeModel')->once()->andReturn($mapping);
        $sync->shouldReceive('canonicalSku')->once()->andReturn('SKU-1');
        $sync->shouldReceive('pushTargetStockForAccount')->twice()->andReturn(['status' => 'success', 'message' => 'ok']);
        $sync->shouldReceive('logSync')->twice();

        $result = (new MarketplaceOrderSyncService($api, $sync))->processShopeeOrderForAccount('shopee-gitacollectionbjm', 'GITA-1', 'POLL_READY_ORDER');

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['success']);
        $this->assertCount(2, $result['items']);
    }

    public function test_gita_target_failure_does_not_suppress_primary_shopee_target(): void
    {
        $api = Mockery::mock(MarketplaceApiService::class);
        $sync = Mockery::mock(MarketplaceSyncService::class);
        $mapping = (object) ['stock_master_id' => null, 'shopee_product_id' => '11', 'shopee_sku' => '22', 'tiktok_product_id' => '33', 'tiktok_sku' => '44'];

        $api->shouldReceive('fetchShopeeOrderDetailForAccount')->once()->andReturn([
            'status' => 'success', 'order' => ['order_status' => 'READY_TO_SHIP', 'item_list' => [['item_id' => '99', 'model_id' => '88', 'model_sku' => 'SKU-1']]],
        ]);
        $api->shouldReceive('fetchShopeeModelStockForAccount')->once()->andReturn(['status' => 'success', 'stock' => 3]);
        $sync->shouldReceive('findSkuMappingByShopeeModel')->once()->andReturn($mapping);
        $sync->shouldReceive('canonicalSku')->once()->andReturn('SKU-1');
        $sync->shouldReceive('pushTargetStockForAccount')->twice()->andReturnUsing(function (object $unused, string $target): array {
            return $target === 'shopee-gitacollectionbjm' ? ['status' => 'success'] : ['status' => 'error', 'message' => 'TikTok delivery failed'];
        });
        $sync->shouldReceive('logSync')->twice();

        $result = (new MarketplaceOrderSyncService($api, $sync))->processShopeeOrderForAccount('shopee-agnishopbjm', 'AGNI-1', 'POLL_READY_ORDER');

        $this->assertSame('warning', $result['status']);
        $this->assertSame(1, $result['success']);
        $this->assertSame(1, $result['failed']);
    }

    public function test_hydrate_account_listing_ids_populates_tiktok_and_gita_using_id_or_stock_master_id(): void
    {
        Schema::dropIfExists('marketplace_listings');
        Schema::create('marketplace_listings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stock_master_id');
            $table->string('account_key');
            $table->string('remote_product_id');
            $table->string('remote_variant_id')->default('');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('marketplace_listings')->insert([
            [
                'stock_master_id' => 77,
                'account_key' => 'shopee-gitacollectionbjm',
                'remote_product_id' => '1574251194',
                'remote_variant_id' => '998877',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'stock_master_id' => 77,
                'account_key' => 'tiktok-agnishopbjm',
                'remote_product_id' => '1729000111222',
                'remote_variant_id' => '1739000333444',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $api = Mockery::mock(MarketplaceApiService::class);
        $sync = Mockery::mock(MarketplaceSyncService::class);
        $service = new MarketplaceOrderSyncService($api, $sync);

        $mapping = (object) [
            'id' => 77,
            'internal_sku' => 'INT-TEST-77',
            'shopee_product_id' => '11',
            'shopee_sku' => '22',
        ];

        $method = new \ReflectionMethod(MarketplaceOrderSyncService::class, 'hydrateAccountListingIds');
        $method->setAccessible(true);
        $method->invoke($service, $mapping);

        $this->assertSame('1574251194', $mapping->shopee_gita_product_id ?? null);
        $this->assertSame('998877', $mapping->shopee_gita_sku ?? null);
        $this->assertSame('1729000111222', $mapping->tiktok_product_id ?? null);
        $this->assertSame('1739000333444', $mapping->tiktok_sku ?? null);
    }
}
