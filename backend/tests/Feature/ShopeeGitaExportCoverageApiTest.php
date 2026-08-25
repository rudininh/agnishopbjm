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

    private string $templateDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->templateDirectory = storage_path('framework/testing/shopee-gita-coverage-templates');
        File::deleteDirectory($this->templateDirectory);
        File::ensureDirectoryExists($this->templateDirectory);
        config()->set('shopee_mass_upload.template_directory', $this->templateDirectory);

        $this->createMinimalShopeeWorkbook($this->templateDirectory.'/mass_update_basic_info.xlsx', [
            ['A' => 'target-1', 'B' => 'Psource-1', 'C' => 'Ready Product'],
            ['A' => 'target-2', 'B' => 'Psource-2', 'C' => 'Rejected Product'],
        ]);
        $this->createMinimalShopeeWorkbook($this->templateDirectory.'/mass_update_sales_info.xlsx', [
            ['A' => 'target-1', 'C' => 'model-1', 'E' => 'Psource-1', 'F' => 'INT-READY'],
            ['A' => 'target-2', 'C' => 'model-2', 'E' => 'Psource-2', 'F' => 'INT-REJECTED'],
        ]);
        $this->createMinimalShopeeWorkbook($this->templateDirectory.'/mass_update_media_info.xlsx', [
            ['A' => 'target-1', 'B' => 'Psource-1', 'C' => 'Ready Product'],
            ['A' => 'target-2', 'B' => 'Psource-2', 'C' => 'Rejected Product'],
        ]);
        $this->createMinimalShopeeWorkbook($this->templateDirectory.'/mass_update_shipping_info.xlsx', [
            ['A' => 'target-1', 'D' => 'model-1'],
            ['A' => 'target-2', 'D' => 'model-2'],
        ]);
        $this->createMinimalShopeeWorkbook($this->templateDirectory.'/mass_update_dts_info.xlsx', [
            ['A' => 'target-1', 'D' => 'model-1'],
            ['A' => 'target-2', 'D' => 'model-2'],
        ]);
        $this->createMinimalShopeeWorkbook($this->templateDirectory.'/mass_republish_items.xlsx', [
            ['A' => 'target-1'],
            ['A' => 'target-2'],
        ], 3);
    }

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
            storage_path('framework/testing/coverage-invalid-ready.xlsx'),
            storage_path('framework/testing/coverage-duplicate-product.xlsx'),
            storage_path('framework/testing/coverage-duplicate-variant.xlsx'),
            storage_path('framework/testing/coverage-moved-formula.xlsx'),
            storage_path('framework/testing/coverage-unsupported-type.xlsx'),
            storage_path('framework/testing/coverage-tuple-collision.xlsx'),
            storage_path('framework/testing/coverage-in-place-formula.xlsx'),
            storage_path('framework/testing/filtered-sales.xlsx'),
        ]);
        File::deleteDirectory($this->templateDirectory);
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

    public function test_sales_target_mapping_forward_fills_parent_source_item_for_variant_rows(): void
    {
        $this->createMinimalShopeeWorkbook($this->templateDirectory.'/mass_update_sales_info.xlsx', [
            [
                'A' => 'target-1', 'B' => 'Produk 1', 'C' => 'model-1', 'D' => 'Red',
                'E' => 'P100', 'F' => 'INT-100-RED',
            ],
            [
                'A' => 'target-1', 'B' => 'Produk 1', 'C' => 'model-2', 'D' => 'Blue',
                'F' => 'INT-100-BLUE',
            ],
            [
                'A' => 'target-2', 'B' => 'Produk 2', 'C' => 'model-3', 'D' => 'Black',
                'E' => 'P200', 'F' => 'INT-200-BLACK',
            ],
            [
                'A' => 'target-3', 'B' => 'Produk 3', 'C' => 'model-4', 'D' => 'White',
                'F' => 'INT-300-WHITE',
            ],
        ]);

        $mappings = collect(app(MarketplaceImportController::class)->shopeeGitaSalesTargetMappings());

        $this->assertSame(['100', '100', '200', ''], $mappings->pluck('source_item_id')->all());
        $this->assertSame(
            ['INT-100-RED', 'INT-100-BLUE', 'INT-200-BLACK', 'INT-300-WHITE'],
            $mappings->pluck('source_seller_sku')->all(),
        );
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

    public function test_every_manual_download_rejects_missing_or_stale_revision(): void
    {
        $snapshot = $this->partialCoverageSnapshot();
        $this->bindCoverageSnapshot($snapshot);
        $paths = [
            '/api/marketplace/import/shopee-gita/mass-update',
            '/api/marketplace/import/shopee-gita/mass-update/basic-info',
            '/api/marketplace/import/shopee-gita/mass-update/sales-info',
            '/api/marketplace/import/shopee-gita/mass-update/media-info',
            '/api/marketplace/import/shopee-gita/mass-update/shipping-info',
            '/api/marketplace/import/shopee-gita/mass-update/dts-info',
            '/api/marketplace/import/shopee-gita/mass-update/republish-items',
            '/api/marketplace/import/shopee-gita/exceptions',
        ];

        foreach ($paths as $path) {
            $this->get($path)->assertStatus(409);
            $this->get($path.'?revision=stale')->assertStatus(409);
        }
    }

    public function test_exception_csv_contains_every_non_ready_source_row_as_utf8_csv(): void
    {
        $snapshot = $this->partialCoverageSnapshot();
        $this->bindCoverageSnapshot($snapshot);

        $response = $this->get('/api/marketplace/import/shopee-gita/exceptions?revision='.$snapshot['revision']);
        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Khiban series', $csv);
        $this->assertStringContainsString('NINJA NON RESLETING', $csv);
        $this->assertStringNotContainsString('Ready Product', $csv);
        $this->assertSame(2, substr_count($csv, 'new_product'));
        $this->assertStringContainsString('"Khiban series, ""Premium"""', $csv);
    }

    public function test_download_headers_and_filenames_identify_partial_coverage(): void
    {
        $snapshot = $this->partialCoverageSnapshot();
        $this->bindCoverageSnapshot($snapshot);

        $exceptions = $this->get('/api/marketplace/import/shopee-gita/exceptions?revision='.$snapshot['revision']);
        $exceptions->assertOk()
            ->assertHeader('X-Agni-Coverage-Status', 'partial')
            ->assertHeader('X-Agni-Ready-Variants', '1')
            ->assertHeader('X-Agni-Exception-Variants', '2');
        $this->assertMatchesRegularExpression(
            '/attachment; filename=shopee_gita_exceptions_\d{8}_\d{6}\.csv/',
            (string) $exceptions->headers->get('content-disposition')
        );

        $individual = $this->get('/api/marketplace/import/shopee-gita/mass-update/sales-info?revision='.$snapshot['revision']);
        $individual->assertOk()
            ->assertHeader('X-Agni-Coverage-Status', 'partial')
            ->assertHeader('X-Agni-Ready-Variants', '1')
            ->assertHeader('X-Agni-Exception-Variants', '2')
            ->assertDownload('mass_update_sales_info.xlsx');
    }

    public function test_mass_update_zip_contains_filtered_workbooks_and_coverage_report(): void
    {
        $snapshot = $this->partialCoverageSnapshot();
        $this->bindCoverageSnapshot($snapshot);

        $response = $this->get('/api/marketplace/import/shopee-gita/mass-update?revision='.$snapshot['revision']);
        $response->assertOk()
            ->assertHeader('X-Agni-Coverage-Status', 'partial')
            ->assertHeader('X-Agni-Ready-Variants', '1')
            ->assertHeader('X-Agni-Exception-Variants', '2');
        $this->assertMatchesRegularExpression(
            '/attachment; filename=shopee_gita_mass_update_partial_\d{8}_\d{6}\.zip/',
            (string) $response->headers->get('content-disposition')
        );
        $archivePath = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($archivePath) === true);
        $entries = collect(range(0, $zip->numFiles - 1))
            ->map(fn (int $index) => $zip->getNameIndex($index))
            ->sort()
            ->values()
            ->all();
        $this->assertSame([
            'coverage_report.csv',
            'mass_republish_items.xlsx',
            'mass_update_basic_info.xlsx',
            'mass_update_dts_info.xlsx',
            'mass_update_media_info.xlsx',
            'mass_update_sales_info.xlsx',
            'mass_update_shipping_info.xlsx',
        ], $entries);
        $report = $zip->getFromName('coverage_report.csv');
        $salesBytes = $zip->getFromName('mass_update_sales_info.xlsx');
        $zip->close();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $report);
        $this->assertStringContainsString('Khiban series', $report);
        $this->assertStringContainsString('NINJA NON RESLETING', $report);
        $salesPath = storage_path('framework/testing/filtered-sales.xlsx');
        File::put($salesPath, $salesBytes);
        $this->assertSame([
            ['A' => 'target-1', 'C' => 'model-1', 'E' => 'Psource-1', 'F' => 'INT-READY'],
        ], $this->readMinimalShopeeWorkbookRows($salesPath));
    }

    public function test_complete_download_uses_complete_coverage_headers_and_zip_filename(): void
    {
        $snapshot = $this->partialCoverageSnapshot();
        $snapshot['items'] = [$snapshot['items'][0]];
        $snapshot['summary']['exception_variants'] = 0;
        $snapshot['summary']['ready_variants'] = 1;
        $this->bindCoverageSnapshot($snapshot);

        $response = $this->get('/api/marketplace/import/shopee-gita/mass-update?revision='.$snapshot['revision']);
        $response->assertOk()
            ->assertHeader('X-Agni-Coverage-Status', 'complete')
            ->assertHeader('X-Agni-Ready-Variants', '1')
            ->assertHeader('X-Agni-Exception-Variants', '0');
        $this->assertMatchesRegularExpression(
            '/attachment; filename=shopee_gita_mass_update_\d{8}_\d{6}\.zip/',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_individual_sales_download_contains_only_ready_target_pairs(): void
    {
        $snapshot = $this->partialCoverageSnapshot();
        $this->bindCoverageSnapshot($snapshot);

        $response = $this->get('/api/marketplace/import/shopee-gita/mass-update/sales-info?revision='.$snapshot['revision']);
        $response->assertOk();

        $this->assertSame([
            ['A' => 'target-1', 'C' => 'model-1', 'E' => 'Psource-1', 'F' => 'INT-READY'],
        ], $this->readMinimalShopeeWorkbookRows($response->baseResponse->getFile()->getPathname()));
    }

    public function test_binary_downloads_leave_no_unique_temporary_workspaces_after_send(): void
    {
        $snapshot = $this->partialCoverageSnapshot();
        $this->bindCoverageSnapshot($snapshot);
        $before = $this->shopeeGitaTemporaryArtifacts();

        $zip = $this->get('/api/marketplace/import/shopee-gita/mass-update?revision='.$snapshot['revision']);
        $workbook = $this->get('/api/marketplace/import/shopee-gita/mass-update/sales-info?revision='.$snapshot['revision']);
        $responsePaths = [
            $zip->baseResponse->getFile()->getPathname(),
            $workbook->baseResponse->getFile()->getPathname(),
        ];

        ob_start();
        try {
            $zip->baseResponse->sendContent();
            $workbook->baseResponse->sendContent();
        } finally {
            ob_end_clean();
        }

        foreach ($responsePaths as $path) {
            $this->assertFileDoesNotExist($path);
        }

        $after = $this->shopeeGitaTemporaryArtifacts();
        $newArtifacts = array_values(array_diff($after, $before));
        try {
            $this->assertSame([], $newArtifacts);
        } finally {
            foreach ($newArtifacts as $artifact) {
                File::isDirectory($artifact) ? File::deleteDirectory($artifact) : File::delete($artifact);
            }
        }
    }

    public function test_failed_binary_generation_leaves_no_temporary_artifacts(): void
    {
        $snapshot = $this->partialCoverageSnapshot();
        $snapshot['ready_targets'][] = $snapshot['ready_targets'][0];
        $this->bindCoverageSnapshot($snapshot);
        $before = $this->shopeeGitaTemporaryArtifacts();

        $this->get('/api/marketplace/import/shopee-gita/mass-update?revision='.$snapshot['revision'])
            ->assertStatus(422);
        $this->get('/api/marketplace/import/shopee-gita/mass-update/sales-info?revision='.$snapshot['revision'])
            ->assertStatus(422);

        $after = $this->shopeeGitaTemporaryArtifacts();
        $newArtifacts = array_values(array_diff($after, $before));
        try {
            $this->assertSame([], $newArtifacts);
        } finally {
            foreach ($newArtifacts as $artifact) {
                File::isDirectory($artifact) ? File::deleteDirectory($artifact) : File::delete($artifact);
            }
        }
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

        $this->assertWorkbookRejectedWithoutMutation($path, 'sales-info', [
            ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
            ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
        ], 'duplicate ready target pair');
    }

    public function test_filter_rejects_malformed_ready_target_identities_without_mutating_workbook(): void
    {
        $path = storage_path('framework/testing/coverage-invalid-ready.xlsx');
        $cases = [
            'missing item' => [['target_model_id' => 'model-1']],
            'missing model' => [['target_item_id' => 'target-1']],
            'blank item' => [['target_item_id' => '  ', 'target_model_id' => 'model-1']],
            'blank model' => [['target_item_id' => 'target-1', 'target_model_id' => "\t"]],
            'array item' => [['target_item_id' => [], 'target_model_id' => 'model-1']],
            'object model' => [['target_item_id' => 'target-1', 'target_model_id' => (object) ['id' => 'model-1']]],
            'scalar row' => ['target-1|model-1'],
            'object row' => [(object) ['target_item_id' => 'target-1', 'target_model_id' => 'model-1']],
        ];

        foreach ($cases as $case => $readyTargets) {
            $this->createMinimalShopeeWorkbook($path, [
                ['A' => '', 'C' => '', 'F' => 'blank-row'],
                ['A' => 'target-1', 'C' => 'model-1', 'F' => 'valid-row'],
            ]);

            $this->assertWorkbookRejectedWithoutMutation($path, 'sales-info', $readyTargets, $case);
        }
    }

    public function test_product_filters_reject_duplicate_permitted_workbook_identities_without_mutation(): void
    {
        $path = storage_path('framework/testing/coverage-duplicate-product.xlsx');

        foreach (['basic-info', 'media-info'] as $type) {
            $this->createMinimalShopeeWorkbook($path, [
                ['A' => 'target-1', 'C' => 'Produk 1'],
                ['A' => 'target-1', 'C' => 'Produk 1 Duplikat'],
                ['A' => 'target-2', 'C' => 'Produk 2'],
            ]);

            $this->assertWorkbookRejectedWithoutMutation($path, $type, [
                ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
            ], $type);
        }
    }

    public function test_variant_filters_reject_duplicate_permitted_workbook_identities_without_mutation(): void
    {
        $path = storage_path('framework/testing/coverage-duplicate-variant.xlsx');

        foreach (['sales-info' => 'C', 'shipping-info' => 'D', 'dts-info' => 'D'] as $type => $modelColumn) {
            $this->createMinimalShopeeWorkbook($path, [
                ['A' => 'target-1', $modelColumn => 'model-1', 'F' => 'first'],
                ['A' => 'target-1', $modelColumn => 'model-1', 'F' => 'duplicate'],
                ['A' => 'target-2', $modelColumn => 'model-2', 'F' => 'other'],
            ]);

            $this->assertWorkbookRejectedWithoutMutation($path, $type, [
                ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
            ], $type);
        }
    }

    public function test_filter_rejects_a_retained_formula_data_row_that_would_move_without_mutating_workbook(): void
    {
        $path = storage_path('framework/testing/coverage-moved-formula.xlsx');
        $this->createMinimalShopeeWorkbook($path, [
            ['A' => 'target-1', 'C' => 'model-1', 'F' => 'discarded'],
            ['A' => 'target-2', 'C' => 'model-2', 'F' => 'formula-result'],
        ]);
        $this->setMinimalShopeeWorkbookFormula($path, 'F8', 'A8&"-formula"', 'formula-result');

        $this->assertWorkbookRejectedWithoutMutation($path, 'sales-info', [
            ['target_item_id' => 'target-2', 'target_model_id' => 'model-2'],
        ], 'moved formula row');
    }

    public function test_filter_preserves_a_retained_formula_data_row_that_stays_in_place(): void
    {
        $path = storage_path('framework/testing/coverage-in-place-formula.xlsx');
        $this->createMinimalShopeeWorkbook($path, [
            ['A' => 'target-1', 'C' => 'model-1', 'F' => 'formula-result'],
            ['A' => 'target-2', 'C' => 'model-2', 'F' => 'discarded'],
        ]);
        $this->setMinimalShopeeWorkbookFormula($path, 'F7', 'A7&"-formula"', 'formula-result');
        $formula = $this->readMinimalShopeeWorkbookFormula($path, 'F7');

        $this->invokeWorkbookFilter($path, 'sales-info', [
            ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
        ]);

        $this->assertSame('A7&"-formula"', $formula);
        $this->assertSame($formula, $this->readMinimalShopeeWorkbookFormula($path, 'F7'));
        $this->assertSame('target-1', $this->readMinimalShopeeWorkbookRows($path)[0]['A']);
    }

    public function test_filter_rejects_an_unsupported_workbook_type_without_mutating_workbook(): void
    {
        $path = storage_path('framework/testing/coverage-unsupported-type.xlsx');
        $this->createMinimalShopeeWorkbook($path, [
            ['A' => 'target-1', 'C' => 'model-1'],
        ]);

        $this->assertWorkbookRejectedWithoutMutation($path, 'unknown-info', [
            ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
        ], 'unsupported workbook type');
    }

    public function test_sales_filter_treats_target_item_and_model_as_a_collision_free_tuple(): void
    {
        $path = storage_path('framework/testing/coverage-tuple-collision.xlsx');
        $this->createMinimalShopeeWorkbook($path, [
            ['A' => 'item|part', 'C' => 'model', 'F' => 'permitted'],
            ['A' => 'item', 'C' => 'part|model', 'F' => 'not-permitted'],
        ]);

        $this->invokeWorkbookFilter($path, 'sales-info', [
            ['target_item_id' => 'item|part', 'target_model_id' => 'model'],
        ]);

        $this->assertSame([
            ['A' => 'item|part', 'C' => 'model', 'F' => 'permitted'],
        ], $this->readMinimalShopeeWorkbookRows($path));
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

    private function bindCoverageSnapshot(array $snapshot): void
    {
        $controller = Mockery::mock(MarketplaceImportController::class, [
            app(MarketplaceSyncService::class),
            app(ShopeeGitaExportCoverageService::class),
        ])->makePartial()->shouldAllowMockingProtectedMethods();
        $controller->shouldReceive('currentShopeeGitaCoverage')->andReturn($snapshot);
        $this->app->instance(MarketplaceImportController::class, $controller);
    }

    private function partialCoverageSnapshot(): array
    {
        return [
            'revision' => str_repeat('b', 64),
            'generated_at' => '2026-08-29T10:00:00+00:00',
            'template' => [
                'sales_sha256' => str_repeat('a', 64),
                'sales_last_modified_at' => '2026-08-29T10:00:00+08:00',
            ],
            'summary' => [
                'source_products' => 3,
                'source_variants' => 3,
                'ready_products' => 1,
                'ready_variants' => 1,
                'exception_products' => 2,
                'exception_variants' => 2,
                'products_by_status' => [
                    'mass_update_ready' => 1,
                    'new_product' => 2,
                    'new_variant' => 0,
                    'sku_changed' => 0,
                    'blocked' => 0,
                ],
                'variants_by_status' => [
                    'mass_update_ready' => 1,
                    'new_product' => 2,
                    'new_variant' => 0,
                    'sku_changed' => 0,
                    'blocked' => 0,
                ],
            ],
            'items' => [
                [
                    'status' => 'mass_update_ready',
                    'reason' => 'matched_target_sku',
                    'source_item_id' => 'source-1',
                    'source_model_id' => 'source-model-1',
                    'product_name' => 'Ready Product',
                    'variant_name' => 'Ready Variant',
                    'source_seller_sku' => 'INT-READY',
                    'target_item_id' => 'target-1',
                    'target_model_id' => 'model-1',
                    'target_seller_sku' => 'INT-READY',
                ],
                [
                    'status' => 'new_product',
                    'reason' => 'missing_target_product',
                    'source_item_id' => 'source-2',
                    'source_model_id' => 'source-model-2',
                    'product_name' => 'Khiban series, "Premium"',
                    'variant_name' => 'Maroon',
                    'source_seller_sku' => 'INT-KHIBAN-MAROON',
                    'target_item_id' => '',
                    'target_model_id' => '',
                    'target_seller_sku' => '',
                ],
                [
                    'status' => 'new_product',
                    'reason' => 'missing_target_product',
                    'source_item_id' => 'source-3',
                    'source_model_id' => 'source-model-3',
                    'product_name' => 'NINJA NON RESLETING',
                    'variant_name' => 'Hitam',
                    'source_seller_sku' => 'INT-NINJA-HITAM',
                    'target_item_id' => '',
                    'target_model_id' => '',
                    'target_seller_sku' => '',
                ],
            ],
            'ready_targets' => [
                ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
            ],
        ];
    }

    private function shopeeGitaTemporaryArtifacts(): array
    {
        $generatedDirectory = storage_path('app/import-marketplace/generated');

        return collect(File::glob($generatedDirectory.'/shopee-gita-*'))
            ->sort()
            ->values()
            ->all();
    }

    private function invokeWorkbookFilter(string $path, string $type, array $readyTargets): void
    {
        $method = new ReflectionMethod(MarketplaceImportController::class, 'filterShopeeGitaWorkbook');
        $method->invoke(app(MarketplaceImportController::class), $path, $type, $readyTargets);
    }

    private function assertWorkbookRejectedWithoutMutation(string $path, string $type, array $readyTargets, string $case): void
    {
        $before = file_get_contents($path);

        try {
            $this->invokeWorkbookFilter($path, $type, $readyTargets);
            $this->fail("Workbook was accepted for {$case}.");
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode(), $case);
        }

        $this->assertSame($before, file_get_contents($path), $case);
    }
}
