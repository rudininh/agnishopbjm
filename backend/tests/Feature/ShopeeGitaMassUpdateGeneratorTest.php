<?php

namespace Tests\Feature;

use App\Services\ShopeeGitaMassUpdateGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopeeGitaMassUpdateGeneratorTest extends TestCase
{
    use RefreshDatabase;

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
}
