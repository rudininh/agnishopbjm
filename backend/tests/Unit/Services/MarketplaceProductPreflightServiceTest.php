<?php

namespace Tests\Unit\Services;

use App\Models\Product;
use App\Services\MarketplaceProductPreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceProductPreflightServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_a_product_when_a_variant_reuses_the_product_sku(): void
    {
        $product = Product::factory()->create(['sku' => 'RANDOM-PRODUCT-001']);
        $product->variants()->create([
            'variant_name' => 'Merah',
            'sku' => 'RANDOM-PRODUCT-001',
            'price' => 125000,
            'stock' => 5,
            'image_url' => 'https://example.com/red.jpg',
        ]);

        $result = app(MarketplaceProductPreflightService::class)->validate(
            $product,
            ['shopee-agnishopbjm', 'tiktok-agnishopbjm'],
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('sku_conflict', $result['errors'][0]['code']);
    }

    public function test_it_accepts_a_complete_random_product_for_both_primary_accounts(): void
    {
        $product = Product::factory()->create();
        $product->variants()->createMany([
            [
                'variant_name' => 'Merah - M',
                'sku' => 'RANDOM-RED-M',
                'price' => 125000,
                'stock' => 5,
                'image_url' => 'https://example.com/red.jpg',
                'position' => 0,
            ],
            [
                'variant_name' => 'Biru - L',
                'sku' => 'RANDOM-BLUE-L',
                'price' => 130000,
                'stock' => 4,
                'image_url' => 'https://example.com/blue.jpg',
                'position' => 1,
            ],
        ]);

        $result = app(MarketplaceProductPreflightService::class)->validate(
            $product,
            ['shopee-agnishopbjm', 'tiktok-agnishopbjm'],
        );

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_it_rejects_an_unknown_marketplace_account(): void
    {
        $product = Product::factory()->create();

        $result = app(MarketplaceProductPreflightService::class)->validate($product, ['unknown-account']);

        $this->assertFalse($result['valid']);
        $this->assertSame('unknown_account', $result['errors'][0]['code']);
    }
}
