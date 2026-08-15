<?php

namespace Tests\Feature;

use App\Http\Controllers\MarketplaceImportController;
use App\Services\ShopeeGitaMassUpdateGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ShopeeGitaMassUpdateGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_generator_defines_six_ordered_auditable_file_types(): void
    {
        $files = app(ShopeeGitaMassUpdateGenerator::class)->definitions();

        $this->assertSame([
            'basic-info',
            'sales-info',
            'media-info',
            'shipping-info',
            'dts-info',
            'republish-items',
        ], array_column($files, 'file_type'));
        $this->assertSame('mass_republish_items.xlsx', $files[5]['filename']);
    }

    public function test_generator_passes_the_locked_manifest_job_to_the_workbook_writer(): void
    {
        $imports = Mockery::mock(MarketplaceImportController::class);
        $imports->shouldReceive('generateShopeeGitaMassUpdateFiles')
            ->once()
            ->with('import-marketplace/generated/test-job', 123)
            ->andReturn([]);

        $files = (new ShopeeGitaMassUpdateGenerator($imports))
            ->generate('import-marketplace/generated/test-job', 123);

        $this->assertSame([], $files);
    }
}
