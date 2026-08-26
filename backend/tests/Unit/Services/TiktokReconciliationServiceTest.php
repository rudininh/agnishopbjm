<?php

namespace Tests\Unit\Services;

use App\Services\TiktokReconciliationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TiktokReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stock_master', function (Blueprint $table): void {
            $table->id();
            $table->string('internal_sku');
            $table->string('shopee_product_id')->nullable();
            $table->string('shopee_sku')->nullable();
            $table->string('shopee_seller_sku')->nullable();
            $table->string('product_name')->nullable();
            $table->string('variant_name')->nullable();
            $table->integer('stock_qty')->default(0);
            $table->string('tiktok_product_id')->nullable();
            $table->string('tiktok_sku')->nullable();
            $table->boolean('is_hidden_from_mapping')->default(false);
            $table->timestamps();
        });

        Schema::create('shopee_product', function (Blueprint $table): void {
            $table->string('item_id')->primary();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('shopee_product_model', function (Blueprint $table): void {
            $table->string('item_id');
            $table->string('model_id');
            $table->string('model_name')->nullable();
            $table->string('seller_sku')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->timestamps();
            $table->primary(['item_id', 'model_id']);
        });

        Schema::create('shopee_product_image', function (Blueprint $table): void {
            $table->id();
            $table->string('item_id');
            $table->string('model_id')->nullable();
            $table->string('image_url')->nullable();
            $table->timestamps();
        });

        Schema::create('tiktok_products', function (Blueprint $table): void {
            $table->id();
            $table->string('product_id');
            $table->string('sku_id');
            $table->string('seller_sku')->nullable();
            $table->string('product_name')->nullable();
            $table->string('sku_name')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->integer('stock_qty')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function test_preview_groups_unmapped_shopee_product_as_new_product_without_tiktok_write(): void
    {
        $this->seedShopeeOnlyProductWithTwoVariants('item-100');
        Http::fake();

        $preview = app(TiktokReconciliationService::class)->createPreview();

        $this->assertSame('ready_for_review', $preview['status']);
        $this->assertSame(1, $preview['summary']['new_products']);
        $this->assertCount(1, $preview['new_products']);
        $this->assertSame('new_product:item-100', $preview['new_products'][0]['item_key']);
        Http::assertNothingSent();
        $this->assertDatabaseHas('tiktok_reconciliation_runs', ['id' => $preview['run_id']]);
    }

    public function test_preview_blocks_ambiguous_seller_sku_instead_of_drafting_or_mapping_it(): void
    {
        $this->seedAmbiguousShopeeAndTiktokSellerSku('INT-CONFLICT');

        $preview = app(TiktokReconciliationService::class)->createPreview();

        $this->assertSame(1, $preview['summary']['conflicts']);
        $this->assertSame('conflict', $preview['conflicts'][0]['action_type']);
        $this->assertSame('tiktok_sku_conflict', $preview['conflicts'][0]['block_reason']);
        $this->assertDatabaseMissing('sku_mappings', ['seller_sku' => 'INT-CONFLICT']);
    }

    public function test_preview_revision_changes_when_source_variant_changes_and_never_persists_secrets(): void
    {
        $this->seedShopeeOnlyProductWithTwoVariants('item-101');
        $first = app(TiktokReconciliationService::class)->createPreview();
        DB::table('stock_master')->where('internal_sku', 'INT-101-RED')->update(['stock_qty' => 8]);
        $second = app(TiktokReconciliationService::class)->createPreview();

        $payload = (string) DB::table('tiktok_reconciliation_run_items')->where('run_id', $first['run_id'])->value('payload');
        $this->assertNotSame($first['revision'], $second['revision']);
        $this->assertStringNotContainsString('access_token', $payload);
        $this->assertStringNotContainsString('app_secret', $payload);
        $this->assertStringNotContainsString('sign', $payload);
    }

    public function test_claim_marks_a_run_stale_when_its_source_cache_changes_after_preview(): void
    {
        $this->seedShopeeOnlyProductWithTwoVariants('item-claim-stale');
        $preview = app(TiktokReconciliationService::class)->createPreview();
        DB::table('stock_master')->where('internal_sku', 'INT-101-RED')->update(['stock_qty' => 8]);

        $claim = app(TiktokReconciliationService::class)->claimCurrentRun($preview['run_id'], $preview['revision']);

        $this->assertSame('stale_revision', $claim['status']);
        $this->assertDatabaseHas('tiktok_reconciliation_runs', [
            'id' => $preview['run_id'],
            'status' => 'ready_for_review',
        ]);
    }

    public function test_preview_skips_a_unique_same_name_shopee_seller_sku_that_already_exists_on_tiktok(): void
    {
        $this->seedShopeeOnlyProductWithTwoVariants('item-existing-seller-sku');
        DB::table('stock_master')->where('internal_sku', 'INT-101-BLUE')->delete();
        DB::table('shopee_product_model')->where([
            'item_id' => 'item-existing-seller-sku',
            'model_id' => 'blue',
        ])->delete();
        DB::table('shopee_product_image')->where([
            'item_id' => 'item-existing-seller-sku',
            'model_id' => 'blue',
        ])->delete();
        DB::table('stock_master')->where('internal_sku', 'INT-101-RED')->update([
            'shopee_seller_sku' => 'SHOPEE-RED',
            'updated_at' => now(),
        ]);
        DB::table('shopee_product_model')->where([
            'item_id' => 'item-existing-seller-sku',
            'model_id' => 'red',
        ])->update([
            'seller_sku' => 'SHOPEE-RED',
            'updated_at' => now(),
        ]);
        DB::table('tiktok_products')->insert([
            'product_id' => 'tiktok-existing-product',
            'sku_id' => 'tiktok-existing-red',
            'seller_sku' => 'SHOPEE-RED',
            'product_name' => 'Shopee Product item-existing-seller-sku',
            'sku_name' => '  RED ',
            'stock_qty' => 4,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $preview = app(TiktokReconciliationService::class)->createPreview();

        $this->assertCount(0, $preview['new_products']);
        $this->assertCount(0, $preview['variant_additions']);
        $this->assertCount(0, $preview['conflicts']);
    }

    public function test_claim_is_idempotent_after_an_atomic_first_claim(): void
    {
        $this->seedShopeeOnlyProductWithTwoVariants('item-claim-once');
        $preview = app(TiktokReconciliationService::class)->createPreview();

        $first = app(TiktokReconciliationService::class)->claimCurrentRun($preview['run_id'], $preview['revision']);
        $claimedAt = (string) DB::table('tiktok_reconciliation_runs')->where('id', $preview['run_id'])->value('updated_at');
        $second = app(TiktokReconciliationService::class)->claimCurrentRun($preview['run_id'], $preview['revision']);

        $this->assertSame('claimed', $first['status']);
        $this->assertSame('claimed', $second['status']);
        $this->assertSame($claimedAt, (string) DB::table('tiktok_reconciliation_runs')->where('id', $preview['run_id'])->value('updated_at'));
    }

    public function test_claim_reverts_to_ready_when_source_changes_after_its_atomic_update(): void
    {
        $this->seedShopeeOnlyProductWithTwoVariants('item-claim-post-update-stale');
        $service = new class extends TiktokReconciliationService {
            protected function afterReconciliationRunClaimed(): void
            {
                DB::table('stock_master')->where('internal_sku', 'INT-101-RED')->update(['stock_qty' => 8]);
            }
        };
        $preview = $service->createPreview();

        $claim = $service->claimCurrentRun($preview['run_id'], $preview['revision']);

        $this->assertSame('stale_revision', $claim['status']);
        $this->assertDatabaseHas('tiktok_reconciliation_runs', [
            'id' => $preview['run_id'],
            'status' => 'ready_for_review',
        ]);
    }

    private function seedShopeeOnlyProductWithTwoVariants(string $itemId): void
    {
        DB::table('shopee_product')->insert([
            'item_id' => $itemId,
            'name' => 'Shopee Product '.$itemId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['model_id' => 'red', 'internal_sku' => 'INT-101-RED', 'variant_name' => 'Red'],
            ['model_id' => 'blue', 'internal_sku' => 'INT-101-BLUE', 'variant_name' => 'Blue'],
        ] as $variant) {
            DB::table('stock_master')->insert([
                'internal_sku' => $variant['internal_sku'],
                'shopee_product_id' => $itemId,
                'shopee_sku' => $variant['model_id'],
                'shopee_seller_sku' => $variant['internal_sku'],
                'product_name' => 'Shopee Product '.$itemId,
                'variant_name' => $variant['variant_name'],
                'stock_qty' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('shopee_product_model')->insert([
                'item_id' => $itemId,
                'model_id' => $variant['model_id'],
                'model_name' => $variant['variant_name'],
                'seller_sku' => $variant['internal_sku'],
                'price' => 25000,
                'stock' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('shopee_product_image')->insert([
                'item_id' => $itemId,
                'model_id' => $variant['model_id'],
                'image_url' => 'https://images.test/'.$itemId.'-'.$variant['model_id'].'.jpg',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedAmbiguousShopeeAndTiktokSellerSku(string $sellerSku): void
    {
        $this->seedShopeeOnlyProductWithTwoVariants('item-conflict');
        DB::table('stock_master')->where('internal_sku', 'INT-101-RED')->update([
            'shopee_seller_sku' => $sellerSku,
            'updated_at' => now(),
        ]);
        DB::table('shopee_product_model')->where([
            'item_id' => 'item-conflict',
            'model_id' => 'red',
        ])->update([
            'seller_sku' => $sellerSku,
            'updated_at' => now(),
        ]);
        DB::table('stock_master')->insert([
            'internal_sku' => 'INT-CONFLICT-OTHER',
            'shopee_seller_sku' => $sellerSku,
            'stock_qty' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tiktok_products')->insert([
            'product_id' => 'tiktok-existing-product',
            'sku_id' => 'tiktok-existing-sku',
            'seller_sku' => $sellerSku,
            'product_name' => 'Existing TikTok Product',
            'sku_name' => 'Different Variant Name',
            'stock_qty' => 4,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
