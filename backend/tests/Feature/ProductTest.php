<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $token;
    protected $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('api-token')->plainTextToken;
        $this->category = Category::factory()->create();
    }

    public function test_get_products_list()
    {
        Product::factory(3)->create(['category_id' => $this->category->uuid]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
            ->getJson('/api/products');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['uuid', 'name', 'sku', 'price', 'stock', 'category']
            ]
        ]);
    }

    public function test_create_product()
    {
        $response = $this->withHeader('Authorization', "Bearer $this->token")
            ->postJson('/api/products', [
                'name' => 'Test Product',
                'sku' => 'PROD-001',
                'description' => 'Test description',
                'price' => 100000,
                'stock' => 50,
                'category_id' => $this->category->uuid,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Test Product');

        $this->assertDatabaseHas('products', ['sku' => 'PROD-001']);
    }

    public function test_update_product()
    {
        $product = Product::factory()->create(['category_id' => $this->category->uuid]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
            ->putJson("/api/products/{$product->uuid}", [
                'name' => 'Updated Product',
                'sku' => $product->sku,
                'price' => 150000,
                'stock' => 75,
                'category_id' => $this->category->uuid,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Updated Product');
    }

    public function test_delete_product()
    {
        $product = Product::factory()->create(['category_id' => $this->category->uuid]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
            ->deleteJson("/api/products/{$product->uuid}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('products', ['uuid' => $product->uuid]);
    }
}
