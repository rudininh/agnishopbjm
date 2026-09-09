<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileStockMasterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stock_master', function (Blueprint $table): void {
            $table->id();
            $table->string('internal_sku')->unique();
            $table->string('product_name')->nullable();
            $table->string('variant_name')->nullable();
            $table->integer('stock_qty')->default(0);
            $table->timestamps();
        });
    }

    public function test_authenticated_operator_can_search_adjust_and_read_history(): void
    {
        $stockMasterId = $this->createStockMaster('MOBILE-API-001', 7);
        $user = User::factory()->create(['email' => 'operator@example.test']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mobile/stock-master/search?search=MOBILE-API-001')
            ->assertOk()
            ->assertJsonPath('data.0.id', $stockMasterId)
            ->assertJsonPath('data.0.stock_qty', 7);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/mobile/stock-master/adjustments', [
                'stock_master_id' => $stockMasterId,
                'delta' => -2,
                'reason' => 'sale',
                'note' => 'Penjualan langsung',
            ])
            ->assertOk()
            ->assertJsonPath('data.after_quantity', 5)
            ->assertJsonPath('data.operator', 'operator@example.test');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mobile/stock-master/adjustments?stock_master_id='.$stockMasterId)
            ->assertOk()
            ->assertJsonPath('data.0.delta', -2)
            ->assertJsonPath('data.0.after_quantity', 5);
    }

    public function test_adjustment_endpoint_rejects_zero_delta_and_negative_balance(): void
    {
        $stockMasterId = $this->createStockMaster('MOBILE-API-002', 1);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/mobile/stock-master/adjustments', [
                'stock_master_id' => $stockMasterId,
                'delta' => 0,
                'reason' => 'correction',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('delta');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/mobile/stock-master/adjustments', [
                'stock_master_id' => $stockMasterId,
                'delta' => -2,
                'reason' => 'sale',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Stok tidak mencukupi.');
    }

    private function createStockMaster(string $sku, int $stockQuantity): int
    {
        return (int) DB::table('stock_master')->insertGetId([
            'internal_sku' => $sku,
            'product_name' => 'Produk API',
            'variant_name' => 'Varian API',
            'stock_qty' => $stockQuantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
