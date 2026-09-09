<?php

namespace Tests\Unit\Services;

use App\Models\Product;
use App\Services\MarketplaceProductPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class MarketplaceProductPayloadBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_shopee_payload_from_the_internal_product(): void
    {
        $product = $this->productWithVariants();

        $payload = app(MarketplaceProductPayloadBuilder::class)->forShopee($product, [
            'category_id' => 12345,
            'weight' => 0.5,
        ]);

        $this->assertSame('Kemeja Flanel Random', $payload['item_name']);
        $this->assertSame(12345, $payload['category_id']);
        $this->assertSame(0.5, $payload['weight']);
        $this->assertSame('FLANEL-RED-M', $payload['models'][0]['model_sku']);
        $this->assertSame(8, $payload['models'][0]['stock']);
        $this->assertSame('https://example.com/red.jpg', $payload['models'][0]['image_url']);
    }

    public function test_it_builds_a_tiktok_payload_from_the_internal_product(): void
    {
        $product = $this->productWithVariants();

        $payload = app(MarketplaceProductPayloadBuilder::class)->forTiktok($product, [
            'category_id' => 'CAT-123',
            'warehouse_id' => 'WH-1',
        ]);

        $this->assertSame('Kemeja Flanel Random', $payload['product_name']);
        $this->assertSame('CAT-123', $payload['category_id']);
        $this->assertSame('WH-1', $payload['warehouse_id']);
        $this->assertSame('FLANEL-BLUE-L', $payload['skus'][1]['seller_sku']);
        $this->assertSame(7, $payload['skus'][1]['stock']);
        $this->assertSame('https://example.com/blue.jpg', $payload['skus'][1]['image_url']);
    }

    public function test_it_rejects_missing_channel_context(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(MarketplaceProductPayloadBuilder::class)->forTiktok($this->productWithVariants(), []);
    }

    private function productWithVariants(): Product
    {
        $product = Product::factory()->create([
            'name' => 'Kemeja Flanel Random',
            'description' => 'Produk uji random.',
        ]);

        $product->variants()->createMany([
            [
                'variant_name' => 'Merah - M',
                'sku' => 'FLANEL-RED-M',
                'price' => 125000,
                'stock' => 8,
                'image_url' => 'https://example.com/red.jpg',
                'position' => 0,
            ],
            [
                'variant_name' => 'Biru - L',
                'sku' => 'FLANEL-BLUE-L',
                'price' => 130000,
                'stock' => 7,
                'image_url' => 'https://example.com/blue.jpg',
                'position' => 1,
            ],
        ]);

        return $product;
    }
}
