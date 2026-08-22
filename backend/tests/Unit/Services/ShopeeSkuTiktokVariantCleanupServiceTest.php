<?php

namespace Tests\Unit\Services;

use App\Services\ShopeeSkuTiktokVariantCleanupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShopeeSkuTiktokVariantCleanupServiceTest extends TestCase
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
            $table->string('tiktok_product_id')->nullable();
            $table->string('tiktok_sku')->nullable();
            $table->timestamps();
        });

        Schema::create('shopee_product_model', function (Blueprint $table): void {
            $table->string('item_id');
            $table->string('model_id');
            $table->string('name')->nullable();
            $table->string('model_sku')->nullable();
            $table->timestamps();
            $table->primary(['item_id', 'model_id']);
        });

        Schema::create('tiktok_products', function (Blueprint $table): void {
            $table->id();
            $table->string('product_id');
            $table->string('sku_id');
            $table->string('seller_sku')->nullable();
            $table->string('product_name')->nullable();
            $table->string('sku_name')->nullable();
            $table->boolean('is_active')->nullable()->default(true);
            $table->timestamps();
        });

        Http::fake();
    }

    public function test_preview_marks_only_changed_template_skus_as_ready(): void
    {
        $this->seedCandidate();

        $preview = app(ShopeeSkuTiktokVariantCleanupService::class)
            ->createPreview($this->mappingGroups());

        $this->assertSame(1, $preview['summary']['eligible']);
        $this->assertSame('INT-54256579274-KHAKKY', $preview['items'][0]['target_sku']);
        $this->assertSame('Khakky', $preview['items'][0]['shopee_variant_name']);
        $this->assertSame('Sand', $preview['items'][0]['tiktok_variant_name']);
        $this->assertSame('ready', $preview['items'][0]['status']);
        Http::assertNothingSent();
    }

    public function test_preview_treats_legacy_null_tiktok_rows_as_active_with_postgresql_safe_queries(): void
    {
        $this->seedCandidate();
        DB::table('tiktok_products')->where('product_id', 'tt-1')->update(['is_active' => null]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $preview = app(ShopeeSkuTiktokVariantCleanupService::class)
            ->createPreview($this->mappingGroups());

        $tiktokActiveQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'tiktok_products')
                && str_contains($query['query'], 'is_active'))
            ->values();
        DB::disableQueryLog();

        $this->assertSame('ready', $preview['items'][0]['status']);
        $this->assertNotEmpty($tiktokActiveQueries);
        foreach ($tiktokActiveQueries as $query) {
            $this->assertStringContainsString('COALESCE(is_active, true) = true', $query['query']);
            $this->assertFalse(
                collect($query['bindings'])->contains(fn (mixed $binding): bool => is_bool($binding)),
                'Active-TikTok queries must not use bound booleans on PostgreSQL.',
            );
        }
        Http::assertNothingSent();
    }

    public function test_preview_marks_a_template_sku_as_unchanged_without_a_tiktok_deletion_target(): void
    {
        $target = 'INT-54256579274-KHAKKY';
        $this->seedCandidate(oldSku: $target);

        $preview = app(ShopeeSkuTiktokVariantCleanupService::class)->createPreview($this->mappingGroups());

        $this->assertSame('unchanged', $preview['items'][0]['status']);
        $this->assertSame(1, $preview['summary']['unchanged']);
        $this->assertDatabaseHas('tiktok_reconciliation_run_items', [
            'run_id' => $preview['run_id'],
            'item_key' => $preview['items'][0]['item_key'],
            'target_product_id' => null,
            'target_sku_id' => null,
        ]);
    }

    public function test_preview_blocks_when_authoritative_source_skus_do_not_match(): void
    {
        $this->seedCandidate(tiktokSellerSku: ' OTHER-SKU ');

        $item = app(ShopeeSkuTiktokVariantCleanupService::class)->createPreview($this->mappingGroups())['items'][0];

        $this->assertSame('blocked', $item['status']);
        $this->assertSame('source_sku_mismatch', $item['block_reason']);
    }

    public function test_preview_blocks_a_blank_authoritative_shopee_model_sku_as_incomplete(): void
    {
        $this->seedCandidate(oldSku: '', tiktokSellerSku: 'INT-54256579274-SAND');

        $item = app(ShopeeSkuTiktokVariantCleanupService::class)->createPreview($this->mappingGroups())['items'][0];

        $this->assertSame('blocked', $item['status']);
        $this->assertSame('incomplete_identity', $item['block_reason']);
    }

    public function test_preview_blocks_a_blank_authoritative_tiktok_seller_sku_as_incomplete(): void
    {
        $this->seedCandidate(tiktokSellerSku: '');

        $item = app(ShopeeSkuTiktokVariantCleanupService::class)->createPreview($this->mappingGroups())['items'][0];

        $this->assertSame('blocked', $item['status']);
        $this->assertSame('incomplete_identity', $item['block_reason']);
    }

    public function test_preview_blocks_two_blank_authoritative_source_skus_as_incomplete(): void
    {
        $this->seedCandidate(oldSku: '', tiktokSellerSku: '');

        $item = app(ShopeeSkuTiktokVariantCleanupService::class)->createPreview($this->mappingGroups())['items'][0];

        $this->assertSame('blocked', $item['status']);
        $this->assertSame('incomplete_identity', $item['block_reason']);
    }

    public function test_preview_blocks_when_normalized_authoritative_variant_names_match(): void
    {
        $this->seedCandidate(tiktokVariantName: '  khakky  ');

        $item = app(ShopeeSkuTiktokVariantCleanupService::class)->createPreview($this->mappingGroups())['items'][0];

        $this->assertSame('blocked', $item['status']);
        $this->assertSame('variant_names_match', $item['block_reason']);
    }

    public function test_preview_blocks_when_target_is_used_by_another_shopee_model_on_the_item(): void
    {
        $this->seedCandidate();
        DB::table('shopee_product_model')->insert([
            'item_id' => '54256579274',
            'model_id' => 'model-other',
            'name' => 'Other',
            'model_sku' => ' int-54256579274-khakky ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item = app(ShopeeSkuTiktokVariantCleanupService::class)->createPreview($this->mappingGroups())['items'][0];

        $this->assertSame('blocked', $item['status']);
        $this->assertSame('shopee_target_collision', $item['block_reason']);
    }

    public function test_preview_blocks_when_target_is_used_by_another_active_tiktok_sku_on_the_product(): void
    {
        $this->seedCandidate();
        $this->insertTiktokSku('tt-1', 'tt-sku-collision', 'INT-54256579274-KHAKKY', 'Other');

        $item = app(ShopeeSkuTiktokVariantCleanupService::class)->createPreview($this->mappingGroups())['items'][0];

        $this->assertSame('blocked', $item['status']);
        $this->assertSame('tiktok_target_collision', $item['block_reason']);
    }

    public function test_preview_blocks_every_ready_item_when_deletion_would_remove_the_last_tiktok_variant(): void
    {
        $this->seedCandidate(addSurvivor: false);

        $preview = app(ShopeeSkuTiktokVariantCleanupService::class)->createPreview($this->mappingGroups());

        $this->assertSame(0, $preview['summary']['eligible']);
        $this->assertSame('blocked', $preview['items'][0]['status']);
        $this->assertSame('last_tiktok_variant', $preview['items'][0]['block_reason']);
    }

    public function test_preview_blocks_an_item_with_incomplete_marketplace_identity(): void
    {
        $groups = collect([[
            'tiktok_product_id' => '',
            'mapping_only_variants' => collect([[
                'shopee_item_id' => '',
                'shopee_model_id' => '',
                'tiktok_sku_id' => '',
            ]]),
        ]]);

        $item = app(ShopeeSkuTiktokVariantCleanupService::class)->createPreview($groups)['items'][0];

        $this->assertSame('blocked', $item['status']);
        $this->assertSame('incomplete_identity', $item['block_reason']);
    }

    public function test_preview_revision_and_item_keys_are_stable_when_input_order_changes(): void
    {
        $this->seedCandidate();
        $this->seedCandidate(
            itemId: '54256579275',
            modelId: 'model-navy',
            productId: 'tt-2',
            tiktokSkuId: 'tt-sku-blue',
            oldSku: 'INT-54256579275-BLUE',
            variantName: 'Navy',
            tiktokVariantName: 'Blue',
        );
        $firstGroups = collect([
            $this->group('54256579274', 'model-khakky', 'tt-1', 'tt-sku-sand'),
            $this->group('54256579275', 'model-navy', 'tt-2', 'tt-sku-blue'),
        ]);
        $secondGroups = $firstGroups->reverse()->values()->map(function (array $group): array {
            $group['irrelevant_nested_secrets'] = [
                'Access_Token' => 'marker-access',
                'nested' => ['AUTHORIZATION' => 'marker-auth'],
            ];

            return $group;
        });
        $service = app(ShopeeSkuTiktokVariantCleanupService::class);

        $first = $service->createPreview($firstGroups);
        $second = $service->createPreview($secondGroups);

        $this->assertSame($first['revision'], $second['revision']);
        $this->assertSame(
            array_column($first['items'], 'item_key'),
            array_column($second['items'], 'item_key'),
        );
        $this->assertSame($first['revision'], $service->currentRevision($secondGroups));
    }

    public function test_preview_load_run_returns_only_cleanup_items(): void
    {
        $this->seedCandidate();
        $service = app(ShopeeSkuTiktokVariantCleanupService::class);
        $preview = $service->createPreview($this->mappingGroups());
        DB::table('tiktok_reconciliation_run_items')->insert([
            'run_id' => $preview['run_id'],
            'item_key' => 'new-product:unrelated',
            'action_type' => 'new_product',
            'status' => 'ready',
            'source_fingerprint' => str_repeat('d', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $loaded = $service->loadRun($preview['run_id']);

        $this->assertCount(1, $loaded['items']);
        $this->assertSame('shopee_sku_tiktok_delete', $loaded['items'][0]['action_type']);
    }

    private function seedCandidate(
        string $itemId = '54256579274',
        string $modelId = 'model-khakky',
        string $productId = 'tt-1',
        string $tiktokSkuId = 'tt-sku-sand',
        string $oldSku = 'INT-54256579274-SAND',
        string $variantName = 'Khakky',
        string $tiktokVariantName = 'Sand',
        ?string $tiktokSellerSku = null,
        bool $addSurvivor = true,
    ): void {
        DB::table('shopee_product_model')->insert([
            'item_id' => $itemId,
            'model_id' => $modelId,
            'name' => $variantName,
            'model_sku' => $oldSku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertTiktokSku($productId, $tiktokSkuId, $tiktokSellerSku ?? $oldSku, $tiktokVariantName);
        if ($addSurvivor) {
            $this->insertTiktokSku($productId, $tiktokSkuId.'-survivor', 'INT-SURVIVOR-'.$modelId, 'Survivor');
        }

        $stockMasterId = DB::table('stock_master')->insertGetId([
            'internal_sku' => 'INTERNAL-'.$modelId,
            'shopee_product_id' => $itemId,
            'shopee_sku' => $modelId,
            'shopee_seller_sku' => $oldSku,
            'tiktok_product_id' => $productId,
            'tiktok_sku' => $tiktokSkuId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sku_mappings')->insert([
            'stock_master_id' => $stockMasterId,
            'shopee_item_id' => $itemId,
            'shopee_model_id' => $modelId,
            'tiktok_product_id' => $productId,
            'tiktok_sku_id' => $tiktokSkuId,
            'seller_sku' => $oldSku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertTiktokSku(
        string $productId,
        string $skuId,
        string $sellerSku,
        string $variantName,
    ): void {
        DB::table('tiktok_products')->insert([
            'product_id' => $productId,
            'sku_id' => $skuId,
            'seller_sku' => $sellerSku,
            'product_name' => 'Authoritative product',
            'sku_name' => $variantName,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mappingGroups(): Collection
    {
        return collect([$this->group('54256579274', 'model-khakky', 'tt-1', 'tt-sku-sand')]);
    }

    private function group(string $itemId, string $modelId, string $productId, string $tiktokSkuId): array
    {
        return [
            'tiktok_product_id' => $productId,
            'product_name' => 'Stale display product',
            'mapping_only_variants' => collect([[
                'shopee_item_id' => $itemId,
                'shopee_model_id' => $modelId,
                'variant_name' => 'Stale display name',
                'seller_sku' => 'STALE-DISPLAY-SKU',
                'tiktok_sku_id' => $tiktokSkuId,
                'tiktok_variant_name' => 'Stale TikTok display name',
            ]]),
        ];
    }
}
