<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceApiService;
use App\Services\ShopeeSkuTiktokVariantCleanupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ShopeeSkuTiktokVariantCleanupServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceApiService&MockInterface $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = Mockery::mock(MarketplaceApiService::class);
        $this->app->instance(MarketplaceApiService::class, $this->api);

        Schema::create('stock_master', function (Blueprint $table): void {
            $table->id();
            $table->string('internal_sku');
            $table->string('shopee_product_id')->nullable();
            $table->string('shopee_sku')->nullable();
            $table->string('shopee_seller_sku')->nullable();
            $table->string('tiktok_product_id')->nullable();
            $table->string('tiktok_sku')->nullable();
            $table->string('tiktok_seller_sku')->nullable();
            $table->boolean('is_hidden_from_mapping')->default(false);
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
            $table->integer('stock_qty')->default(0);
            $table->boolean('is_active')->nullable()->default(true);
            $table->timestamps();
        });

        Http::fake();
    }

    public function test_submit_rejects_a_stale_ready_run_before_any_marketplace_write(): void
    {
        $this->seedCandidate();
        $preview = $this->service()->createPreview($this->mappingGroups());
        DB::table('shopee_product_model')
            ->where('item_id', '54256579274')
            ->where('model_id', 'model-khakky')
            ->update(['name' => 'Changed Again']);

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $this->mappingGroups());

        $this->assertSame('stale_revision', $result['status']);
        $this->assertDatabaseHas('tiktok_reconciliation_runs', [
            'id' => $preview['run_id'],
            'status' => 'ready_for_review',
        ]);
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
    }

    public function test_submit_returns_a_completed_run_without_repeating_marketplace_calls(): void
    {
        $this->seedCandidate();
        $preview = $this->service()->createPreview($this->mappingGroups());
        DB::table('tiktok_reconciliation_runs')->where('id', $preview['run_id'])->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $preview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->update([
                'status' => 'updated',
                'result' => json_encode(['outcome' => 'persisted-result'], JSON_THROW_ON_ERROR),
            ]);

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], collect());

        $this->assertSame('completed', $result['status']);
        $this->assertSame('persisted-result', $result['items'][0]['result']['outcome']);
        $this->api->shouldNotHaveReceived('fetchTiktokProduct');
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        $this->api->shouldNotHaveReceived('fetchShopeeModels');
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
    }

    public function test_submit_groups_two_targets_into_one_tiktok_partial_edit_and_omits_only_the_targets(): void
    {
        $this->seedTwoCandidatesOnOneProduct();
        $groups = $this->twoCandidateGroups();
        $preview = $this->service()->createPreview($groups);
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-soft', 'INT-54256579274-SOFT', 'Soft'),
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-survivor', 'INT-SURVIVOR', 'Survivor'),
        ]);
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-survivor', 'INT-SURVIVOR', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->twice()
            ->with('tt-1')
            ->andReturn($this->tiktokFetchResult($before), $this->tiktokFetchResult($after));
        $this->api->shouldReceive('fetchShopeeModels')
            ->times(3)
            ->with('54256579274')
            ->andReturn(
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-a', 'Soft Dusty', 'INT-54256579274-SOFT'),
                    $this->shopeeModel('model-b', 'Khakky', 'INT-54256579274-SAND'),
                ]),
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-a', 'Soft Dusty', 'INT-54256579274-SOFT-DUSTY'),
                    $this->shopeeModel('model-b', 'Khakky', 'INT-54256579274-SAND'),
                ]),
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-a', 'Soft Dusty', 'INT-54256579274-SOFT-DUSTY'),
                    $this->shopeeModel('model-b', 'Khakky', 'INT-54256579274-KHAKKY'),
                ]),
            );
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()
            ->withArgs(function (string $productId, array $payload): bool {
                $this->assertSame('tt-1', $productId);
                $this->assertSame('LISTING', $payload['save_mode']);
                $this->assertSame(['tt-sku-survivor'], array_column($payload['skus'], 'id'));

                return true;
            })
            ->andReturn($this->writeResult(true, 'TikTok updated'));
        $this->api->shouldReceive('updateShopeeModelSku')
            ->once()
            ->with('54256579274', 'model-a', 'INT-54256579274-SOFT-DUSTY')
            ->andReturn($this->writeResult(true, 'Shopee updated'));
        $this->api->shouldReceive('updateShopeeModelSku')
            ->once()
            ->with('54256579274', 'model-b', 'INT-54256579274-KHAKKY')
            ->andReturn($this->writeResult(true, 'Shopee updated'));

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(['updated', 'updated'], array_column($result['items'], 'status'));
        Http::assertNothingSent();
    }

    public function test_submit_stops_before_shopee_when_the_tiktok_write_fails(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->once()
            ->with('tt-1')
            ->andReturn($this->tiktokFetchResult($before));
        $this->api->shouldReceive('fetchShopeeModels')
            ->once()
            ->with('54256579274')
            ->andReturn($this->shopeeModelsResult([
                $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
            ]));
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()
            ->andReturn($this->writeResult(false, 'TikTok refused'));

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $this->assertSame('partial', $result['status']);
        $this->assertSame('partial', $result['items'][0]['status']);
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
        Http::assertNothingSent();
    }

    public function test_submit_marks_the_product_unverified_when_a_non_target_tiktok_sku_disappears(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);
        $afterWithMissingSurvivor = $this->tiktokProduct([]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->twice()
            ->with('tt-1')
            ->andReturn($this->tiktokFetchResult($before), $this->tiktokFetchResult($afterWithMissingSurvivor));
        $this->api->shouldReceive('fetchShopeeModels')
            ->once()
            ->with('54256579274')
            ->andReturn($this->shopeeModelsResult([
                $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
            ]));
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()
            ->andReturn($this->writeResult(true, 'TikTok accepted'));

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $this->assertSame('partial', $result['status']);
        $this->assertSame('submitted_unverified', $result['items'][0]['status']);
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
        Http::assertNothingSent();
    }

    public function test_submit_verifies_tiktok_before_shopee_and_reconciles_only_the_target_local_rows(): void
    {
        $this->seedCandidate(
            modelId: 'model-a',
            tiktokSkuId: 'tt-sku-soft',
            oldSku: 'INT-54256579274-SOFT',
            variantName: 'Soft Dusty',
            tiktokVariantName: 'Soft',
        );
        $groups = collect([$this->group('54256579274', 'model-a', 'tt-1', 'tt-sku-soft')]);
        $preview = $this->service()->createPreview($groups);
        $stockMasterId = (int) DB::table('stock_master')->where('shopee_sku', 'model-a')->value('id');
        DB::table('sku_mappings')->where('stock_master_id', $stockMasterId)->update([
            'tiktok_sku_name' => 'Soft',
            'tiktok_image_url' => 'https://example.test/old-soft.jpg',
        ]);
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-soft', 'INT-54256579274-SOFT', 'Soft'),
            $this->tiktokSku('tt-sku-soft-survivor', 'INT-SURVIVOR-model-a', 'Survivor'),
        ]);
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-soft-survivor', 'INT-SURVIVOR-model-a', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->once()->with('tt-1')->ordered()
            ->andReturn($this->tiktokFetchResult($before));
        $this->api->shouldReceive('fetchShopeeModels')
            ->once()->with('54256579274')->ordered()
            ->andReturn($this->shopeeModelsResult([
                $this->shopeeModel('model-a', 'Soft Dusty', 'INT-54256579274-SOFT'),
            ]));
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()->with('tt-1', Mockery::type('array'))->ordered()
            ->andReturn($this->writeResult(true, 'TikTok updated'));
        $this->api->shouldReceive('fetchTiktokProduct')
            ->once()->with('tt-1')->ordered()
            ->andReturn($this->tiktokFetchResult($after));
        $this->api->shouldReceive('updateShopeeModelSku')
            ->once()
            ->with('54256579274', 'model-a', 'INT-54256579274-SOFT-DUSTY')
            ->ordered()
            ->andReturn([
                ...$this->writeResult(true, 'Shopee updated'),
                'response' => ['code' => 0, 'access_token' => 'do-not-persist-this-secret'],
            ]);
        $this->api->shouldReceive('fetchShopeeModels')
            ->once()->with('54256579274')->ordered()
            ->andReturn($this->shopeeModelsResult([
                $this->shopeeModel('model-a', 'Soft Dusty', 'INT-54256579274-SOFT-DUSTY'),
            ]));

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $this->assertSame('completed', $result['status']);
        $this->assertDatabaseHas('shopee_product_model', [
            'item_id' => '54256579274',
            'model_id' => 'model-a',
            'model_sku' => 'INT-54256579274-SOFT-DUSTY',
        ]);
        $this->assertDatabaseHas('stock_master', [
            'id' => $stockMasterId,
            'shopee_seller_sku' => 'INT-54256579274-SOFT-DUSTY',
            'tiktok_product_id' => 'tt-1',
            'tiktok_sku' => null,
            'tiktok_seller_sku' => null,
            'is_hidden_from_mapping' => false,
        ]);
        $this->assertDatabaseHas('sku_mappings', [
            'stock_master_id' => $stockMasterId,
            'seller_sku' => 'INT-54256579274-SOFT-DUSTY',
            'tiktok_product_id' => 'tt-1',
            'tiktok_sku_id' => null,
            'tiktok_sku_name' => null,
            'tiktok_image_url' => null,
        ]);
        $this->assertDatabaseHas('tiktok_products', [
            'product_id' => 'tt-1',
            'sku_id' => 'tt-sku-soft',
            'stock_qty' => 0,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('tiktok_products', [
            'product_id' => 'tt-1',
            'sku_id' => 'tt-sku-soft-survivor',
            'stock_qty' => 7,
            'is_active' => true,
        ]);
        $storedResult = (string) DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $preview['run_id'])
            ->where('target_sku_id', 'tt-sku-soft')
            ->value('result');
        $this->assertStringNotContainsString('do-not-persist-this-secret', $storedResult);
        Http::assertNothingSent();
    }

    public function test_submit_does_not_write_the_target_sku_locally_when_shopee_verification_fails(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $stockMasterId = (int) DB::table('stock_master')->where('shopee_sku', 'model-khakky')->value('id');
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->twice()->with('tt-1')
            ->andReturn($this->tiktokFetchResult($before), $this->tiktokFetchResult($after));
        $this->api->shouldReceive('fetchShopeeModels')
            ->twice()->with('54256579274')
            ->andReturn(
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
                ]),
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
                ]),
            );
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()->andReturn($this->writeResult(true, 'TikTok updated'));
        $this->api->shouldReceive('updateShopeeModelSku')
            ->once()->with('54256579274', 'model-khakky', 'INT-54256579274-KHAKKY')
            ->andReturn($this->writeResult(true, 'Shopee accepted'));

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $this->assertSame('partial', $result['status']);
        $this->assertSame('submitted_unverified', $result['items'][0]['status']);
        $this->assertDatabaseHas('shopee_product_model', [
            'item_id' => '54256579274',
            'model_id' => 'model-khakky',
            'model_sku' => 'INT-54256579274-SAND',
        ]);
        $this->assertDatabaseHas('stock_master', [
            'id' => $stockMasterId,
            'shopee_seller_sku' => 'INT-54256579274-SAND',
            'tiktok_product_id' => 'tt-1',
            'tiktok_sku' => null,
            'tiktok_seller_sku' => null,
            'is_hidden_from_mapping' => false,
        ]);
        Http::assertNothingSent();
    }

    public function test_submit_retries_only_shopee_after_a_verified_tiktok_delete(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->times(3)->with('tt-1')
            ->andReturn(
                $this->tiktokFetchResult($before),
                $this->tiktokFetchResult($after),
                $this->tiktokFetchResult($after),
            );
        $this->api->shouldReceive('fetchShopeeModels')
            ->times(3)->with('54256579274')
            ->andReturn(
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
                ]),
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
                ]),
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-KHAKKY'),
                ]),
            );
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()->with('tt-1', Mockery::type('array'))
            ->andReturn($this->writeResult(true, 'TikTok updated'));
        $this->api->shouldReceive('updateShopeeModelSku')
            ->twice()->with('54256579274', 'model-khakky', 'INT-54256579274-KHAKKY')
            ->andReturn(
                $this->writeResult(false, 'Shopee temporarily unavailable'),
                $this->writeResult(true, 'Shopee updated'),
            );

        $first = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $this->assertSame('partial', $first['status']);
        $this->assertSame('partial', $first['items'][0]['status']);
        $this->assertTrue($first['items'][0]['result']['tiktok_verified']);

        $second = $this->service()->submit($preview['run_id'], $preview['revision'], collect());

        $this->assertSame('completed', $second['status']);
        $this->assertSame('updated', $second['items'][0]['status']);
        $this->assertSame('TikTok updated', $second['items'][0]['result']['tiktok_delete']['message']);
        $this->assertDatabaseHas('shopee_product_model', [
            'item_id' => '54256579274',
            'model_id' => 'model-khakky',
            'model_sku' => 'INT-54256579274-KHAKKY',
        ]);
        Http::assertNothingSent();
    }

    public function test_ambiguous_tiktok_delete_evidence_survives_a_transient_read_failure_and_later_retry(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->times(4)->with('tt-1')
            ->andReturn(
                $this->tiktokFetchResult($before),
                $this->catalogFailureResult('TikTok verification unavailable'),
                $this->catalogFailureResult('TikTok temporarily unavailable'),
                $this->tiktokFetchResult($after),
            );
        $this->api->shouldReceive('fetchShopeeModels')
            ->times(3)->with('54256579274')
            ->andReturn(
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
                ]),
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
                ]),
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-KHAKKY'),
                ]),
            );
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()->with('tt-1', Mockery::type('array'))
            ->andReturn($this->writeResult(true, 'TikTok accepted'));
        $this->api->shouldReceive('updateShopeeModelSku')
            ->once()->with('54256579274', 'model-khakky', 'INT-54256579274-KHAKKY')
            ->andReturn($this->writeResult(true, 'Shopee updated'));

        $first = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);
        $second = $this->service()->submit($preview['run_id'], $preview['revision'], collect());
        $third = $this->service()->submit($preview['run_id'], $preview['revision'], collect());

        $this->assertSame('partial', $first['status']);
        $this->assertSame('partial', $second['status']);
        $this->assertSame('completed', $third['status']);
        $this->assertTrue($third['items'][0]['result']['tiktok_delete_attempted']);
        $this->assertSame(['tt-sku-sand-survivor'], $third['items'][0]['result']['expected_non_target_sku_ids']);
        $this->assertSame('TikTok accepted', $third['items'][0]['result']['tiktok_delete']['message']);
        Http::assertNothingSent();
    }

    public function test_verified_tiktok_delete_evidence_survives_a_transient_shopee_read_failure_and_later_retry(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->times(4)->with('tt-1')
            ->andReturn(
                $this->tiktokFetchResult($before),
                $this->tiktokFetchResult($after),
                $this->tiktokFetchResult($after),
                $this->tiktokFetchResult($after),
            );
        $this->api->shouldReceive('fetchShopeeModels')
            ->times(3)->with('54256579274')
            ->andReturn(
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
                ]),
                $this->catalogFailureResult('Shopee temporarily unavailable'),
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-KHAKKY'),
                ]),
            );
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()->with('tt-1', Mockery::type('array'))
            ->andReturn($this->writeResult(true, 'TikTok updated'));
        $this->api->shouldReceive('updateShopeeModelSku')
            ->once()->with('54256579274', 'model-khakky', 'INT-54256579274-KHAKKY')
            ->andReturn($this->writeResult(false, 'Shopee response was ambiguous'));

        $first = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);
        $second = $this->service()->submit($preview['run_id'], $preview['revision'], collect());
        $third = $this->service()->submit($preview['run_id'], $preview['revision'], collect());

        $this->assertSame('partial', $first['status']);
        $this->assertSame('partial', $second['status']);
        $this->assertSame('completed', $third['status']);
        $this->assertTrue($third['items'][0]['result']['tiktok_verified']);
        $this->assertTrue($third['items'][0]['result']['tiktok_delete_attempted']);
        $this->assertSame('TikTok updated', $third['items'][0]['result']['tiktok_delete']['message']);
        Http::assertNothingSent();
    }

    public function test_claimed_retry_uses_persisted_survivors_when_tiktok_targets_are_already_absent(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        DB::table('tiktok_reconciliation_runs')->where('id', $preview['run_id'])->update([
            'status' => 'claimed',
            'submitted_at' => now(),
        ]);
        DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $preview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->update([
                'result' => json_encode([
                    'tiktok_delete_attempted' => true,
                    'expected_non_target_sku_ids' => ['tt-sku-sand-survivor'],
                ], JSON_THROW_ON_ERROR),
            ]);
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->once()->with('tt-1')
            ->andReturn($this->tiktokFetchResult($after));
        $this->api->shouldReceive('fetchShopeeModels')
            ->twice()->with('54256579274')
            ->andReturn(
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
                ]),
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-KHAKKY'),
                ]),
            );
        $this->api->shouldReceive('updateShopeeModelSku')
            ->once()->with('54256579274', 'model-khakky', 'INT-54256579274-KHAKKY')
            ->andReturn($this->writeResult(true, 'Shopee updated'));

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], collect());

        $this->assertSame('completed', $result['status']);
        $this->assertSame('updated', $result['items'][0]['status']);
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        Http::assertNothingSent();
    }

    public function test_partial_retry_does_not_resubmit_an_accepted_tiktok_delete_that_is_not_yet_visible(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->times(3)->with('tt-1')
            ->andReturn(
                $this->tiktokFetchResult($before),
                $this->tiktokFetchResult($before),
                $this->tiktokFetchResult($before),
            );
        $this->api->shouldReceive('fetchShopeeModels')
            ->twice()->with('54256579274')
            ->andReturn($this->shopeeModelsResult([
                $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
            ]));
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()->with('tt-1', Mockery::type('array'))
            ->andReturn($this->writeResult(true, 'TikTok accepted'));

        $first = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);
        $second = $this->service()->submit($preview['run_id'], $preview['revision'], collect());

        $this->assertSame('partial', $first['status']);
        $this->assertSame('submitted_unverified', $first['items'][0]['status']);
        $this->assertSame('partial', $second['status']);
        $this->assertSame('submitted_unverified', $second['items'][0]['status']);
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
        Http::assertNothingSent();
    }

    public function test_submit_persists_tiktok_verification_before_attempting_the_shopee_write(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->twice()->with('tt-1')
            ->andReturn($this->tiktokFetchResult($before), $this->tiktokFetchResult($after));
        $this->api->shouldReceive('fetchShopeeModels')
            ->once()->with('54256579274')
            ->andReturn($this->shopeeModelsResult([
                $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
            ]));
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()->andReturn($this->writeResult(true, 'TikTok accepted'));
        $this->api->shouldReceive('updateShopeeModelSku')
            ->once()
            ->andThrow(new \RuntimeException('simulated process interruption after Shopee dispatch'));

        $first = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $item = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $preview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->first();
        $result = json_decode((string) $item->result, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('partial', $first['status']);
        $this->assertSame('partial', $item->status);
        $this->assertSame('product_execution_exception', $item->block_reason);
        $this->assertTrue($result['tiktok_verified']);
        $this->assertSame(['tt-sku-sand-survivor'], $result['expected_non_target_sku_ids']);
        $this->assertStringNotContainsString('simulated process interruption', (string) $item->result);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->once()->with('tt-1')
            ->andReturn($this->tiktokFetchResult($after));
        $this->api->shouldReceive('fetchShopeeModels')
            ->once()->with('54256579274')
            ->andReturn($this->shopeeModelsResult([
                $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-KHAKKY'),
            ]));

        $retry = $this->service()->submit($preview['run_id'], $preview['revision'], collect());

        $this->assertSame('completed', $retry['status']);
        $this->assertSame('updated', $retry['items'][0]['status']);
        Http::assertNothingSent();
    }

    public function test_submit_rechecks_a_fresh_shopee_target_collision_before_tiktok_deletion(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->once()->with('tt-1')
            ->andReturn($this->tiktokFetchResult($before));
        $this->api->shouldReceive('fetchShopeeModels')
            ->once()->with('54256579274')
            ->andReturn($this->shopeeModelsResult([
                $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
                $this->shopeeModel('model-other', 'Other', 'int-54256579274-khakky'),
            ]));

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('shopee_target_collision', $result['items'][0]['block_reason']);
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
        Http::assertNothingSent();
    }

    public function test_submit_rechecks_a_fresh_tiktok_target_collision_before_deletion(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'int-54256579274-khakky', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->once()->with('tt-1')
            ->andReturn($this->tiktokFetchResult($before));
        $this->api->shouldReceive('fetchShopeeModels')
            ->once()->with('54256579274')
            ->andReturn($this->shopeeModelsResult([
                $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
            ]));

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('tiktok_target_collision', $result['items'][0]['block_reason']);
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
        Http::assertNothingSent();
    }

    public function test_preview_blocks_distinct_ready_variants_that_generate_the_same_shopee_target(): void
    {
        $this->seedCandidate(
            modelId: 'model-a',
            productId: 'tt-1',
            tiktokSkuId: 'tt-sku-a',
            oldSku: 'INT-54256579274-OLD-A',
            variantName: 'Soft Dusty',
            tiktokVariantName: 'Old A',
        );
        $this->seedCandidate(
            modelId: 'model-b',
            productId: 'tt-2',
            tiktokSkuId: 'tt-sku-b',
            oldSku: 'INT-54256579274-OLD-B',
            variantName: 'Soft Dusty!',
            tiktokVariantName: 'Old B',
        );
        $groups = collect([
            $this->group('54256579274', 'model-a', 'tt-1', 'tt-sku-a'),
            $this->group('54256579274', 'model-b', 'tt-2', 'tt-sku-b'),
        ]);

        $preview = $this->service()->createPreview($groups);
        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $this->assertSame(0, $preview['summary']['eligible']);
        $this->assertSame(['blocked', 'blocked'], array_column($preview['items'], 'status'));
        $this->assertSame(
            ['planned_shopee_target_collision'],
            array_values(array_unique(array_column($preview['items'], 'block_reason'))),
        );
        $this->assertSame('completed', $result['status']);
        $this->api->shouldNotHaveReceived('fetchTiktokProduct');
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
        Http::assertNothingSent();
    }

    public function test_submit_revalidates_planned_target_collisions_before_any_marketplace_call(): void
    {
        $this->seedTwoCandidatesOnOneProduct();
        $groups = $this->twoCandidateGroups();
        $preview = $this->service()->createPreview($groups);
        $row = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $preview['run_id'])
            ->where('item_key', 'like', '%:model-b:%')
            ->first();
        $payload = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);
        $payload['target_sku'] = 'INT-54256579274-SOFT-DUSTY';
        DB::table('tiktok_reconciliation_run_items')->where('id', $row->id)->update([
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(['failed', 'failed'], array_column($result['items'], 'status'));
        $this->assertSame(
            ['planned_shopee_target_collision'],
            array_values(array_unique(array_column($result['items'], 'block_reason'))),
        );
        $this->api->shouldNotHaveReceived('fetchTiktokProduct');
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
        Http::assertNothingSent();
    }

    public function test_submit_blocks_an_initial_product_when_only_some_grouped_targets_are_freshly_present(): void
    {
        $this->seedTwoCandidatesOnOneProduct();
        $groups = $this->twoCandidateGroups();
        $preview = $this->service()->createPreview($groups);
        $missingOneTarget = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-survivor', 'INT-SURVIVOR', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->once()->with('tt-1')
            ->andReturn($this->tiktokFetchResult($missingOneTarget));
        $this->api->shouldReceive('fetchShopeeModels')
            ->once()->with('54256579274')
            ->andReturn($this->shopeeModelsResult([
                $this->shopeeModel('model-a', 'Soft Dusty', 'INT-54256579274-SOFT'),
                $this->shopeeModel('model-b', 'Khakky', 'INT-54256579274-SAND'),
            ]));

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(['failed', 'failed'], array_column($result['items'], 'status'));
        $this->assertSame(
            ['tiktok_targets_missing_before_submit'],
            array_values(array_unique(array_column($result['items'], 'block_reason'))),
        );
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
        Http::assertNothingSent();
    }

    public function test_partial_retry_does_not_mutate_a_persisted_stock_mapping_that_was_repurposed(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $stockMasterId = (int) DB::table('stock_master')->where('shopee_sku', 'model-khakky')->value('id');
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->times(3)->with('tt-1')
            ->andReturn(
                $this->tiktokFetchResult($before),
                $this->tiktokFetchResult($after),
                $this->tiktokFetchResult($after),
            );
        $this->api->shouldReceive('fetchShopeeModels')
            ->twice()->with('54256579274')
            ->andReturn($this->shopeeModelsResult([
                $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
            ]));
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()->andReturn($this->writeResult(true, 'TikTok updated'));
        $this->api->shouldReceive('updateShopeeModelSku')
            ->once()->andReturn($this->writeResult(false, 'Shopee unavailable'));

        $first = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);
        $this->assertSame('partial', $first['status']);

        DB::table('stock_master')->where('id', $stockMasterId)->update([
            'shopee_product_id' => 'other-item',
            'shopee_sku' => 'other-model',
            'shopee_seller_sku' => 'OTHER-SHOPEE-SKU',
            'tiktok_product_id' => 'tt-other',
            'tiktok_sku' => 'tt-other-sku',
            'tiktok_seller_sku' => 'OTHER-TIKTOK-SKU',
        ]);
        DB::table('sku_mappings')->where('stock_master_id', $stockMasterId)->update([
            'shopee_item_id' => 'other-item',
            'shopee_model_id' => 'other-model',
            'tiktok_product_id' => 'tt-other',
            'tiktok_sku_id' => 'tt-other-sku',
            'tiktok_sku_name' => 'Other',
            'seller_sku' => 'OTHER-SHOPEE-SKU',
            'tiktok_image_url' => 'https://example.test/other.jpg',
        ]);

        $second = $this->service()->submit($preview['run_id'], $preview['revision'], collect());

        $this->assertSame('partial', $second['status']);
        $this->assertSame('local_identity_changed', $second['items'][0]['block_reason']);
        $this->assertDatabaseHas('stock_master', [
            'id' => $stockMasterId,
            'shopee_product_id' => 'other-item',
            'shopee_sku' => 'other-model',
            'shopee_seller_sku' => 'OTHER-SHOPEE-SKU',
            'tiktok_product_id' => 'tt-other',
            'tiktok_sku' => 'tt-other-sku',
            'tiktok_seller_sku' => 'OTHER-TIKTOK-SKU',
        ]);
        $this->assertDatabaseHas('sku_mappings', [
            'stock_master_id' => $stockMasterId,
            'shopee_item_id' => 'other-item',
            'shopee_model_id' => 'other-model',
            'tiktok_product_id' => 'tt-other',
            'tiktok_sku_id' => 'tt-other-sku',
            'tiktok_sku_name' => 'Other',
            'seller_sku' => 'OTHER-SHOPEE-SKU',
            'tiktok_image_url' => 'https://example.test/other.jpg',
        ]);
        Http::assertNothingSent();
    }

    public function test_submit_rechecks_local_identity_inside_tiktok_reflection_after_the_network_call(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $stockMasterId = (int) DB::table('stock_master')->where('shopee_sku', 'model-khakky')->value('id');
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->once()->with('tt-1')->ordered()
            ->andReturn($this->tiktokFetchResult($before));
        $this->api->shouldReceive('fetchShopeeModels')
            ->once()->with('54256579274')->ordered()
            ->andReturn($this->shopeeModelsResult([
                $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
            ]));
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()->ordered()
            ->andReturn($this->writeResult(true, 'TikTok updated'));
        $this->api->shouldReceive('fetchTiktokProduct')
            ->once()->with('tt-1')->ordered()
            ->andReturnUsing(function () use ($stockMasterId, $after): array {
                $this->repurposeStockMapping($stockMasterId);

                return $this->tiktokFetchResult($after);
            });

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $this->assertSame('partial', $result['status']);
        $this->assertSame('local_identity_changed', $result['items'][0]['block_reason']);
        $this->assertRepurposedStockMappingRemains($stockMasterId);
        $this->assertDatabaseHas('tiktok_products', [
            'product_id' => 'tt-1',
            'sku_id' => 'tt-sku-sand',
            'stock_qty' => 0,
            'is_active' => false,
        ]);
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
        Http::assertNothingSent();
    }

    public function test_submit_rechecks_local_identity_inside_shopee_reconciliation_after_the_network_call(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $stockMasterId = (int) DB::table('stock_master')->where('shopee_sku', 'model-khakky')->value('id');
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->twice()->with('tt-1')
            ->andReturn($this->tiktokFetchResult($before), $this->tiktokFetchResult($after));
        $this->api->shouldReceive('fetchShopeeModels')
            ->twice()->with('54256579274')
            ->andReturn(
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
                ]),
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-KHAKKY'),
                ]),
            );
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()->andReturn($this->writeResult(true, 'TikTok updated'));
        $this->api->shouldReceive('updateShopeeModelSku')
            ->once()
            ->andReturnUsing(function () use ($stockMasterId): array {
                $this->repurposeStockMapping($stockMasterId);

                return $this->writeResult(true, 'Shopee updated');
            });

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $this->assertSame('partial', $result['status']);
        $this->assertSame('local_identity_changed', $result['items'][0]['block_reason']);
        $this->assertRepurposedStockMappingRemains($stockMasterId);
        $this->assertDatabaseHas('shopee_product_model', [
            'item_id' => '54256579274',
            'model_id' => 'model-khakky',
            'model_sku' => 'INT-54256579274-SAND',
        ]);
        Http::assertNothingSent();
    }

    public function test_submit_does_not_mark_updated_when_the_exact_tiktok_cache_row_disappears_during_verification(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->once()->with('tt-1')->ordered()
            ->andReturn($this->tiktokFetchResult($before));
        $this->api->shouldReceive('fetchShopeeModels')
            ->once()->with('54256579274')->ordered()
            ->andReturn($this->shopeeModelsResult([
                $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
            ]));
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()->ordered()
            ->andReturn($this->writeResult(true, 'TikTok updated'));
        $this->api->shouldReceive('fetchTiktokProduct')
            ->once()->with('tt-1')->ordered()
            ->andReturnUsing(function () use ($after): array {
                DB::table('tiktok_products')
                    ->where('product_id', 'tt-1')
                    ->where('sku_id', 'tt-sku-sand')
                    ->delete();

                return $this->tiktokFetchResult($after);
            });

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $this->assertSame('partial', $result['status']);
        $this->assertSame('tiktok_cache_identity_changed', $result['items'][0]['block_reason']);
        $this->assertDatabaseMissing('tiktok_products', [
            'product_id' => 'tt-1',
            'sku_id' => 'tt-sku-sand',
        ]);
        $this->assertDatabaseHas('stock_master', [
            'shopee_sku' => 'model-khakky',
            'tiktok_product_id' => 'tt-1',
            'tiktok_sku' => 'tt-sku-sand',
        ]);
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
        Http::assertNothingSent();
    }

    public function test_submit_does_not_overwrite_a_newer_shopee_cache_state_after_remote_verification(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $stockMasterId = (int) DB::table('stock_master')->where('shopee_sku', 'model-khakky')->value('id');
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->twice()->with('tt-1')
            ->andReturn($this->tiktokFetchResult($before), $this->tiktokFetchResult($after));
        $this->api->shouldReceive('fetchShopeeModels')
            ->twice()->with('54256579274')
            ->andReturn(
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND'),
                ]),
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-KHAKKY'),
                ]),
            );
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()->andReturn($this->writeResult(true, 'TikTok updated'));
        $this->api->shouldReceive('updateShopeeModelSku')
            ->once()
            ->andReturnUsing(function (): array {
                DB::table('shopee_product_model')
                    ->where('item_id', '54256579274')
                    ->where('model_id', 'model-khakky')
                    ->update(['model_sku' => 'NEWER-LOCAL-SKU']);

                return $this->writeResult(true, 'Shopee updated');
            });

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $this->assertSame('partial', $result['status']);
        $this->assertSame('shopee_cache_identity_changed', $result['items'][0]['block_reason']);
        $this->assertDatabaseHas('shopee_product_model', [
            'item_id' => '54256579274',
            'model_id' => 'model-khakky',
            'model_sku' => 'NEWER-LOCAL-SKU',
        ]);
        $this->assertDatabaseHas('stock_master', [
            'id' => $stockMasterId,
            'shopee_seller_sku' => 'INT-54256579274-SAND',
        ]);
        Http::assertNothingSent();
    }

    public function test_submit_leaves_a_product_owned_by_an_unexpired_execution_lease_untouched(): void
    {
        $this->seedTwoCandidatesOnOneProduct();
        $groups = $this->twoCandidateGroups();
        $preview = $this->service()->createPreview($groups);
        $otherOwner = (string) \Illuminate\Support\Str::uuid();
        $leaseUntil = now()->addMinutes(10);
        DB::table('tiktok_reconciliation_runs')->where('id', $preview['run_id'])->update([
            'status' => 'claimed',
            'submitted_at' => now(),
        ]);
        $ownedRowId = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $preview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->orderBy('id')
            ->value('id');
        DB::table('tiktok_reconciliation_run_items')
            ->where('id', $ownedRowId)
            ->update([
                'execution_owner' => $otherOwner,
                'execution_lease_until' => $leaseUntil,
                'execution_attempts' => 4,
            ]);

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], collect());

        $rows = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $preview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->orderBy('id')
            ->get();
        $this->assertSame('claimed', $result['status']);
        $this->assertSame(['ready', 'ready'], $rows->pluck('status')->all());
        $this->assertSame($otherOwner, $rows[0]->execution_owner);
        $this->assertSame(4, (int) $rows[0]->execution_attempts);
        $this->assertNotNull($rows[0]->execution_lease_until);
        $this->assertNull($rows[1]->execution_owner);
        $this->assertSame(0, (int) $rows[1]->execution_attempts);
        $this->api->shouldNotHaveReceived('fetchTiktokProduct');
        $this->api->shouldNotHaveReceived('fetchShopeeModels');
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
        Http::assertNothingSent();
    }

    public function test_an_unexpired_product_lease_in_another_run_blocks_a_different_cleanup_on_the_same_product(): void
    {
        $this->seedCandidate(
            modelId: 'model-a',
            tiktokSkuId: 'tt-sku-a',
            oldSku: 'INT-54256579274-OLD-A',
            variantName: 'Target A',
            tiktokVariantName: 'Old A',
            addSurvivor: false,
        );
        $this->seedCandidate(
            modelId: 'model-b',
            tiktokSkuId: 'tt-sku-b',
            oldSku: 'INT-54256579274-OLD-B',
            variantName: 'Target B',
            tiktokVariantName: 'Old B',
            addSurvivor: false,
        );
        $this->insertTiktokSku('tt-1', 'tt-sku-survivor', 'INT-SURVIVOR', 'Survivor');
        $firstPreview = $this->service()->createPreview(collect([
            $this->group('54256579274', 'model-a', 'tt-1', 'tt-sku-a'),
        ]));
        $secondPreview = $this->service()->createPreview(collect([
            $this->group('54256579274', 'model-b', 'tt-1', 'tt-sku-b'),
        ]));
        $otherOwner = (string) \Illuminate\Support\Str::uuid();
        DB::table('tiktok_reconciliation_runs')->where('id', $firstPreview['run_id'])->update([
            'status' => 'claimed',
            'submitted_at' => now()->subMinutes(16),
        ]);
        DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $firstPreview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->update([
                'execution_owner' => $otherOwner,
                'execution_lease_until' => now()->addMinutes(10),
                'execution_attempts' => 1,
            ]);
        DB::table('tiktok_reconciliation_runs')->where('id', $secondPreview['run_id'])->update([
            'status' => 'claimed',
            'submitted_at' => now(),
        ]);

        $result = $this->service()->submit($secondPreview['run_id'], $secondPreview['revision'], collect());

        $firstRow = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $firstPreview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->first();
        $secondRow = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $secondPreview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->first();
        $this->assertSame('claimed', $result['status']);
        $this->assertSame($otherOwner, $firstRow->execution_owner);
        $this->assertSame(1, (int) $firstRow->execution_attempts);
        $this->assertNull($secondRow->execution_owner);
        $this->assertSame(0, (int) $secondRow->execution_attempts);
        $this->api->shouldNotHaveReceived('fetchTiktokProduct');
        $this->api->shouldNotHaveReceived('fetchShopeeModels');
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
        Http::assertNothingSent();
    }

    public function test_an_active_cross_run_shopee_target_collision_blocks_a_different_tiktok_product(): void
    {
        $this->seedCandidate(
            modelId: 'model-a',
            productId: 'tt-a',
            tiktokSkuId: 'tt-sku-a',
            oldSku: 'INT-54256579274-OLD-A',
            variantName: 'Soft Dusty',
            tiktokVariantName: 'Old A',
        );
        $this->seedCandidate(
            modelId: 'model-b',
            productId: 'tt-b',
            tiktokSkuId: 'tt-sku-b',
            oldSku: 'INT-54256579274-OLD-B',
            variantName: 'Soft Dusty!',
            tiktokVariantName: 'Old B',
        );
        $firstGroups = collect([$this->group('54256579274', 'model-a', 'tt-a', 'tt-sku-a')]);
        $secondGroups = collect([$this->group('54256579274', 'model-b', 'tt-b', 'tt-sku-b')]);
        $firstPreview = $this->service()->createPreview($firstGroups);
        $secondPreview = $this->service()->createPreview($secondGroups);
        $firstOwner = (string) \Illuminate\Support\Str::uuid();
        DB::table('tiktok_reconciliation_runs')->where('id', $firstPreview['run_id'])->update([
            'status' => 'claimed',
            'submitted_at' => now()->subMinutes(16),
        ]);
        DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $firstPreview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->update([
                'execution_owner' => $firstOwner,
                'execution_lease_until' => now()->addMinutes(10),
                'execution_attempts' => 1,
            ]);
        DB::table('tiktok_reconciliation_runs')->where('id', $secondPreview['run_id'])->update([
            'status' => 'claimed',
            'submitted_at' => now(),
        ]);

        $result = $this->service()->submit($secondPreview['run_id'], $secondPreview['revision'], collect());

        $firstRow = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $firstPreview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->first();
        $secondRow = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $secondPreview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->first();
        $this->assertSame('failed', $result['status']);
        $this->assertSame($firstOwner, $firstRow->execution_owner);
        $this->assertSame('ready', $firstRow->status);
        $this->assertSame('failed', $secondRow->status);
        $this->assertSame('cross_run_shopee_target_collision', $secondRow->block_reason);
        $this->assertNull($secondRow->execution_owner);
        $this->assertSame(0, (int) $secondRow->execution_attempts);
        $this->api->shouldNotHaveReceived('fetchTiktokProduct');
        $this->api->shouldNotHaveReceived('fetchShopeeModels');
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
        Http::assertNothingSent();
    }

    public function test_prior_subset_delete_intent_blocks_a_superset_cleanup_before_marketplace_access(): void
    {
        $this->assertOverlappingPriorRunBlocks(['tt-sku-a'], ['tt-sku-a', 'tt-sku-b']);
    }

    public function test_prior_superset_delete_intent_blocks_a_subset_cleanup_before_marketplace_access(): void
    {
        $this->assertOverlappingPriorRunBlocks(['tt-sku-a', 'tt-sku-b'], ['tt-sku-a']);
    }

    public function test_prior_partial_overlap_delete_intent_blocks_a_new_cleanup_before_marketplace_access(): void
    {
        $this->assertOverlappingPriorRunBlocks(['tt-sku-a', 'tt-sku-b'], ['tt-sku-b', 'tt-sku-c']);
    }

    public function test_unexpired_prior_overlap_still_persists_the_manual_reconciliation_block(): void
    {
        $this->assertOverlappingPriorRunBlocks(
            ['tt-sku-a'],
            ['tt-sku-a', 'tt-sku-b'],
            priorHasUnexpiredLease: true,
        );
    }

    public function test_original_evidence_run_retries_after_blocking_a_passive_overlapping_run(): void
    {
        $this->seedCandidate(
            modelId: 'model-a',
            tiktokSkuId: 'tt-sku-a',
            oldSku: 'INT-54256579274-OLD-A',
            variantName: 'Target A',
            tiktokVariantName: 'Old A',
            addSurvivor: false,
        );
        $this->seedCandidate(
            modelId: 'model-b',
            tiktokSkuId: 'tt-sku-b',
            oldSku: 'INT-54256579274-OLD-B',
            variantName: 'Target B',
            tiktokVariantName: 'Old B',
            addSurvivor: false,
        );
        $this->insertTiktokSku('tt-1', 'tt-sku-survivor', 'INT-SURVIVOR', 'Survivor');
        $groupA = $this->group('54256579274', 'model-a', 'tt-1', 'tt-sku-a');
        $groupB = $this->group('54256579274', 'model-b', 'tt-1', 'tt-sku-b');
        $originalPreview = $this->service()->createPreview(collect([$groupA]));
        $overlappingPreview = $this->service()->createPreview(collect([$groupA, $groupB]));
        $this->persistRunDeleteEvidence(
            $originalPreview['run_id'],
            ['tt-sku-b', 'tt-sku-survivor'],
        );
        DB::table('tiktok_reconciliation_runs')->where('id', $overlappingPreview['run_id'])->update([
            'status' => 'claimed',
            'submitted_at' => now(),
        ]);

        $blocked = $this->service()->submit(
            $overlappingPreview['run_id'],
            $overlappingPreview['revision'],
            collect(),
        );

        $this->assertSame('failed', $blocked['status']);
        $this->assertSame(
            ['overlapping_tiktok_delete_history'],
            array_values(array_unique(array_column($blocked['items'], 'block_reason'))),
        );
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-b', 'INT-54256579274-OLD-B', 'Old B'),
            $this->tiktokSku('tt-sku-survivor', 'INT-SURVIVOR', 'Survivor'),
        ]);
        $this->api->shouldReceive('fetchTiktokProduct')->once()->with('tt-1')
            ->andReturn($this->tiktokFetchResult($after));
        $this->api->shouldReceive('fetchShopeeModels')->twice()->with('54256579274')
            ->andReturn(
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-a', 'Target A', 'INT-54256579274-OLD-A'),
                    $this->shopeeModel('model-b', 'Target B', 'INT-54256579274-OLD-B'),
                ]),
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-a', 'Target A', 'INT-54256579274-TARGET-A'),
                    $this->shopeeModel('model-b', 'Target B', 'INT-54256579274-OLD-B'),
                ]),
            );
        $this->api->shouldReceive('updateShopeeModelSku')->once()
            ->with('54256579274', 'model-a', 'INT-54256579274-TARGET-A')
            ->andReturn($this->writeResult(true, 'Shopee updated'));

        $retried = $this->service()->submit(
            $originalPreview['run_id'],
            $originalPreview['revision'],
            collect(),
        );

        $blockedRows = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $overlappingPreview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->get();
        $this->assertSame('completed', $retried['status']);
        $this->assertSame('updated', $retried['items'][0]['status']);
        $this->assertSame(['failed'], $blockedRows->pluck('status')->unique()->values()->all());
        $this->assertSame([null], $blockedRows->pluck('execution_owner')->unique()->values()->all());
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        Http::assertNothingSent();
    }

    public function test_original_target_run_ignores_a_passive_cross_product_collision_row(): void
    {
        $this->seedCandidate(
            modelId: 'model-a',
            productId: 'tt-a',
            tiktokSkuId: 'tt-sku-a',
            oldSku: 'INT-54256579274-OLD-A',
            variantName: 'Soft Dusty',
            tiktokVariantName: 'Old A',
        );
        $this->seedCandidate(
            modelId: 'model-b',
            productId: 'tt-b',
            tiktokSkuId: 'tt-sku-b',
            oldSku: 'INT-54256579274-OLD-B',
            variantName: 'Soft Dusty!',
            tiktokVariantName: 'Old B',
        );
        $groupA = $this->group('54256579274', 'model-a', 'tt-a', 'tt-sku-a');
        $groupB = $this->group('54256579274', 'model-b', 'tt-b', 'tt-sku-b');
        $originalPreview = $this->service()->createPreview(collect([$groupA]));
        $collisionPreview = $this->service()->createPreview(collect([$groupB]));
        $this->persistRunDeleteEvidence($originalPreview['run_id'], ['tt-sku-a-survivor']);
        DB::table('tiktok_reconciliation_runs')->where('id', $collisionPreview['run_id'])->update([
            'status' => 'claimed',
            'submitted_at' => now(),
        ]);

        $blocked = $this->service()->submit($collisionPreview['run_id'], $collisionPreview['revision'], collect());

        $this->assertSame('failed', $blocked['status']);
        $this->assertSame('cross_run_shopee_target_collision', $blocked['items'][0]['block_reason']);
        $after = ['id' => 'tt-a', 'title' => 'A', 'skus' => [
            $this->tiktokSku('tt-sku-a-survivor', 'INT-SURVIVOR-model-a', 'Survivor'),
        ]];
        $this->api->shouldReceive('fetchTiktokProduct')->once()->with('tt-a')
            ->andReturn($this->tiktokFetchResult($after));
        $this->api->shouldReceive('fetchShopeeModels')->twice()->with('54256579274')
            ->andReturn(
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-a', 'Soft Dusty', 'INT-54256579274-OLD-A'),
                    $this->shopeeModel('model-b', 'Soft Dusty!', 'INT-54256579274-OLD-B'),
                ]),
                $this->shopeeModelsResult([
                    $this->shopeeModel('model-a', 'Soft Dusty', 'INT-54256579274-SOFT-DUSTY'),
                    $this->shopeeModel('model-b', 'Soft Dusty!', 'INT-54256579274-OLD-B'),
                ]),
            );
        $this->api->shouldReceive('updateShopeeModelSku')->once()
            ->with('54256579274', 'model-a', 'INT-54256579274-SOFT-DUSTY')
            ->andReturn($this->writeResult(true, 'Shopee updated'));

        $retried = $this->service()->submit($originalPreview['run_id'], $originalPreview['revision'], collect());

        $this->assertSame('completed', $retried['status']);
        $this->assertSame('updated', $retried['items'][0]['status']);
        $this->assertDatabaseHas('tiktok_reconciliation_run_items', [
            'run_id' => $collisionPreview['run_id'],
            'status' => 'failed',
            'block_reason' => 'cross_run_shopee_target_collision',
        ]);
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        Http::assertNothingSent();
    }

    public function test_expired_cross_run_takeover_inherits_delete_evidence_and_never_redeletes(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $firstPreview = $this->service()->createPreview($groups);
        $secondPreview = $this->service()->createPreview($groups);
        $persistedDelete = $this->writeResult(true, 'Accepted in the expired owner');
        DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $firstPreview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->update([
                'status' => 'submitted_unverified',
                'block_reason' => 'tiktok_delete_unverified',
                'result' => json_encode([
                    'tiktok_delete_attempted' => true,
                    'expected_non_target_sku_ids' => ['tt-sku-sand-survivor'],
                    'tiktok_delete' => $persistedDelete,
                ], JSON_THROW_ON_ERROR),
                'execution_owner' => (string) \Illuminate\Support\Str::uuid(),
                'execution_lease_until' => now()->subMinute(),
                'execution_attempts' => 1,
            ]);
        DB::table('tiktok_reconciliation_runs')->where('id', $secondPreview['run_id'])->update([
            'status' => 'claimed',
            'submitted_at' => now(),
        ]);
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);
        $targetSku = 'INT-54256579274-KHAKKY';
        $this->api->shouldReceive('fetchTiktokProduct')->once()->with('tt-1')
            ->andReturn($this->tiktokFetchResult($after));
        $this->api->shouldReceive('fetchShopeeModels')->twice()->with('54256579274')
            ->andReturn(
                $this->shopeeModelsResult([$this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND')]),
                $this->shopeeModelsResult([$this->shopeeModel('model-khakky', 'Khakky', $targetSku)]),
            );
        $this->api->shouldReceive('updateShopeeModelSku')->once()
            ->with('54256579274', 'model-khakky', $targetSku)
            ->andReturn($this->writeResult(true, 'Shopee updated'));

        $result = $this->service()->submit($secondPreview['run_id'], $secondPreview['revision'], collect());

        $firstRow = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $firstPreview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->first();
        $secondRow = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $secondPreview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->first();
        $secondResult = json_decode($secondRow->result, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('completed', $result['status']);
        $this->assertNull($firstRow->execution_owner);
        $this->assertSame('updated', $secondRow->status);
        $this->assertSame(1, (int) $secondRow->execution_attempts);
        $this->assertTrue($secondResult['tiktok_delete_attempted']);
        $this->assertSame($persistedDelete, $secondResult['tiktok_delete']);
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        Http::assertNothingSent();
    }

    public function test_expired_execution_lease_takeover_preserves_delete_evidence_without_redeleting(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $beforeWithoutTarget = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);
        $targetSku = 'INT-54256579274-KHAKKY';
        $persistedDelete = $this->writeResult(true, 'Previously accepted');
        DB::table('tiktok_reconciliation_runs')->where('id', $preview['run_id'])->update([
            'status' => 'partial',
            'submitted_at' => now()->subMinutes(20),
        ]);
        DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $preview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->update([
                'status' => 'submitted_unverified',
                'block_reason' => 'tiktok_delete_unverified',
                'result' => json_encode([
                    'tiktok_delete_attempted' => true,
                    'expected_non_target_sku_ids' => ['tt-sku-sand-survivor'],
                    'tiktok_delete' => $persistedDelete,
                ], JSON_THROW_ON_ERROR),
                'execution_owner' => (string) \Illuminate\Support\Str::uuid(),
                'execution_lease_until' => now()->subMinute(),
                'execution_attempts' => 2,
            ]);

        $this->api->shouldReceive('fetchTiktokProduct')
            ->once()
            ->with('tt-1')
            ->andReturn($this->tiktokFetchResult($beforeWithoutTarget));
        $this->api->shouldReceive('fetchShopeeModels')
            ->twice()
            ->with('54256579274')
            ->andReturn(
                $this->shopeeModelsResult([$this->shopeeModel('model-khakky', 'Khakky', 'INT-54256579274-SAND')]),
                $this->shopeeModelsResult([$this->shopeeModel('model-khakky', 'Khakky', $targetSku)]),
            );
        $this->api->shouldReceive('updateShopeeModelSku')
            ->once()
            ->with('54256579274', 'model-khakky', $targetSku)
            ->andReturn($this->writeResult(true, 'Shopee updated'));

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], collect());

        $row = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $preview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->first();
        $persistedResult = json_decode($row->result, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('updated', $row->status);
        $this->assertSame(3, (int) $row->execution_attempts);
        $this->assertNull($row->execution_owner);
        $this->assertNull($row->execution_lease_until);
        $this->assertTrue($persistedResult['tiktok_delete_attempted']);
        $this->assertSame($persistedDelete, $persistedResult['tiktok_delete']);
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        Http::assertNothingSent();
    }

    public function test_execution_lease_is_renewed_before_every_marketplace_call_and_released_after_outcome(): void
    {
        $this->seedCandidate();
        $groups = $this->mappingGroups();
        $preview = $this->service()->createPreview($groups);
        $before = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand', 'INT-54256579274-SAND', 'Sand'),
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);
        $after = $this->tiktokProduct([
            $this->tiktokSku('tt-sku-sand-survivor', 'INT-SURVIVOR-model-khakky', 'Survivor'),
        ]);
        $targetSku = 'INT-54256579274-KHAKKY';
        $observedOwner = null;
        $assertRenewedThenExpire = function () use ($preview, &$observedOwner): void {
            $row = DB::table('tiktok_reconciliation_run_items')
                ->where('run_id', $preview['run_id'])
                ->where('action_type', 'shopee_sku_tiktok_delete')
                ->first();
            $this->assertNotNull($row->execution_owner);
            $this->assertTrue(\Illuminate\Support\Carbon::parse($row->execution_lease_until)->isFuture());
            $observedOwner ??= $row->execution_owner;
            $this->assertSame($observedOwner, $row->execution_owner);
            DB::table('tiktok_reconciliation_run_items')->where('id', $row->id)->update([
                'execution_lease_until' => now()->subMinute(),
            ]);
        };
        $tiktokFetch = 0;
        $this->api->shouldReceive('fetchTiktokProduct')
            ->twice()
            ->with('tt-1')
            ->andReturnUsing(function () use (&$tiktokFetch, $assertRenewedThenExpire, $before, $after): array {
                $assertRenewedThenExpire();

                return ++$tiktokFetch === 1
                    ? $this->tiktokFetchResult($before)
                    : $this->tiktokFetchResult($after);
            });
        $shopeeFetch = 0;
        $this->api->shouldReceive('fetchShopeeModels')
            ->twice()
            ->with('54256579274')
            ->andReturnUsing(function () use (&$shopeeFetch, $assertRenewedThenExpire, $targetSku): array {
                $assertRenewedThenExpire();

                return $this->shopeeModelsResult([
                    $this->shopeeModel(
                        'model-khakky',
                        'Khakky',
                        ++$shopeeFetch === 1 ? 'INT-54256579274-SAND' : $targetSku,
                    ),
                ]);
            });
        $this->api->shouldReceive('partialEditTiktokProduct')
            ->once()
            ->with('tt-1', Mockery::type('array'))
            ->andReturnUsing(function () use ($assertRenewedThenExpire): array {
                $assertRenewedThenExpire();

                return $this->writeResult(true, 'TikTok updated');
            });
        $this->api->shouldReceive('updateShopeeModelSku')
            ->once()
            ->with('54256579274', 'model-khakky', $targetSku)
            ->andReturnUsing(function () use ($assertRenewedThenExpire): array {
                $assertRenewedThenExpire();

                return $this->writeResult(true, 'Shopee updated');
            });

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $row = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $preview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->first();
        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, (int) $row->execution_attempts);
        $this->assertNull($row->execution_owner);
        $this->assertNull($row->execution_lease_until);
        Http::assertNothingSent();
    }

    public function test_product_exception_after_delete_intent_is_sanitized_and_does_not_cancel_later_products(): void
    {
        $this->seedCandidate(
            itemId: '100',
            modelId: 'model-a',
            productId: 'tt-a',
            tiktokSkuId: 'tt-sku-a',
            oldSku: 'INT-100-OLD',
            variantName: 'New',
            tiktokVariantName: 'Old',
        );
        $this->seedCandidate(
            itemId: '900',
            modelId: 'model-z',
            productId: 'tt-z',
            tiktokSkuId: 'tt-sku-z',
            oldSku: 'INT-900-OLD',
            variantName: 'New',
            tiktokVariantName: 'Old',
        );
        $groups = collect([
            $this->group('100', 'model-a', 'tt-a', 'tt-sku-a'),
            $this->group('900', 'model-z', 'tt-z', 'tt-sku-z'),
        ]);
        $preview = $this->service()->createPreview($groups);
        $tiktokBeforeA = ['id' => 'tt-a', 'title' => 'A', 'skus' => [
            $this->tiktokSku('tt-sku-a', 'INT-100-OLD', 'Old'),
            $this->tiktokSku('tt-sku-a-survivor', 'INT-A-SURVIVOR', 'Survivor'),
        ]];
        $tiktokBeforeZ = ['id' => 'tt-z', 'title' => 'Z', 'skus' => [
            $this->tiktokSku('tt-sku-z', 'INT-900-OLD', 'Old'),
            $this->tiktokSku('tt-sku-z-survivor', 'INT-Z-SURVIVOR', 'Survivor'),
        ]];
        $tiktokAfterZ = ['id' => 'tt-z', 'title' => 'Z', 'skus' => [
            $this->tiktokSku('tt-sku-z-survivor', 'INT-Z-SURVIVOR', 'Survivor'),
        ]];

        $tiktokFetchA = 0;
        $this->api->shouldReceive('fetchTiktokProduct')->twice()->with('tt-a')
            ->andReturnUsing(function () use (&$tiktokFetchA, $tiktokBeforeA): array {
                if (++$tiktokFetchA === 1) {
                    return $this->tiktokFetchResult($tiktokBeforeA);
                }

                throw new \RuntimeException('access_token=should-never-be-persisted');
            });
        $this->api->shouldReceive('fetchTiktokProduct')->twice()->with('tt-z')
            ->andReturn($this->tiktokFetchResult($tiktokBeforeZ), $this->tiktokFetchResult($tiktokAfterZ));
        $this->api->shouldReceive('fetchShopeeModels')->once()->with('100')
            ->andReturn($this->shopeeModelsResult([$this->shopeeModel('model-a', 'New', 'INT-100-OLD')]));
        $this->api->shouldReceive('fetchShopeeModels')->twice()->with('900')
            ->andReturn(
                $this->shopeeModelsResult([$this->shopeeModel('model-z', 'New', 'INT-900-OLD')]),
                $this->shopeeModelsResult([$this->shopeeModel('model-z', 'New', 'INT-900-NEW')]),
            );
        $this->api->shouldReceive('partialEditTiktokProduct')->once()->with('tt-a', Mockery::type('array'))
            ->andReturn($this->writeResult(true, 'TikTok A accepted'));
        $this->api->shouldReceive('partialEditTiktokProduct')->once()->with('tt-z', Mockery::type('array'))
            ->andReturn($this->writeResult(true, 'TikTok Z updated'));
        $this->api->shouldReceive('updateShopeeModelSku')->once()->with('900', 'model-z', 'INT-900-NEW')
            ->andReturn($this->writeResult(true, 'Shopee Z updated'));

        $result = $this->service()->submit($preview['run_id'], $preview['revision'], $groups);

        $rows = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $preview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->orderBy('item_key')
            ->get();
        $firstResult = json_decode($rows[0]->result, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('partial', $result['status']);
        $this->assertSame('partial', $rows[0]->status);
        $this->assertSame('product_execution_exception', $rows[0]->block_reason);
        $this->assertTrue($firstResult['tiktok_delete_attempted']);
        $this->assertSame('TikTok A accepted', $firstResult['tiktok_delete']['message']);
        $this->assertStringNotContainsString('should-never-be-persisted', $rows[0]->result);
        $this->assertSame('updated', $rows[1]->status);
        $this->assertNull($rows[0]->execution_owner);
        $this->assertNull($rows[1]->execution_owner);
        Http::assertNothingSent();
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
            'tiktok_seller_sku' => $tiktokSellerSku ?? $oldSku,
            'is_hidden_from_mapping' => false,
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
            'stock_qty' => 7,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mappingGroups(): Collection
    {
        return collect([$this->group('54256579274', 'model-khakky', 'tt-1', 'tt-sku-sand')]);
    }

    private function seedTwoCandidatesOnOneProduct(): void
    {
        $this->seedCandidate(
            modelId: 'model-a',
            tiktokSkuId: 'tt-sku-soft',
            oldSku: 'INT-54256579274-SOFT',
            variantName: 'Soft Dusty',
            tiktokVariantName: 'Soft',
            addSurvivor: false,
        );
        $this->seedCandidate(
            modelId: 'model-b',
            tiktokSkuId: 'tt-sku-sand',
            oldSku: 'INT-54256579274-SAND',
            variantName: 'Khakky',
            tiktokVariantName: 'Sand',
            addSurvivor: false,
        );
        $this->insertTiktokSku('tt-1', 'tt-sku-survivor', 'INT-SURVIVOR', 'Survivor');
    }

    private function twoCandidateGroups(): Collection
    {
        return collect([
            $this->group('54256579274', 'model-a', 'tt-1', 'tt-sku-soft'),
            $this->group('54256579274', 'model-b', 'tt-1', 'tt-sku-sand'),
        ]);
    }

    private function tiktokProduct(array $skus): array
    {
        return [
            'id' => 'tt-1',
            'title' => 'Authoritative product',
            'skus' => $skus,
        ];
    }

    private function tiktokSku(string $id, string $sellerSku, string $name): array
    {
        return [
            'id' => $id,
            'seller_sku' => $sellerSku,
            'price' => [
                'currency' => 'IDR',
                'sale_price' => '12500.00',
                'tax_exclusive_price' => '12500.00',
                'amount' => '12500.00',
            ],
            'inventory' => [[
                'warehouse_id' => 'warehouse-1',
                'quantity' => '7',
            ]],
            'sales_attributes' => [[
                'id' => 'attribute-1',
                'name' => 'Variation',
                'value_id' => $id.'-value',
                'value_name' => $name,
            ]],
        ];
    }

    private function tiktokFetchResult(array $product): array
    {
        return [
            'ok' => true,
            'message' => 'TikTok fetched',
            'data' => ['product_id' => 'tt-1', 'product' => $product],
            'request' => ['method' => 'GET', 'path' => '/product/tt-1', 'body' => null],
            'response' => ['code' => 0],
        ];
    }

    private function shopeeModelsResult(array $models): array
    {
        return [
            'ok' => true,
            'message' => 'Shopee fetched',
            'data' => ['item_id' => '54256579274', 'models' => $models],
            'request' => ['method' => 'GET', 'path' => '/api/v2/product/get_model_list', 'body' => ['item_id' => 54256579274]],
            'response' => ['error' => ''],
        ];
    }

    private function shopeeModel(string $modelId, string $name, string $sku): array
    {
        return [
            'model_id' => $modelId,
            'model_name' => $name,
            'model_sku' => $sku,
            'stock_info_v2' => ['seller_stock' => [['stock' => 7]]],
        ];
    }

    private function writeResult(bool $ok, string $message): array
    {
        return [
            'ok' => $ok,
            'message' => $message,
            'data' => [],
            'request' => ['method' => 'POST', 'path' => '/write', 'body' => []],
            'response' => ['code' => $ok ? 0 : 1, 'message' => $message],
        ];
    }

    private function catalogFailureResult(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'data' => ['product' => null, 'models' => []],
            'request' => ['method' => 'GET', 'path' => '/read', 'body' => null],
            'response' => ['code' => 503, 'message' => $message],
        ];
    }

    private function repurposeStockMapping(int $stockMasterId): void
    {
        DB::table('stock_master')->where('id', $stockMasterId)->update([
            'shopee_product_id' => 'other-item',
            'shopee_sku' => 'other-model',
            'shopee_seller_sku' => 'OTHER-SHOPEE-SKU',
            'tiktok_product_id' => 'tt-other',
            'tiktok_sku' => 'tt-other-sku',
            'tiktok_seller_sku' => 'OTHER-TIKTOK-SKU',
        ]);
        DB::table('sku_mappings')->where('stock_master_id', $stockMasterId)->update([
            'shopee_item_id' => 'other-item',
            'shopee_model_id' => 'other-model',
            'tiktok_product_id' => 'tt-other',
            'tiktok_sku_id' => 'tt-other-sku',
            'tiktok_sku_name' => 'Other',
            'seller_sku' => 'OTHER-SHOPEE-SKU',
            'tiktok_image_url' => 'https://example.test/other.jpg',
        ]);
    }

    private function assertRepurposedStockMappingRemains(int $stockMasterId): void
    {
        $this->assertDatabaseHas('stock_master', [
            'id' => $stockMasterId,
            'shopee_product_id' => 'other-item',
            'shopee_sku' => 'other-model',
            'shopee_seller_sku' => 'OTHER-SHOPEE-SKU',
            'tiktok_product_id' => 'tt-other',
            'tiktok_sku' => 'tt-other-sku',
            'tiktok_seller_sku' => 'OTHER-TIKTOK-SKU',
        ]);
        $this->assertDatabaseHas('sku_mappings', [
            'stock_master_id' => $stockMasterId,
            'shopee_item_id' => 'other-item',
            'shopee_model_id' => 'other-model',
            'tiktok_product_id' => 'tt-other',
            'tiktok_sku_id' => 'tt-other-sku',
            'tiktok_sku_name' => 'Other',
            'seller_sku' => 'OTHER-SHOPEE-SKU',
            'tiktok_image_url' => 'https://example.test/other.jpg',
        ]);
    }

    private function service(): ShopeeSkuTiktokVariantCleanupService
    {
        return app(ShopeeSkuTiktokVariantCleanupService::class);
    }

    private function assertOverlappingPriorRunBlocks(
        array $priorTargetIds,
        array $currentTargetIds,
        bool $priorHasUnexpiredLease = false,
    ): void
    {
        $definitions = [
            'tt-sku-a' => ['model-a', 'INT-54256579274-OLD-A', 'Target A', 'Old A'],
            'tt-sku-b' => ['model-b', 'INT-54256579274-OLD-B', 'Target B', 'Old B'],
            'tt-sku-c' => ['model-c', 'INT-54256579274-OLD-C', 'Target C', 'Old C'],
        ];
        foreach ($definitions as $tiktokSkuId => [$modelId, $oldSku, $variantName, $tiktokVariantName]) {
            $this->seedCandidate(
                modelId: $modelId,
                tiktokSkuId: $tiktokSkuId,
                oldSku: $oldSku,
                variantName: $variantName,
                tiktokVariantName: $tiktokVariantName,
                addSurvivor: false,
            );
        }
        $this->insertTiktokSku('tt-1', 'tt-sku-survivor', 'INT-SURVIVOR', 'Survivor');
        $groupsFor = fn (array $targetIds): Collection => collect(array_map(
            fn (string $targetId): array => $this->group(
                '54256579274',
                $definitions[$targetId][0],
                'tt-1',
                $targetId,
            ),
            $targetIds,
        ));
        $priorPreview = $this->service()->createPreview($groupsFor($priorTargetIds));
        $currentPreview = $this->service()->createPreview($groupsFor($currentTargetIds));
        DB::table('tiktok_reconciliation_runs')->where('id', $priorPreview['run_id'])->update([
            'status' => 'partial',
            'submitted_at' => now()->subMinutes(20),
        ]);
        $priorValues = [
            'status' => 'submitted_unverified',
            'block_reason' => 'tiktok_delete_unverified',
            'result' => json_encode([
                'tiktok_delete_attempted' => true,
                'expected_non_target_sku_ids' => ['tt-sku-survivor'],
                'tiktok_delete' => $this->writeResult(true, 'Possibly dispatched in prior run'),
            ], JSON_THROW_ON_ERROR),
        ];
        if ($priorHasUnexpiredLease) {
            $priorValues['execution_owner'] = (string) \Illuminate\Support\Str::uuid();
            $priorValues['execution_lease_until'] = now()->addMinutes(10);
            $priorValues['execution_attempts'] = 1;
        }
        DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $priorPreview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->update($priorValues);
        DB::table('tiktok_reconciliation_runs')->where('id', $currentPreview['run_id'])->update([
            'status' => 'claimed',
            'submitted_at' => now(),
        ]);

        $result = $this->service()->submit($currentPreview['run_id'], $currentPreview['revision'], collect());

        $currentRows = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $currentPreview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->orderBy('id')
            ->get();
        $this->assertSame('failed', $result['status']);
        $this->assertNotEmpty($currentRows);
        $this->assertSame(
            ['overlapping_tiktok_delete_history'],
            $currentRows->pluck('block_reason')->unique()->values()->all(),
        );
        $this->assertSame([0], $currentRows->pluck('execution_attempts')->map(fn (mixed $value): int => (int) $value)->unique()->values()->all());
        $this->assertSame([null], $currentRows->pluck('execution_owner')->unique()->values()->all());
        $this->assertSame(
            ['retry_original_run_or_manual_reconciliation'],
            $currentRows
                ->map(fn (object $row): mixed => data_get(json_decode($row->result, true), 'resolution'))
                ->unique()
                ->values()
                ->all(),
        );
        $this->api->shouldNotHaveReceived('fetchTiktokProduct');
        $this->api->shouldNotHaveReceived('fetchShopeeModels');
        $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
        $this->api->shouldNotHaveReceived('updateShopeeModelSku');
        Http::assertNothingSent();
    }

    private function persistRunDeleteEvidence(string $runId, array $expectedNonTargetIds): void
    {
        DB::table('tiktok_reconciliation_runs')->where('id', $runId)->update([
            'status' => 'partial',
            'submitted_at' => now()->subMinutes(20),
        ]);
        DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $runId)
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->update([
                'status' => 'submitted_unverified',
                'block_reason' => 'tiktok_delete_unverified',
                'result' => json_encode([
                    'tiktok_delete_attempted' => true,
                    'expected_non_target_sku_ids' => $expectedNonTargetIds,
                    'tiktok_delete' => $this->writeResult(true, 'Accepted in original run'),
                ], JSON_THROW_ON_ERROR),
            ]);
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
