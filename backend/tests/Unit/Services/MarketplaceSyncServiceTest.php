<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceApiService;
use App\Services\MarketplaceFailureNotifier;
use App\Services\MarketplaceSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class MarketplaceSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('shopee_product_model')) {
            Schema::create('shopee_product_model', function (Blueprint $table): void {
                $table->string('model_id');
                $table->string('item_id');
                $table->integer('stock')->default(0);
                $table->timestamps();
                $table->primary(['model_id', 'item_id']);
            });
        }
    }

    public function test_shopee_push_uses_the_configured_account_when_another_account_token_is_newer(): void
    {
        config([
            'shopee.account_key' => 'shopee-agnishopbjm',
            'shopee.host' => 'https://shopee.test',
            'shopee.partner_id' => 123,
            'shopee.partner_key' => 'test-partner-key',
        ]);

        DB::table('shopee_product_model')->insert([
            'item_id' => '101',
            'model_id' => '202',
            'stock' => 9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('shopee_tokens')->insert([
            [
                'account_key' => 'shopee-agnishopbjm',
                'account_name' => 'Shopee AgniShopBJM',
                'shop_id' => 111,
                'access_token' => 'agnishop-token',
                'refresh_token' => 'agnishop-refresh',
                'is_active' => true,
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
            [
                'account_key' => 'shopee-gitacollectionbjm',
                'account_name' => 'Shopee Gita Collection BJM',
                'shop_id' => 222,
                'access_token' => 'gita-token',
                'refresh_token' => 'gita-refresh',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Http::fake([
            'https://shopee.test/*' => Http::response(['error' => '', 'response' => []]),
        ]);

        $service = new MarketplaceSyncService(
            Mockery::mock(MarketplaceApiService::class),
            Mockery::mock(MarketplaceFailureNotifier::class),
        );

        $result = $service->pushTargetStock((object) [
            'shopee_product_id' => '101',
            'shopee_sku' => '202',
        ], 'shopee', 8, true);

        $this->assertSame('success', $result['status']);
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['shop_id'] ?? null) === '111'
                && ($query['access_token'] ?? null) === 'agnishop-token';
        });
    }
}
