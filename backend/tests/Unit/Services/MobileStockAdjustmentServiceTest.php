<?php

namespace Tests\Unit\Services;

use App\Services\MobileStockAdjustmentService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileStockAdjustmentServiceTest extends TestCase
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

    public function test_adjustment_updates_stock_master_writes_immutable_ledger_and_reports_mapping_states(): void
    {
        $stockMasterId = $this->createStockMaster(10);
        $this->createListing($stockMasterId, 'shopee-agnishopbjm');

        $result = app(MobileStockAdjustmentService::class)->adjust(
            $stockMasterId,
            3,
            'receiving',
            'Barang masuk gudang',
            'admin@example.test',
        );

        $this->assertSame(10, $result['before_quantity']);
        $this->assertSame(13, $result['after_quantity']);
        $this->assertSame('mapped_pending', $result['delivery_states']['shopee-agnishopbjm']['status']);
        $this->assertSame('mapping_required', $result['delivery_states']['shopee-gitacollectionbjm']['status']);
        $this->assertSame('mapping_required', $result['delivery_states']['tiktok-agnishopbjm']['status']);
        $this->assertDatabaseHas('stock_master', ['id' => $stockMasterId, 'stock_qty' => 13]);
        $this->assertDatabaseHas('stock_adjustments', [
            'stock_master_id' => $stockMasterId,
            'delta' => 3,
            'before_quantity' => 10,
            'after_quantity' => 13,
            'reason' => 'receiving',
            'operator' => 'admin@example.test',
        ]);
    }

    public function test_adjustment_rejects_deduction_that_would_make_stock_negative(): void
    {
        $stockMasterId = $this->createStockMaster(2);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Stok tidak mencukupi.');

        app(MobileStockAdjustmentService::class)->adjust(
            $stockMasterId,
            -3,
            'sale',
            null,
            'admin@example.test',
        );
    }

    public function test_history_returns_latest_adjustment_first_without_mutating_existing_entries(): void
    {
        $stockMasterId = $this->createStockMaster(5);
        $service = app(MobileStockAdjustmentService::class);

        $service->adjust($stockMasterId, 2, 'receiving', null, 'admin@example.test');
        $service->adjust($stockMasterId, -1, 'sale', null, 'admin@example.test');

        $history = $service->history($stockMasterId, 10);

        $this->assertCount(2, $history);
        $this->assertSame(-1, $history->first()['delta']);
        $this->assertSame(6, $history->first()['after_quantity']);
        $this->assertDatabaseCount('stock_adjustments', 2);
    }

    private function createStockMaster(int $stockQuantity): int
    {
        return (int) DB::table('stock_master')->insertGetId([
            'internal_sku' => 'MOBILE-STOCK-'.uniqid(),
            'product_name' => 'Produk Uji',
            'variant_name' => 'Varian Uji',
            'stock_qty' => $stockQuantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createListing(int $stockMasterId, string $accountKey): void
    {
        DB::table('marketplace_listings')->insert([
            'stock_master_id' => $stockMasterId,
            'account_key' => $accountKey,
            'channel' => 'shopee',
            'remote_product_id' => '123',
            'remote_variant_id' => '456',
            'remote_identity_hash' => hash('sha256', $accountKey.'-'.$stockMasterId),
            'seller_sku' => 'MOBILE-STOCK',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
