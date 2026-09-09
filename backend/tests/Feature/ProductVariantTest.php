<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductVariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_can_be_created_with_random_variants(): void
    {
        $category = Category::factory()->create();

        $response = $this->postJson('/api/products', [
            'name' => 'Kemeja Flanel Random Test',
            'sku' => 'FLANEL-RANDOM-001',
            'description' => 'Produk random untuk uji input omnichannel.',
            'price' => 125000,
            'stock' => 15,
            'category_id' => $category->uuid,
            'variants' => [
                [
                    'variant_name' => 'Merah - M',
                    'sku' => 'FLANEL-RANDOM-001-MERAH-M',
                    'price' => 125000,
                    'stock' => 8,
                    'image_url' => 'https://example.com/flanel-merah.jpg',
                ],
                [
                    'variant_name' => 'Biru - L',
                    'sku' => 'FLANEL-RANDOM-001-BIRU-L',
                    'price' => 130000,
                    'stock' => 7,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.variants.0.variant_name', 'Merah - M')
            ->assertJsonPath('data.variants.1.sku', 'FLANEL-RANDOM-001-BIRU-L');

        $product = Product::query()->where('sku', 'FLANEL-RANDOM-001')->firstOrFail();

        $this->assertDatabaseHas('product_variants', [
            'product_uuid' => $product->uuid,
            'sku' => 'FLANEL-RANDOM-001-MERAH-M',
            'stock' => 8,
        ]);
    }

    public function test_product_detail_includes_saved_variants(): void
    {
        $product = Product::factory()->create();
        $product->variants()->create([
            'variant_name' => 'Hitam - XL',
            'sku' => 'DETAIL-RANDOM-HITAM-XL',
            'price' => 145000,
            'stock' => 4,
            'position' => 0,
        ]);

        $this->getJson('/api/products/'.$product->uuid)
            ->assertOk()
            ->assertJsonPath('data.variants.0.variant_name', 'Hitam - XL')
            ->assertJsonPath('data.variants.0.stock', 4);
    }
}
