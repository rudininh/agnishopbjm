<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceAccountRegistry;
use App\Services\MarketplaceApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class MarketplaceAccountAwareStockApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('shopee_tokens');
        Schema::create('shopee_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('account_key')->nullable();
            $table->unsignedBigInteger('shop_id');
            $table->text('access_token');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('shopee_tokens')->insert([
            ['account_key' => 'shopee-agnishopbjm', 'shop_id' => 101, 'access_token' => 'primary-token', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['account_key' => 'shopee-gitacollectionbjm', 'shop_id' => 202, 'access_token' => 'gita-token', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $registry = Mockery::mock(MarketplaceAccountRegistry::class);
        $registry->shouldReceive('shopeeContext')->andReturnUsing(function (string $key): array {
            return [
                'partner_id' => $key === 'shopee-gitacollectionbjm' ? 22 : 11,
                'partner_key' => $key === 'shopee-gitacollectionbjm' ? 'gita-key' : 'primary-key',
                'host' => 'https://'.$key.'.test',
                'redirect_url' => 'https://callback.test',
            ];
        });
        $this->app->instance(MarketplaceAccountRegistry::class, $registry);
    }

    public function test_gita_order_list_uses_gita_context_and_token(): void
    {
        Http::fake(['https://shopee-gitacollectionbjm.test/*' => Http::response([
            'error' => '',
            'response' => ['order_list' => [['order_sn' => 'GITA-1']], 'more' => false],
        ])]);

        $result = app(MarketplaceApiService::class)->fetchShopeeOrderSnListForAccount('shopee-gitacollectionbjm', 100, 200, 'READY_TO_SHIP');

        $this->assertSame('success', $result['status']);
        $this->assertSame('shopee-gitacollectionbjm', $result['account_key']);
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return parse_url($request->url(), PHP_URL_HOST) === 'shopee-gitacollectionbjm.test'
                && (int) ($data['shop_id'] ?? 0) === 202
                && ($data['access_token'] ?? null) === 'gita-token'
                && (int) ($data['partner_id'] ?? 0) === 22
                && ($data['order_status'] ?? null) === 'READY_TO_SHIP';
        });
    }

    public function test_gita_model_stock_uses_gita_context_and_keeps_model_lookup_account_scoped(): void
    {
        Http::fake(['https://shopee-gitacollectionbjm.test/*' => Http::response([
            'error' => '',
            'response' => ['model' => [['model_id' => 9, 'normal_stock' => 7]]],
        ])]);

        $result = app(MarketplaceApiService::class)->fetchShopeeModelStockForAccount('shopee-gitacollectionbjm', '88', '9');

        $this->assertSame('success', $result['status']);
        $this->assertSame(7, $result['stock']);
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return (int) ($data['shop_id'] ?? 0) === 202
                && ($data['access_token'] ?? null) === 'gita-token'
                && (int) ($data['item_id'] ?? 0) === 88;
        });
    }

    public function test_gita_stock_update_uses_gita_shop_and_safe_payload(): void
    {
        Http::fake(['https://shopee-gitacollectionbjm.test/*' => Http::response(['error' => ''])]);

        $result = app(MarketplaceApiService::class)->updateShopeeModelStockForAccount('shopee-gitacollectionbjm', '88', '9', 4, 'event-1');

        $this->assertSame('success', $result['status']);
        Http::assertSent(function ($request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $body = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && (int) ($query['shop_id'] ?? 0) === 202
                && $request->header('X-Idempotency-Key') === ['event-1']
                && data_get($body, 'model.0.normal_stock') === 4;
        });
    }

    public function test_missing_gita_token_fails_closed_without_http(): void
    {
        DB::table('shopee_tokens')->where('account_key', 'shopee-gitacollectionbjm')->update(['is_active' => false]);
        Http::fake();

        $result = app(MarketplaceApiService::class)->fetchShopeeOrderDetailForAccount('shopee-gitacollectionbjm', 'GITA-1');

        $this->assertSame('error', $result['status']);
        Http::assertNothingSent();
    }
}
