<?php

namespace Tests\Feature;

use App\Models\MarketplacePublicationRun;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplacePublicationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_publication_run_keeps_channel_results_independent(): void
    {
        $product = Product::factory()->create();
        $run = MarketplacePublicationRun::create([
            'product_uuid' => $product->uuid,
            'status' => 'partial_success',
            'requested_accounts' => [
                'shopee-agnishopbjm',
                'tiktok-agnishopbjm',
            ],
        ]);

        $run->results()->createMany([
            [
                'account_key' => 'shopee-agnishopbjm',
                'channel' => 'shopee',
                'status' => 'success',
                'idempotency_key' => $run->id.':shopee-agnishopbjm',
                'remote_product_id' => '12345',
                'remote_variant_ids' => ['67890'],
            ],
            [
                'account_key' => 'tiktok-agnishopbjm',
                'channel' => 'tiktok',
                'status' => 'failed',
                'idempotency_key' => $run->id.':tiktok-agnishopbjm',
                'error_code' => 'category_mapping_required',
                'error_message' => 'Kategori TikTok belum dipetakan.',
            ],
        ]);

        $freshRun = $run->fresh(['product', 'results']);

        $this->assertSame($product->uuid, $freshRun->product->uuid);
        $this->assertSame(['shopee-agnishopbjm', 'tiktok-agnishopbjm'], $freshRun->requested_accounts);
        $this->assertSame(['success', 'failed'], $freshRun->results->pluck('status')->all());
        $this->assertSame(['67890'], $freshRun->results->first()->remote_variant_ids);
        $this->assertSame('category_mapping_required', $freshRun->results->last()->error_code);
    }
}
