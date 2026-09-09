<?php

namespace Tests\Unit\Services;

use App\Contracts\MarketplaceProductGateway;
use App\Models\Product;
use App\Services\MarketplaceProductPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MarketplaceProductPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishes_to_both_requested_accounts(): void
    {
        $product = $this->productWithVariants();
        $gateway = new RecordingMarketplaceGateway();
        $publisher = new MarketplaceProductPublisher($gateway);

        $result = $publisher->publish($product, [
            'shopee-agnishopbjm' => ['category_id' => 100, 'weight' => 0.5],
            'tiktok-agnishopbjm' => ['category_id' => '200', 'warehouse_id' => 'WH-1'],
        ]);

        $this->assertSame('success', $result['run']->status);
        $this->assertSame(['shopee-agnishopbjm', 'tiktok-agnishopbjm'], $gateway->accounts);
        $this->assertDatabaseCount('marketplace_publication_results', 2);
        $this->assertDatabaseHas('marketplace_publication_results', [
            'account_key' => 'shopee-agnishopbjm',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('marketplace_publication_results', [
            'account_key' => 'tiktok-agnishopbjm',
            'status' => 'success',
        ]);
    }

    public function test_isolates_channel_failure_and_retries_only_failed_channel(): void
    {
        $product = $this->productWithVariants();
        $gateway = new RecordingMarketplaceGateway(['tiktok-agnishopbjm']);
        $publisher = new MarketplaceProductPublisher($gateway);
        $contexts = [
            'shopee-agnishopbjm' => ['category_id' => 100, 'weight' => 0.5],
            'tiktok-agnishopbjm' => ['category_id' => '200', 'warehouse_id' => 'WH-1'],
        ];

        $published = $publisher->publish($product, $contexts);
        $published['run']->refresh();

        $this->assertSame('partial_success', $published['run']->status);
        $this->assertSame(1, $this->accountCallCount($gateway->accounts, 'shopee-agnishopbjm'));
        $this->assertSame(1, $this->accountCallCount($gateway->accounts, 'tiktok-agnishopbjm'));

        $gateway->failAccounts = [];
        $retried = $publisher->retry($published['run']);

        $this->assertSame('success', $retried['run']->status);
        $this->assertSame(1, $this->accountCallCount($gateway->accounts, 'shopee-agnishopbjm'));
        $this->assertSame(2, $this->accountCallCount($gateway->accounts, 'tiktok-agnishopbjm'));
        $this->assertDatabaseCount('marketplace_publication_results', 2);
    }

    public function test_skips_successful_account_when_retrying_completed_run(): void
    {
        $product = $this->productWithVariants();
        $gateway = new RecordingMarketplaceGateway();
        $publisher = new MarketplaceProductPublisher($gateway);
        $contexts = [
            'shopee-agnishopbjm' => ['category_id' => 100, 'weight' => 0.5],
            'tiktok-agnishopbjm' => ['category_id' => '200', 'warehouse_id' => 'WH-1'],
        ];

        $published = $publisher->publish($product, $contexts);
        $publisher->retry($published['run']);

        $this->assertCount(2, $gateway->accounts);
        $this->assertSame('success', $published['run']->fresh()->status);
    }

    private function productWithVariants(): Product
    {
        $product = Product::factory()->create();
        $product->variants()->createMany([
            [
                'variant_name' => 'Merah - M',
                'sku' => 'TEST-RED-M-'.uniqid('', true),
                'price' => 125000,
                'stock' => 8,
                'image_url' => 'https://example.com/red.jpg',
                'position' => 0,
            ],
            [
                'variant_name' => 'Biru - L',
                'sku' => 'TEST-BLUE-L-'.uniqid('', true),
                'price' => 130000,
                'stock' => 7,
                'image_url' => 'https://example.com/blue.jpg',
                'position' => 1,
            ],
        ]);

        return $product->load('variants');
    }

    private function accountCallCount(array $accounts, string $accountKey): int
    {
        return count(array_filter($accounts, fn (string $account): bool => $account === $accountKey));
    }
}

class RecordingMarketplaceGateway implements MarketplaceProductGateway
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
            'response_payload' => ['ok' => true, 'idempotency_key' => $idempotencyKey],
        ];
    }
}