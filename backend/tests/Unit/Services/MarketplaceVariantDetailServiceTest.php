<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceVariantDetailService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class MarketplaceVariantDetailServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $stockMasterId;

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

        $this->stockMasterId = (int) DB::table('stock_master')->insertGetId([
            'internal_sku' => 'DETAIL-TEST-001',
            'product_name' => 'Produk Detail',
            'variant_name' => 'Merah',
            'stock_qty' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->configureShopeeAccount('shopee-agnishopbjm');
        $this->configureShopeeAccount('shopee-gitacollectionbjm');
        $this->insertShopeeToken('shopee-agnishopbjm');
        $this->insertShopeeToken('shopee-gitacollectionbjm');
        $this->insertListing('shopee-agnishopbjm', $this->stockMasterId);
        $this->insertListing('shopee-gitacollectionbjm', $this->stockMasterId + 1);
    }

    public function test_detail_downgrades_globally_ready_gita_without_an_exact_variant_mapping(): void
    {
        $detail = app(MarketplaceVariantDetailService::class)->detail($this->stockMasterId);

        $this->assertSame(7, $detail['variant']['stock_qty']);
        $this->assertSame('ready', $detail['accounts']['shopee-agnishopbjm']['status']);
        $this->assertTrue($detail['accounts']['shopee-agnishopbjm']['selectable']);
        $this->assertSame('mapping_required', $detail['accounts']['shopee-gitacollectionbjm']['status']);
        $this->assertFalse($detail['accounts']['shopee-gitacollectionbjm']['selectable']);
    }

    public function test_selectable_targets_rejects_duplicate_unknown_and_unmapped_accounts(): void
    {
        $service = app(MarketplaceVariantDetailService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->selectableTargets($this->stockMasterId, [
            'shopee-agnishopbjm',
            'shopee-agnishopbjm',
        ]);
    }

    private function configureShopeeAccount(string $accountKey): void
    {
        config([
            'marketplace_accounts.accounts.'.$accountKey.'.enabled' => true,
            'marketplace_accounts.accounts.'.$accountKey.'.use_primary_app' => false,
            'marketplace_accounts.accounts.'.$accountKey.'.credentials.partner_id' => 12345,
            'marketplace_accounts.accounts.'.$accountKey.'.credentials.partner_key' => 'test-partner-key',
            'marketplace_accounts.accounts.'.$accountKey.'.credentials.host' => 'https://partner.test',
            'marketplace_accounts.accounts.'.$accountKey.'.credentials.redirect_url' => 'https://app.test/api/shopee/callback',
        ]);
    }

    private function insertShopeeToken(string $accountKey): void
    {
        DB::table('shopee_tokens')->insert([
            'account_key' => $accountKey,
            'account_name' => $accountKey,
            'shop_id' => 123456,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertListing(string $accountKey, int $stockMasterId): void
    {
        $remoteProductId = 'product-'.$stockMasterId.'-'.$accountKey;
        $remoteVariantId = 'variant-'.$stockMasterId.'-'.$accountKey;

        DB::table('marketplace_listings')->insert([
            'stock_master_id' => $stockMasterId,
            'account_key' => $accountKey,
            'channel' => 'shopee',
            'remote_product_id' => $remoteProductId,
            'remote_variant_id' => $remoteVariantId,
            'remote_identity_hash' => hash('sha256', $remoteProductId.'|'.$remoteVariantId),
            'seller_sku' => 'DETAIL-'.$stockMasterId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
