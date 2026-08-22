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

        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', $result['items'][0]['status']);
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

        try {
            $this->service()->submit($preview['run_id'], $preview['revision'], $groups);
            $this->fail('The simulated process interruption should escape the service call.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated process interruption after Shopee dispatch', $exception->getMessage());
        }

        $item = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $preview['run_id'])
            ->where('action_type', 'shopee_sku_tiktok_delete')
            ->first();
        $result = json_decode((string) $item->result, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('partial', $item->status);
        $this->assertTrue($result['tiktok_verified']);
        $this->assertSame(['tt-sku-sand-survivor'], $result['expected_non_target_sku_ids']);

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
