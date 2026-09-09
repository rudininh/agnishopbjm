<?php

namespace Tests\Feature;

use App\Contracts\MarketplaceProductGateway;
use App\Models\MarketplacePublicationRun;
use App\Models\Product;
use App\Services\MarketplaceProductPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MarketplaceProductPublicationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_endpoint_returns_run_with_per_account_results(): void
    {
        $product = $this->productWithVariants();
        $gateway = new ApiRecordingMarketplaceGateway();
        $this->app->instance(MarketplaceProductPublisher::class, new MarketplaceProductPublisher($gateway));

        $response = $this->postJson('/api/marketplace/products/'.$product->uuid.'/publish', [
            'accounts' => [
                ['account_key' => 'shopee-agnishopbjm', 'context' => ['category_id' => 100, 'weight' => 0.5]],
                ['account_key' => 'tiktok-agnishopbjm', 'context' => ['category_id' => '200', 'warehouse_id' => 'WH-1']],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.results.0.status', 'success')
            ->assertJsonPath('data.results.1.status', 'success');
    }

    public function test_retry_endpoint_retries_only_failed_publication_results(): void
    {
        $product = $this->productWithVariants();
        $gateway = new ApiRecordingMarketplaceGateway(['tiktok-agnishopbjm']);
        $publisher = new MarketplaceProductPublisher($gateway);
        $this->app->instance(MarketplaceProductPublisher::class, $publisher);

        $published = $this->postJson('/api/marketplace/products/'.$product->uuid.'/publish', [
            'accounts' => [
                ['account_key' => 'shopee-agnishopbjm', 'context' => ['category_id' => 100, 'weight' => 0.5]],
                ['account_key' => 'tiktok-agnishopbjm', 'context' => ['category_id' => '200', 'warehouse_id' => 'WH-1']],
            ],
        ])->assertCreated()->json('data');

        $gateway->failAccounts = [];

        $this->postJson('/api/marketplace/products/publication-runs/'.$published['id'].'/retry')
            ->assertOk()
            ->assertJsonPath('data.status', 'success');

        $this->assertSame(['shopee-agnishopbjm', 'tiktok-agnishopbjm', 'tiktok-agnishopbjm'], $gateway->accounts);
        $this->assertSame('success', MarketplacePublicationRun::query()->findOrFail($published['id'])->status);
    }

    private function productWithVariants(): Product
    {
        $product = Product::factory()->create(['sku' => 'API-PUB-'.uniqid()]);
        $product->variants()->createMany([
            [
                'variant_name' => 'Merah - M',
                'sku' => 'API-PUB-RED-M-'.uniqid('', true),
                'price' => 125000,
                'stock' => 8,
                'image_url' => 'https://example.com/red.jpg',
                'position' => 0,
            ],
            [
                'variant_name' => 'Biru - L',
                'sku' => 'API-PUB-BLUE-L-'.uniqid('', true),
                'price' => 130000,
                'stock' => 7,
                'image_url' => 'https://example.com/blue.jpg',
                'position' => 1,
            ],
        ]);

        return $product->load('variants');
    }
}

class ApiRecordingMarketplaceGateway implements MarketplaceProductGateway
{
    public array $accounts = [];

    public function __construct(public array $failAccounts = [])
    {
    }

    public function publish(string $accountKey, string $channel, array $payload, string $idempotencyKey): array
    {
        $this->accounts[] = $accountKey;

        if (in_array($accountKey, $this->failAccounts, true)) {
            throw new RuntimeException('Simulated marketplace failure.');
        }

        return [
            'remote_product_id' => strtoupper($channel).'-PRODUCT-1',
            'remote_variant_ids' => ['VARIANT-1', 'VARIANT-2'],
            'response_payload' => ['ok' => true],
        ];
    }
}