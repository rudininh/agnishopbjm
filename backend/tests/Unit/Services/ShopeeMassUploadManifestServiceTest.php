<?php

namespace Tests\Unit\Services;

use App\Http\Controllers\OmnichannelController;
use App\Http\Controllers\MarketplaceImportController;
use App\Services\ShopeeMassUploadManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ShopeeMassUploadManifestServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_refreshes_the_shopee_source_cache_without_running_tiktok_sync(): void
    {
        $omnichannel = Mockery::mock(OmnichannelController::class);
        $omnichannel
            ->shouldReceive('syncShopeeProductCachesForMassUpdate')
            ->once()
            ->andReturn([
                'status' => 'ok',
                'shopee' => [
                    'status' => 'ok',
                    'products' => 60,
                    'variants' => 1730,
                ],
                'source_refreshed_at' => '2026-08-15 10:30:00',
            ]);

        $result = (new ShopeeMassUploadManifestService($omnichannel))->refreshSource();

        $this->assertSame(60, $result['products']);
        $this->assertSame(1730, $result['variants']);
        $this->assertSame('2026-08-15 10:30:00', $result['refreshed_at']);
    }

    public function test_fails_closed_when_the_shopee_source_refresh_is_not_complete(): void
    {
        $omnichannel = Mockery::mock(OmnichannelController::class);
        $omnichannel
            ->shouldReceive('syncShopeeProductCachesForMassUpdate')
            ->once()
            ->andReturn([
                'status' => 'partial',
                'shopee' => [
                    'status' => 'partial',
                    'products' => 60,
                    'variants' => 1729,
                ],
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Katalog sumber Shopee tidak dapat disegarkan dengan aman.');

        (new ShopeeMassUploadManifestService($omnichannel))->refreshSource();
    }

    public function test_persists_an_immutable_manifest_for_each_source_and_target_variant(): void
    {
        $now = now();
        $jobId = DB::table('shopee_mass_upload_jobs')->insertGetId([
            'account_key' => config('shopee_mass_upload.account_key'),
            'expected_shop_name' => config('shopee_mass_upload.expected_shop_name'),
            'status' => 'preflight',
            'requested_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $omnichannel = Mockery::mock(OmnichannelController::class);
        $imports = Mockery::mock(MarketplaceImportController::class);
        $imports->shouldReceive('shopeeGitaSourceVariants')->once()->andReturn(collect([
            (object) [
                'item_id' => 'source-item-1',
                'model_id' => 'source-model-1',
                'seller_sku' => 'INT-100-RED',
                'price' => 50000,
                'stock_qty' => 12,
                'raw_image_url' => 'https://cf.shopee.co.id/file/variant-image',
                'product_image_urls' => ['https://cf.shopee.co.id/file/product-image'],
            ],
        ]));
        $imports->shouldReceive('shopeeGitaSalesTargetMappings')->once()->andReturn(collect([
            [
                'source_item_id' => 'source-item-1',
                'source_seller_sku' => 'INT-100-RED',
                'target_item_id' => 'target-item-1',
                'target_model_id' => 'target-model-1',
            ],
        ]));

        $result = (new ShopeeMassUploadManifestService($omnichannel, $imports))->buildForJob($jobId);

        $this->assertSame(1, $result['products']);
        $this->assertSame(1, $result['variants']);
        $this->assertDatabaseHas('shopee_mass_upload_manifests', [
            'job_id' => $jobId,
            'source_item_id' => 'source-item-1',
            'source_model_id' => 'source-model-1',
            'target_item_id' => 'target-item-1',
            'target_model_id' => 'target-model-1',
            'seller_sku' => 'INT-100-RED',
            'price' => 50000,
            'stock_qty' => 12,
        ]);
        $this->assertDatabaseHas('shopee_mass_upload_jobs', [
            'id' => $jobId,
            'expected_product_count' => 1,
            'expected_variant_count' => 1,
        ]);
    }

    public function test_rejects_a_source_variant_without_a_canonical_product_image(): void
    {
        $now = now();
        $jobId = DB::table('shopee_mass_upload_jobs')->insertGetId([
            'account_key' => config('shopee_mass_upload.account_key'),
            'expected_shop_name' => config('shopee_mass_upload.expected_shop_name'),
            'status' => 'preflight',
            'requested_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $imports = Mockery::mock(MarketplaceImportController::class);
        $imports->shouldReceive('shopeeGitaSourceVariants')->once()->andReturn(collect([
            (object) [
                'item_id' => 'source-item-1',
                'model_id' => 'source-model-1',
                'seller_sku' => 'INT-100-RED',
                'price' => 50000,
                'stock_qty' => 12,
                'raw_image_url' => '',
                'product_image_urls' => [],
            ],
        ]));
        $imports->shouldNotReceive('shopeeGitaSalesTargetMappings');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Data gambar sumber Shopee tidak lengkap untuk manifest Mass Update.');

        (new ShopeeMassUploadManifestService(Mockery::mock(OmnichannelController::class), $imports))->buildForJob($jobId);
    }
}
