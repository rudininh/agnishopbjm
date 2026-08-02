<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\OmnichannelController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

class OmnichannelControllerTest extends TestCase
{
    public function test_tiktok_generated_payload_weight_is_normalized_from_kilogram_to_gram(): void
    {
        $payload = $this->normalizePackageWeight([
            'package_weight' => [
                'unit' => 'KILOGRAM',
                'value' => '0.2',
            ],
        ]);

        $this->assertSame('GRAM', $payload['package_weight']['unit']);
        $this->assertSame('200', $payload['package_weight']['value']);
    }

    public function test_tiktok_generated_payload_weight_defaults_to_200_gram(): void
    {
        $payload = $this->normalizePackageWeight([]);

        $this->assertSame('GRAM', $payload['package_weight']['unit']);
        $this->assertSame('200', $payload['package_weight']['value']);
    }

    public function test_tiktok_generated_payload_weight_is_normalized_inside_nested_payload(): void
    {
        $payload = $this->normalizePackageWeight([
            'data' => [
                'product' => [
                    'package_weight' => [
                        'unit' => 'KILOGRAM',
                        'value' => '0',
                    ],
                ],
            ],
        ]);

        $this->assertSame('GRAM', $payload['data']['product']['package_weight']['unit']);
        $this->assertSame('200', $payload['data']['product']['package_weight']['value']);
        $this->assertSame('GRAM', $payload['package_weight']['unit']);
        $this->assertSame('200', $payload['package_weight']['value']);
    }

    public function test_tiktok_generated_payload_sku_weights_are_normalized_to_gram(): void
    {
        $payload = $this->normalizePackageWeight([
            'skus' => [
                [
                    'seller_sku' => 'SKU-1',
                    'sku_weight' => [
                        'unit' => 'KILOGRAM',
                        'value' => '0.07',
                    ],
                ],
                [
                    'seller_sku' => 'SKU-2',
                    'sku_weight' => [
                        'unit' => 'KG',
                        'value' => '0,2',
                    ],
                ],
            ],
        ]);

        $this->assertSame('GRAM', $payload['skus'][0]['sku_weight']['unit']);
        $this->assertSame('70', $payload['skus'][0]['sku_weight']['value']);
        $this->assertSame('GRAM', $payload['skus'][1]['sku_weight']['unit']);
        $this->assertSame('200', $payload['skus'][1]['sku_weight']['value']);
    }

    public function test_tiktok_generated_payload_missing_sku_weight_uses_product_weight(): void
    {
        $payload = $this->normalizePackageWeight([
            'package_weight' => [
                'unit' => 'KILOGRAM',
                'value' => '0.07',
            ],
            'skus' => [
                ['seller_sku' => 'NEW-SKU'],
            ],
        ]);

        $this->assertSame('GRAM', $payload['skus'][0]['sku_weight']['unit']);
        $this->assertSame('70', $payload['skus'][0]['sku_weight']['value']);
    }

    public function test_tiktok_generated_payload_dimensions_are_normalized_to_non_zero_centimeter(): void
    {
        $payload = $this->normalizeDimensions([
            'package_dimensions' => [
                'unit' => 'CENTIMETER',
                'height' => '0',
                'length' => '',
                'width' => '0',
            ],
            'skus' => [
                [
                    'sku_dimensions' => [
                        'unit' => 'CENTIMETER',
                        'height' => '0',
                        'length' => '0',
                        'width' => '0',
                    ],
                ],
            ],
        ]);

        $this->assertSame('CENTIMETER', $payload['package_dimensions']['unit']);
        $this->assertSame('1', $payload['package_dimensions']['height']);
        $this->assertSame('1', $payload['package_dimensions']['length']);
        $this->assertSame('1', $payload['package_dimensions']['width']);
        $this->assertSame('1', $payload['skus'][0]['sku_dimensions']['height']);
        $this->assertSame('1', $payload['skus'][0]['sku_dimensions']['length']);
        $this->assertSame('1', $payload['skus'][0]['sku_dimensions']['width']);
    }

    public function test_tiktok_generated_payload_missing_sku_dimensions_uses_product_dimensions(): void
    {
        $payload = $this->normalizeDimensions([
            'package_dimensions' => [
                'unit' => 'CENTIMETER',
                'height' => '2',
                'length' => '3',
                'width' => '4',
            ],
            'skus' => [
                ['seller_sku' => 'NEW-SKU'],
            ],
        ]);

        $this->assertSame('CENTIMETER', $payload['skus'][0]['sku_dimensions']['unit']);
        $this->assertSame('2', $payload['skus'][0]['sku_dimensions']['height']);
        $this->assertSame('3', $payload['skus'][0]['sku_dimensions']['length']);
        $this->assertSame('4', $payload['skus'][0]['sku_dimensions']['width']);
    }

    public function test_shopee_bulk_candidates_include_only_blank_skus_and_use_internal_template(): void
    {
        $controller = new OmnichannelController();
        $this->assertTrue(method_exists($controller, 'shopeeMissingSkuBulkCandidates'));

        $candidates = $this->shopeeMissingSkuBulkCandidates(collect([
            (object) ['item_id' => '100', 'model_id' => '1', 'name' => 'Merah / L', 'model_sku' => ''],
            (object) ['item_id' => '100', 'model_id' => '2', 'name' => 'Biru', 'model_sku' => 'SKU-SUDAH-ADA'],
            (object) ['item_id' => '101', 'model_id' => '3', 'name' => 'Hitam', 'model_sku' => '   '],
        ]));

        $this->assertSame([
            ['item_id' => '100', 'model_id' => '1', 'model_name' => 'Merah / L', 'seller_sku' => 'INT-100-MERAH-L'],
            ['item_id' => '101', 'model_id' => '3', 'model_name' => 'Hitam', 'seller_sku' => 'INT-101-HITAM'],
        ], $candidates->all());
    }

    public function test_shopee_bulk_empty_sku_route_is_registered(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => in_array('POST', $route->methods(), true)
                && $route->uri() === 'api/sku-mapping/bulk-update-empty-shopee-variant-skus');

        $this->assertNotNull($route);
        $this->assertSame('App\\Http\\Controllers\\OmnichannelController@bulkUpdateShopeeEmptyVariantSkus', $route->getActionName());
    }
    public function test_tiktok_bulk_candidates_keep_only_shopee_skus_missing_from_tiktok(): void
    {
        $groups = $this->tiktokBulkMissingVariantCandidates(collect([
            (object) [
                'tiktok_product_id' => '900',
                'product_name' => 'Produk A',
                'shopee_item_id' => '100',
                'shopee_model_id' => '1',
                'shopee_model_sku' => 'SH-RED',
                'shopee_variant_name' => 'Merah',
                'shopee_image_url' => 'https://cdn.example/red.jpg',
                'tiktok_seller_skus' => ['SH-BLUE'],
                'tiktok_skus' => [['seller_sku' => 'SH-BLUE', 'sku_id' => 'TT-2']],
            ],
            (object) [
                'tiktok_product_id' => '900',
                'product_name' => 'Produk A',
                'shopee_item_id' => '100',
                'shopee_model_id' => '2',
                'shopee_model_sku' => 'sh-blue',
                'shopee_variant_name' => 'Biru',
                'shopee_image_url' => 'https://cdn.example/blue.jpg',
                'tiktok_seller_skus' => ['SH-BLUE'],
                'tiktok_skus' => [['seller_sku' => 'SH-BLUE', 'sku_id' => 'TT-2']],
            ],
        ]));

        $this->assertSame(['SH-RED'], $groups->first()['variants']->pluck('seller_sku')->all());
        $this->assertSame(['SH-BLUE'], $groups->first()['mapping_only_variants']->pluck('seller_sku')->all());
        $this->assertSame('TT-2', $groups->first()['mapping_only_variants']->first()['tiktok_sku_id']);
        $this->assertSame('SKU sudah ada di TikTok; mapping belum tersambung.', $groups->first()['mapping_only_variants']->first()['reason']);
    }

    public function test_linked_tiktok_seller_sku_lookup_ignores_variant_name_mismatch(): void
    {
        $match = $this->linkedTiktokSellerSkuMatch([
            'product_groups' => [
                '900' => [
                    'rows_by_seller_sku' => [
                        'sh blue' => (object) ['product_id' => '900', 'sku_id' => 'TT-2', 'sku_name' => 'Nama TikTok Lama', 'seller_sku' => 'SH-BLUE'],
                    ],
                ],
            ],
        ], '900', 'sh-blue');

        $this->assertSame('TT-2', $match->sku_id);
        $this->assertSame('SH-BLUE', $match->seller_sku);
    }

    public function test_tiktok_bulk_candidate_row_uses_suggested_tiktok_product_from_sku_mapping(): void
    {
        $row = $this->tiktokBulkCandidateRowFromSkuMapping([
            'status' => 'tiktok_missing',
            'product_name' => 'Produk Shopee',
            'shopee' => ['item_id' => '100', 'model_id' => '1', 'seller_sku' => 'SH-RED', 'variant_name' => 'Merah', 'image_url' => '/cached/red.jpg'],
            'tiktok' => ['product_id' => '900', 'product_name' => 'Produk TikTok', 'source' => 'suggested_product'],
        ]);

        $this->assertSame('900', $row->tiktok_product_id);
        $this->assertSame('100', $row->shopee_item_id);
        $this->assertSame('SH-RED', $row->shopee_model_sku);
        $this->assertSame('/cached/red.jpg', $row->shopee_image_url);
    }

    public function test_tiktok_majority_price_returns_the_most_frequent_price(): void
    {
        $result = $this->tiktokMajorityPrice([
            ['sale_price' => '50000'],
            ['sale_price' => '50000'],
            ['sale_price' => '19000'],
        ]);

        $this->assertSame(['price' => 50000, 'reason' => null], $result);
    }

    public function test_tiktok_majority_price_rejects_a_tie(): void
    {
        $result = $this->tiktokMajorityPrice([
            ['sale_price' => '50000'],
            ['sale_price' => '19000'],
        ]);

        $this->assertSame(['price' => null, 'reason' => 'Harga TikTok mayoritas seri.'], $result);
    }
    public function test_bulk_tiktok_image_refresh_replaces_the_identity_cache_file(): void
    {
        Http::fake([
            'https://cdn.example/old.jpg' => Http::response('old-image', 200, ['Content-Type' => 'image/jpeg']),
            'https://cdn.example/new.png' => Http::response('new-image', 200, ['Content-Type' => 'image/png']),
        ]);

        $oldCachedUrl = $this->refreshMarketplaceImageUrl('https://cdn.example/old.jpg', 'shopee', '100', '7');
        $newCachedUrl = $this->refreshMarketplaceImageUrl('https://cdn.example/new.png', 'shopee', '100', '7');
        $oldPath = $this->cachedImagePath($oldCachedUrl);
        $newPath = $this->cachedImagePath($newCachedUrl);

        try {
            $this->assertNotSame($oldCachedUrl, $newCachedUrl);
            $this->assertFileDoesNotExist($oldPath);
            $this->assertSame('new-image', file_get_contents($newPath));
            Http::assertSentCount(2);
        } finally {
            @unlink($oldPath);
            @unlink($newPath);
        }
    }
    public function test_bulk_tiktok_missing_variant_routes_are_registered(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());
        $preview = $routes->first(fn ($route) => in_array('GET', $route->methods(), true)
            && $route->uri() === 'api/tiktok/bulk-missing-variants');
        $submit = $routes->first(fn ($route) => in_array('POST', $route->methods(), true)
            && $route->uri() === 'api/tiktok/bulk-missing-variants/submit');

        $this->assertNotNull($preview);
        $this->assertSame('App\\Http\\Controllers\\OmnichannelController@bulkTiktokMissingVariantsPreview', $preview->getActionName());
        $this->assertNotNull($submit);
        $this->assertSame('App\\Http\\Controllers\\OmnichannelController@bulkSubmitTiktokMissingVariants', $submit->getActionName());
    }

    public function test_bulk_tiktok_missing_variant_manual_price_must_be_positive(): void
    {
        $this->postJson('/api/tiktok/bulk-missing-variants/submit', [
            'scope' => 'selected',
            'product_ids' => ['900'],
            'price_mode' => 'manual',
            'manual_price' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('manual_price');
    }

    public function test_existing_tiktok_product_requires_readable_detail_before_mutation(): void
    {
        $this->assertTrue($this->hasControllerMethod('canSubmitTiktokProductMutation'));

        $canSubmit = $this->invokeControllerMethod('canSubmitTiktokProductMutation', [
            ['product_id' => '123'],
            null,
        ]);

        $this->assertFalse($canSubmit);
    }

    public function test_bulk_tiktok_audit_payload_redacts_signing_credentials(): void
    {
        $this->assertTrue($this->hasControllerMethod('redactBulkTiktokAuditPayload'));

        $payload = $this->invokeControllerMethod('redactBulkTiktokAuditPayload', [[
            'request' => [
                'query' => [
                    'access_token' => 'secret',
                    'sign' => 'signature',
                    'shop_cipher' => 'cipher',
                ],
            ],
            'response' => ['code' => 400, 'message' => 'invalid'],
        ]]);

        $this->assertArrayNotHasKey('access_token', $payload['request']['query']);
        $this->assertArrayNotHasKey('sign', $payload['request']['query']);
        $this->assertSame('invalid', $payload['response']['message']);
    }

    public function test_bulk_tiktok_outcome_keeps_sku_and_marks_unverified(): void
    {
        $this->assertTrue($this->hasControllerMethod('bulkTiktokVariantOutcome'));

        $outcome = $this->invokeControllerMethod('bulkTiktokVariantOutcome', [[
            'seller_sku' => 'SKU-ROSE',
        ], 'submitted_unverified', 48000, 'SKU belum muncul pada katalog TikTok terbaru.']);

        $this->assertSame([
            'seller_sku' => 'SKU-ROSE',
            'price' => 48000,
            'status' => 'submitted_unverified',
            'reason' => 'SKU belum muncul pada katalog TikTok terbaru.',
        ], $outcome);
    }

    public function test_bulk_tiktok_verification_requires_fresh_catalogue_sku(): void
    {
        $this->assertTrue($this->hasControllerMethod('bulkTiktokVerificationOutcome'));

        $missingSku = $this->invokeControllerMethod('bulkTiktokVerificationOutcome', [[
            'status' => 'ok',
            'message' => 'Produk TikTok dipilih berhasil disinkronkan: 5 varian aktif.',
        ], false]);
        $syncFailure = $this->invokeControllerMethod('bulkTiktokVerificationOutcome', [[
            'status' => 'error',
            'message' => 'Detail produk TikTok tidak berhasil diambil.',
        ], false]);
        $verified = $this->invokeControllerMethod('bulkTiktokVerificationOutcome', [[
            'status' => 'ok',
        ], true]);

        $this->assertSame([
            'status' => 'submitted_unverified',
            'reason' => 'SKU belum muncul pada katalog TikTok terbaru.',
        ], $missingSku);
        $this->assertSame([
            'status' => 'submitted_unverified',
            'reason' => 'Detail produk TikTok tidak berhasil diambil.',
        ], $syncFailure);
        $this->assertSame(['status' => 'updated', 'reason' => null], $verified);
    }

    public function test_bulk_tiktok_variant_image_upload_uses_attribute_image(): void
    {
        $this->assertTrue($this->hasControllerMethod('bulkTiktokVariantImageUseCase'));

        $this->assertSame('ATTRIBUTE_IMAGE', $this->invokeControllerMethod('bulkTiktokVariantImageUseCase', []));
    }

    public function test_tiktok_main_images_for_mutation_use_structured_tiktok_uris_only(): void
    {
        $this->assertTrue($this->hasControllerMethod('normalizeTiktokMainImagesForMutation'));

        $images = $this->invokeControllerMethod('normalizeTiktokMainImagesForMutation', [[
            'main_images' => [
                ['uri' => 'tos-alisg-i-aphluv4xwc-sg/existing-image'],
                'https://p16-oec-sg.ibyteimg.com/tos-alisg-i-aphluv4xwc-sg/cdn-image~tplv-origin.jpeg?x=1',
                '/cached-images/marketplace-images/shopee/invalid.jpg',
                'tos-alisg-i-aphluv4xwc-sg/existing-image',
            ],
        ]]);

        $this->assertSame([
            ['uri' => 'tos-alisg-i-aphluv4xwc-sg/existing-image'],
            ['uri' => 'tos-alisg-i-aphluv4xwc-sg/cdn-image'],
        ], $images);
    }

    public function test_tiktok_variant_mutation_sku_rows_use_structured_prices(): void
    {
        $this->assertTrue($this->hasControllerMethod('buildTiktokVariantMutationSkuRows'));

        $rows = $this->invokeControllerMethod('buildTiktokVariantMutationSkuRows', [
            [
                'skus' => [[
                    'id' => 'existing-1',
                    'seller_sku' => 'EXISTING',
                    'sku_name' => 'Hitam',
                    'price' => '48000',
                    'stock' => 3,
                ]],
            ],
            [
                'source' => ['price' => 48000],
                'target' => [
                    'variant_name' => 'Rose Gold',
                    'seller_sku' => 'NEW',
                    'stock_qty' => 0,
                ],
            ],
            (object) ['variant_name' => 'Fallback', 'internal_sku' => 'FALLBACK'],
            'tos-alisg-i-aphluv4xwc-sg/new-image',
        ]);

        $expectedPrice = [
            'currency' => 'IDR',
            'sale_price' => '48000',
            'tax_exclusive_price' => '48000',
            'amount' => '48000',
        ];

        $this->assertSame($expectedPrice, $rows[0]['price']);
        $this->assertSame($expectedPrice, $rows[1]['price']);
    }

    public function test_tiktok_mutation_description_preserves_existing_detail_only(): void
    {
        $this->assertTrue($this->hasControllerMethod('tiktokMutationDescription'));

        $description = $this->invokeControllerMethod('tiktokMutationDescription', [[
            'description' => '  Deskripsi TikTok asli.  ',
        ]]);
        $blank = $this->invokeControllerMethod('tiktokMutationDescription', [[
            'description' => '   ',
        ]]);

        $this->assertSame('Deskripsi TikTok asli.', $description);
        $this->assertSame('', $blank);
    }

    public function test_bulk_tiktok_action_is_persisted_with_redacted_payload(): void
    {
        Schema::dropIfExists('sku_variant_actions');
        Schema::dropIfExists('stock_master');

        try {
            Schema::create('stock_master', function (Blueprint $table): void {
                $table->id();
                $table->string('internal_sku')->unique();
                $table->string('shopee_product_id')->nullable();
                $table->string('shopee_sku')->nullable();
            });
            Schema::create('sku_variant_actions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('stock_master_id');
                $table->string('target_channel');
                $table->string('source_channel')->nullable();
                $table->string('action_type');
                $table->json('payload')->nullable();
                $table->string('status');
                $table->timestamps();
                $table->unique(['stock_master_id', 'target_channel', 'action_type']);
            });
            DB::table('stock_master')->insert([
                'internal_sku' => 'SKU-ROSE',
                'shopee_product_id' => '55307930257',
                'shopee_sku' => '267828680247',
            ]);

            $this->assertTrue($this->hasControllerMethod('recordBulkTiktokVariantAction'));
            $this->invokeControllerMethod('recordBulkTiktokVariantAction', [[
                'shopee_item_id' => '55307930257',
                'shopee_model_id' => '267828680247',
                'seller_sku' => 'SKU-ROSE',
            ], 'failed', 48000, 'TikTok menolak varian.', [
                'mutation' => [
                    'request' => ['query' => ['access_token' => 'secret', 'sign' => 'signature']],
                ],
            ]]);

            $action = DB::table('sku_variant_actions')->first();
            $this->assertSame('failed', $action->status);
            $this->assertSame('bulk_create_variant', $action->action_type);
            $this->assertStringNotContainsString('secret', $action->payload);
            $this->assertStringNotContainsString('signature', $action->payload);
        } finally {
            Schema::dropIfExists('sku_variant_actions');
            Schema::dropIfExists('stock_master');
        }
    }

    private function shopeeMissingSkuBulkCandidates(Collection $models): Collection
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('shopeeMissingSkuBulkCandidates');
        $method->setAccessible(true);

        return $method->invoke($controller, $models);
    }
    private function tiktokBulkMissingVariantCandidates(Collection $rows): Collection
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('tiktokBulkMissingVariantCandidates');
        $method->setAccessible(true);

        return $method->invoke($controller, $rows);
    }

    private function tiktokBulkCandidateRowFromSkuMapping(array $item): ?object
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('tiktokBulkCandidateRowFromSkuMapping');
        $method->setAccessible(true);

        return $method->invoke($controller, $item);
    }
    private function linkedTiktokSellerSkuMatch(array $lookup, string $productId, string $sellerSku): ?object
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('linkedTiktokSellerSkuMatch');
        $method->setAccessible(true);

        return $method->invoke($controller, $lookup, $productId, $sellerSku);
    }
    private function tiktokMajorityPrice(array $skus): array
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('tiktokMajorityPrice');
        $method->setAccessible(true);

        return $method->invoke($controller, $skus);
    }
    private function refreshMarketplaceImageUrl(string $sourceUrl, string $channel, string $scope, string $variant): ?string
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('refreshMarketplaceImageUrl');
        $method->setAccessible(true);

        return $method->invoke($controller, $sourceUrl, $channel, $scope, $variant);
    }

    private function cachedImagePath(?string $cachedUrl): string
    {
        return storage_path('app/public/'.ltrim(substr((string) $cachedUrl, strlen('/cached-images/')), '/'));
    }
    private function normalizePackageWeight(array $payload): array
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('normalizeTiktokGeneratedPayloadWeights');
        $method->setAccessible(true);

        return $method->invoke($controller, $payload);
    }

    private function normalizeDimensions(array $payload): array
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('normalizeTiktokGeneratedPayloadDimensions');
        $method->setAccessible(true);

        return $method->invoke($controller, $payload);
    }

    private function hasControllerMethod(string $name): bool
    {
        return method_exists(OmnichannelController::class, $name);
    }

    private function invokeControllerMethod(string $name, array $arguments): mixed
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod($name);
        $method->setAccessible(true);

        return $method->invoke($controller, ...$arguments);
    }
}
