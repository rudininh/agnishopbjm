<?php

namespace Tests\Feature;

use App\Http\Controllers\MarketplaceImportController;
use App\Services\MarketplaceSyncService;
use App\Services\ShopeeGitaExportCoverageService;
use Mockery;
use Tests\TestCase;

class ShopeeGitaExportCoverageApiTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_sales_target_mapping_exposes_target_names_for_sku_change_detection(): void
    {
        $mapping = collect(app(MarketplaceImportController::class)->shopeeGitaSalesTargetMappings())
            ->first(fn (array $row) => $row['target_item_id'] !== '' && $row['target_model_id'] !== '');

        $this->assertNotNull($mapping);
        $this->assertArrayHasKey('target_product_name', $mapping);
        $this->assertArrayHasKey('target_variant_name', $mapping);
    }

    public function test_coverage_endpoint_returns_revision_summary_and_exceptions(): void
    {
        $controller = Mockery::mock(MarketplaceImportController::class, [
            app(MarketplaceSyncService::class),
            app(ShopeeGitaExportCoverageService::class),
        ])->makePartial();
        $controller->shouldReceive('shopeeGitaSourceVariants')->andReturn(collect([
            (object) $this->source('200', '4', 'INT-200-BLACK', 'Produk Baru', 'Black'),
        ]));
        $controller->shouldReceive('shopeeGitaSalesTargetMappings')->andReturn(collect());
        $controller->shouldReceive('shopeeGitaTemplateMetadata')->andReturn([
            'sales_sha256' => str_repeat('a', 64),
            'sales_last_modified_at' => '2026-08-29T10:00:00+08:00',
        ]);
        $this->app->instance(MarketplaceImportController::class, $controller);

        $this->getJson('/api/marketplace/import/shopee-gita/coverage')
            ->assertOk()
            ->assertJsonPath('data.summary.variants_by_status.new_product', 1)
            ->assertJsonPath('data.items.0.status', 'new_product')
            ->assertJsonStructure(['data' => ['revision', 'template', 'summary', 'items']]);
    }

    private function source(string $itemId, string $modelId, string $sellerSku, string $productName, string $variantName): array
    {
        return [
            'item_id' => $itemId,
            'model_id' => $modelId,
            'seller_sku' => $sellerSku,
            'product_name' => $productName,
            'variant_name' => $variantName,
        ];
    }
}
