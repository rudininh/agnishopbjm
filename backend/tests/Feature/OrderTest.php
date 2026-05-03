<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
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

    public function test_checkout_order()
    {
        $product = Product::factory()->create([
            'category_id' => $this->category->uuid,
            'price' => 100000,
            'stock' => 10,
        ]);

        // Add item to cart
        $this->withHeader('Authorization', "Bearer $this->token")
            ->postJson('/api/cart/items', [
                'product_id' => $product->uuid,
                'quantity' => 2,
            ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
            ->postJson('/api/orders', [
                'shipping_address' => 'Jl. Test No. 123, Banjarmasin',
                'payment_method' => 'bank_transfer',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'uuid', 'order_number', 'status', 'total', 'items', 'created_at'
            ]
        ]);
        $response->assertJsonPath('data.total', 200000);
    }

    public function test_checkout_empty_cart()
    {
        $response = $this->withHeader('Authorization', "Bearer $this->token")
            ->postJson('/api/orders', [
                'shipping_address' => 'Jl. Test No. 123',
                'payment_method' => 'bank_transfer',
            ]);

        $response->assertStatus(422);
    }

    public function test_get_orders_list()
    {
        $response = $this->withHeader('Authorization', "Bearer $this->token")
            ->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['uuid', 'order_number', 'status', 'total']
            ]
        ]);
    }
}
