<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceApiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketplaceApiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('shopee_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('account_key')->nullable();
            $table->unsignedBigInteger('shop_id');
            $table->text('access_token');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('tiktok_tokens', function (Blueprint $table): void {
            $table->id();
            $table->text('access_token');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('tiktok_shops', function (Blueprint $table): void {
            $table->id();
            $table->string('shop_id');
            $table->string('cipher')->nullable();
            $table->string('shop_cipher')->nullable();
            $table->timestamps();
        });

        config([
            'shopee.host' => 'https://shopee.test',
            'shopee.partner_id' => 2468,
            'shopee.partner_key' => 'shopee-secret-test',
            'shopee.account_key' => 'shopee-agnishopbjm',
            'tiktok.api_host' => 'https://tiktok.test',
            'tiktok.app_key' => 'tiktok-app-key',
            'tiktok.app_secret' => 'tiktok-secret-test',
        ]);

        DB::table('shopee_tokens')->insert([
            'shop_id' => 777,
            'account_key' => 'shopee-agnishopbjm',
            'access_token' => 'secret-shopee-access-token',
            'is_active' => DB::raw('true'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tiktok_tokens')->insert([
            'access_token' => 'secret-tiktok-access-token',
            'is_active' => DB::raw('true'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tiktok_shops')->insert([
            'shop_id' => 'shop-888',
            'cipher' => 'secret-shop-cipher',
            'shop_cipher' => 'secret-shop-cipher',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_fetch_tiktok_product_uses_the_fresh_detail_endpoint_and_returns_safe_metadata(): void
    {
        $product = ['id' => 'tt-product-42', 'skus' => [['id' => 'tt-green']]];
        Http::fake(['https://tiktok.test/*' => Http::response([
            'code' => 0,
            'message' => 'Success',
            'data' => ['product' => $product],
        ])]);

        $result = app(MarketplaceApiService::class)->fetchTiktokProduct('tt-product-42');

        $this->assertTrue($result['ok']);
        $this->assertSame($product, $result['data']['product']);
        $this->assertSame([
            'method' => 'GET',
            'path' => '/product/202309/products/tt-product-42',
            'body' => null,
        ], $result['request']);
        $this->assertSafeRequestMetadata($result['request']);
        Http::assertSent(function ($request): bool {
            $query = $this->queryFromUrl($request->url());

            return $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === '/product/202309/products/tt-product-42'
                && ($query['shop_id'] ?? null) === 'shop-888'
                && $this->hasValidTiktokSignature('/product/202309/products/tt-product-42', $query);
        });
        Http::assertSentCount(1);
    }

    public function test_partial_edit_tiktok_product_sends_the_supplied_payload_to_the_signed_202509_endpoint(): void
    {
        $payload = [
            'save_mode' => 'LISTING',
            'skus' => [[
                'id' => 'tt-green',
                'seller_sku' => 'INT-42-GREEN',
                'price' => ['currency' => 'IDR', 'sale_price' => '25000'],
                'inventory' => [['warehouse_id' => 'warehouse-1', 'quantity' => 4]],
                'sales_attributes' => [['id' => '100000', 'value_id' => 'green']],
            ]],
        ];
        Http::fake(['https://tiktok.test/*' => Http::response(['code' => 0, 'message' => 'Success'])]);

        $result = app(MarketplaceApiService::class)->partialEditTiktokProduct('tt-product-42', $payload);

        $this->assertTrue($result['ok']);
        $this->assertSame($payload, $result['request']['body']);
        $this->assertSame('/product/202509/products/tt-product-42/partial_edit', $result['request']['path']);
        $this->assertSafeRequestMetadata($result['request']);
        Http::assertSent(function ($request) use ($payload): bool {
            $path = '/product/202509/products/tt-product-42/partial_edit';
            $query = $this->queryFromUrl($request->url());
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $request->method() === 'POST'
                && parse_url($request->url(), PHP_URL_PATH) === $path
                && $request->body() === $body
                && $this->hasValidTiktokSignature($path, $query, $body);
        });
        Http::assertSentCount(1);
    }

    public function test_fetch_shopee_models_uses_get_model_list_and_returns_safe_metadata(): void
    {
        $models = [[
            'model_id' => 123,
            'model_sku' => 'INT-54256579274-SOFT-DUSTY',
        ]];
        Http::fake(['https://shopee.test/*' => Http::response([
            'error' => '',
            'response' => ['model' => $models],
        ])]);

        $result = app(MarketplaceApiService::class)->fetchShopeeModels('54256579274');

        $this->assertTrue($result['ok']);
        $this->assertSame($models, $result['data']['models']);
        $this->assertSame([
            'method' => 'GET',
            'path' => '/api/v2/product/get_model_list',
            'body' => ['item_id' => 54256579274],
        ], $result['request']);
        $this->assertSafeRequestMetadata($result['request']);
        Http::assertSent(function ($request): bool {
            $query = $request->data();

            return $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === '/api/v2/product/get_model_list'
                && (int) ($query['item_id'] ?? 0) === 54256579274
                && isset($query['sign'], $query['timestamp'], $query['partner_id'], $query['shop_id']);
        });
        Http::assertSentCount(1);
    }

    public function test_update_shopee_model_sku_sends_only_the_safe_minimal_model_fields(): void
    {
        Http::fake(['https://shopee.test/*' => Http::response(['error' => ''])]);

        $result = app(MarketplaceApiService::class)->updateShopeeModelSku(
            '54256579274',
            '123',
            'INT-54256579274-SOFT-DUSTY'
        );

        $expectedBody = [
            'item_id' => 54256579274,
            'model' => [[
                'model_id' => 123,
                'model_sku' => 'INT-54256579274-SOFT-DUSTY',
            ]],
        ];
        $this->assertSame([
            'ok' => true,
            'message' => 'Model SKU Shopee berhasil diperbarui.',
            'data' => ['item_id' => '54256579274', 'model_id' => '123'],
            'request' => [
                'method' => 'POST',
                'path' => '/api/v2/product/update_model',
                'body' => $expectedBody,
            ],
            'response' => ['error' => ''],
        ], $result);
        $this->assertSame(
            ['item_id', 'model'],
            array_keys($result['request']['body'])
        );
        $this->assertSame(
            ['model_id', 'model_sku'],
            array_keys($result['request']['body']['model'][0])
        );
        $this->assertEmpty(array_intersect(
            ['name', 'image', 'price', 'stock', 'status', 'attribute_list', 'tier_variation'],
            array_keys($result['request']['body']['model'][0])
        ));
        $this->assertSafeRequestMetadata($result['request']);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/api/v2/product/update_model'
            && $request->data() === $expectedBody);
        Http::assertSentCount(1);
    }

    public function test_shopee_catalog_operations_use_the_configured_account_when_another_token_is_newer(): void
    {
        DB::table('shopee_tokens')->insert([
            'account_key' => 'shopee-gitacollectionbjm',
            'shop_id' => 999,
            'access_token' => 'wrong-shop-access-token',
            'is_active' => DB::raw('true'),
            'created_at' => now()->addMinute(),
            'updated_at' => now()->addMinute(),
        ]);
        Http::fake(['https://shopee.test/*' => Http::response(['error' => ''])]);

        $result = app(MarketplaceApiService::class)->updateShopeeModelSku('54256579274', '123', 'TARGET-SKU');

        $this->assertTrue($result['ok']);
        Http::assertSent(function ($request): bool {
            $query = $this->queryFromUrl($request->url());

            return ($query['shop_id'] ?? null) === '777'
                && ($query['access_token'] ?? null) === 'secret-shopee-access-token';
        });
        Http::assertSentCount(1);
    }

    /**
     * @dataProvider catalogOperationProvider
     */
    public function test_catalog_operations_normalize_connection_failures(string $operation): void
    {
        Http::fake(fn () => throw new ConnectionException('simulated connection failure'));

        $result = $this->runCatalogOperation($operation);

        $this->assertFalse($result['ok']);
        $this->assertSame(['ok', 'message', 'data', 'request', 'response'], array_keys($result));
        $this->assertSame([], $result['response']);
        $this->assertSafeRequestMetadata($result['request']);
        Http::assertNothingSent();
    }

    /**
     * @dataProvider catalogOperationProvider
     */
    public function test_catalog_operations_require_a_successful_http_status(string $operation): void
    {
        $response = str_starts_with($operation, 'tiktok')
            ? ['code' => 0, 'message' => 'Success']
            : ['error' => ''];
        Http::fake(fn () => Http::response($response, 500));

        $result = $this->runCatalogOperation($operation);

        $this->assertFalse($result['ok']);
        $this->assertSame(['ok', 'message', 'data', 'request', 'response'], array_keys($result));
        $this->assertNotSame('', trim($result['message']));
        $this->assertNotSame('Success', $result['message']);
        $this->assertSafeRequestMetadata($result['request']);
        Http::assertSentCount(1);
    }

    public static function catalogOperationProvider(): array
    {
        return [
            'TikTok fetch' => ['tiktok_fetch'],
            'TikTok partial edit' => ['tiktok_edit'],
            'Shopee fetch' => ['shopee_fetch'],
            'Shopee update' => ['shopee_update'],
        ];
    }

    private function queryFromUrl(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function runCatalogOperation(string $operation): array
    {
        $service = app(MarketplaceApiService::class);

        return match ($operation) {
            'tiktok_fetch' => $service->fetchTiktokProduct('tt-product-42'),
            'tiktok_edit' => $service->partialEditTiktokProduct('tt-product-42', [
                'save_mode' => 'LISTING',
                'skus' => [['id' => 'tt-green']],
            ]),
            'shopee_fetch' => $service->fetchShopeeModels('54256579274'),
            'shopee_update' => $service->updateShopeeModelSku('54256579274', '123', 'TARGET-SKU'),
        };
    }

    private function hasValidTiktokSignature(string $path, array $query, ?string $body = null): bool
    {
        $actual = (string) ($query['sign'] ?? '');
        unset($query['sign'], $query['access_token']);
        ksort($query);

        $base = 'tiktok-secret-test'.$path;
        foreach ($query as $key => $value) {
            $base .= $key.$value;
        }
        $base .= $body ?? '';
        $base .= 'tiktok-secret-test';

        return $actual !== ''
            && hash_equals(hash_hmac('sha256', $base, 'tiktok-secret-test'), $actual);
    }

    private function assertSafeRequestMetadata(array $request): void
    {
        $encoded = strtolower(json_encode($request, JSON_THROW_ON_ERROR));
        foreach ([
            'access_token',
            'authorization',
            'shop_cipher',
            '"sign"',
            'secret-shopee-access-token',
            'secret-tiktok-access-token',
            'secret-shop-cipher',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }
}
