<?php

namespace Tests\Feature;

use App\Http\Controllers\MarketplaceImportController;
use App\Services\MarketplaceSyncService;
use App\Services\ShopeeGitaExportCoverageService;
use Illuminate\Support\Facades\File;
use Mockery;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesMinimalShopeeWorkbook;
use Tests\TestCase;
use ZipArchive;

class ShopeeGitaExportCoverageApiTest extends TestCase
{
    use CreatesMinimalShopeeWorkbook;

    protected function tearDown(): void
    {
        File::delete([
            storage_path('framework/testing/coverage-sales.xlsx'),
            storage_path('framework/testing/coverage-basic-info.xlsx'),
            storage_path('framework/testing/coverage-media-info.xlsx'),
            storage_path('framework/testing/coverage-shipping-info.xlsx'),
            storage_path('framework/testing/coverage-dts-info.xlsx'),
            storage_path('framework/testing/coverage-republish-items.xlsx'),
            storage_path('framework/testing/coverage-duplicate.xlsx'),
            storage_path('framework/testing/coverage-package.xlsx'),
        ]);
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

    public function test_sales_filter_keeps_only_ready_target_item_model_pairs(): void
    {
        $path = storage_path('framework/testing/coverage-sales.xlsx');
        $this->createMinimalShopeeWorkbook($path, [
            ['A' => 'target-1', 'C' => 'model-1', 'E' => 'Psource-1', 'F' => 'INT-1-RED'],
            ['A' => 'target-2', 'C' => 'model-2', 'E' => 'Psource-2', 'F' => 'INT-2-BLACK'],
        ]);

        $this->invokeWorkbookFilter($path, 'sales-info', [
            ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
        ]);

        $this->assertSame([
            ['A' => 'target-1', 'C' => 'model-1', 'E' => 'Psource-1', 'F' => 'INT-1-RED'],
        ], $this->readMinimalShopeeWorkbookRows($path));
    }

    public function test_basic_and_media_filters_keep_one_row_per_ready_target_product(): void
    {
        foreach (['basic-info', 'media-info'] as $type) {
            $path = storage_path("framework/testing/coverage-{$type}.xlsx");
            $this->createMinimalShopeeWorkbook($path, [
                ['A' => 'target-1', 'C' => 'Produk 1'],
                ['A' => 'target-2', 'C' => 'Produk 2'],
            ]);

            $this->invokeWorkbookFilter($path, $type, [
                ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
                ['target_item_id' => 'target-1', 'target_model_id' => 'model-2'],
            ]);

            $this->assertSame([
                ['A' => 'target-1', 'C' => 'Produk 1'],
            ], $this->readMinimalShopeeWorkbookRows($path));
        }
    }

    public function test_shipping_and_dts_filters_require_the_target_item_model_pair(): void
    {
        foreach (['shipping-info', 'dts-info'] as $type) {
            $path = storage_path("framework/testing/coverage-{$type}.xlsx");
            $this->createMinimalShopeeWorkbook($path, [
                ['A' => 'target-1', 'D' => 'model-1'],
                ['A' => 'target-1', 'D' => 'model-2'],
            ]);

            $this->invokeWorkbookFilter($path, $type, [
                ['target_item_id' => 'target-1', 'target_model_id' => 'model-2'],
            ]);

            $this->assertSame([
                ['A' => 'target-1', 'D' => 'model-2'],
            ], $this->readMinimalShopeeWorkbookRows($path));
        }
    }

    public function test_republish_filter_removes_every_data_row_from_row_four(): void
    {
        $path = storage_path('framework/testing/coverage-republish-items.xlsx');
        $this->createMinimalShopeeWorkbook($path, [
            ['A' => 'target-1'],
            ['A' => 'target-2'],
        ], 3);

        $this->invokeWorkbookFilter($path, 'republish-items', [
            ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
        ]);

        $this->assertSame([], $this->readMinimalShopeeWorkbookRows($path, 4));
    }

    public function test_filter_rejects_duplicate_ready_target_pairs(): void
    {
        $path = storage_path('framework/testing/coverage-duplicate.xlsx');
        $this->createMinimalShopeeWorkbook($path, [['A' => 'target-1', 'C' => 'model-1']]);

        try {
            $this->invokeWorkbookFilter($path, 'sales-info', [
                ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
                ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
            ]);
            $this->fail('Duplicate target pair was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_filter_preserves_package_headers_styles_and_formulas_and_recalculates_ranges(): void
    {
        $path = storage_path('framework/testing/coverage-package.xlsx');
        $this->createMinimalShopeeWorkbook($path, [
            ['A' => 'target-1', 'C' => 'model-1', 'F' => 'discarded'],
            ['A' => 'target-2', 'C' => 'model-2', 'F' => 'retained'],
        ]);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $stylesBefore = $zip->getFromName('xl/styles.xml');
        $zip->close();

        $this->invokeWorkbookFilter($path, 'sales-info', [
            ['target_item_id' => 'target-2', 'target_model_id' => 'model-2'],
        ]);

        $this->assertTrue($zip->open($path) === true);
        $this->assertSame($stylesBefore, $zip->getFromName('xl/styles.xml'));
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/sharedStrings.xml'] as $entry) {
            $this->assertNotFalse($zip->getFromName($entry));
        }
        $sheetXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $dom = new \DOMDocument();
        $dom->loadXML($sheetXml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $this->assertSame('1', $xpath->evaluate('string(//x:c[@r="A1"]/@s)'));
        $this->assertSame('1+1', $xpath->evaluate('string(//x:c[@r="B1"]/x:f)'));
        $this->assertSame(1, $xpath->query('//x:sheetData/x:row[@r="7"]')->count());
        $this->assertSame(0, $xpath->query('//x:sheetData/x:row[@r="8"]')->count());
        $this->assertSame(1, $xpath->query('//x:c[@r="A7"]')->count());
        $this->assertSame(1, $xpath->query('//x:c[@r="C7"]')->count());
        $this->assertSame(1, $xpath->query('//x:c[@r="F7"]')->count());
        $this->assertSame('A1:F7', $xpath->evaluate('string(//x:dimension/@ref)'));
        $this->assertSame('A6:F7', $xpath->evaluate('string(//x:autoFilter/@ref)'));
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

    private function invokeWorkbookFilter(string $path, string $type, array $readyTargets): void
    {
        $method = new ReflectionMethod(MarketplaceImportController::class, 'filterShopeeGitaWorkbook');
        $method->invoke(app(MarketplaceImportController::class), $path, $type, $readyTargets);
    }
}
