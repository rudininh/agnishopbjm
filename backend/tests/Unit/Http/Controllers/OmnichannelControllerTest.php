<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\OmnichannelController;
use Illuminate\Support\Collection;
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
            ],
        ]));

        $this->assertSame(['SH-RED'], $groups->first()['variants']->pluck('seller_sku')->all());
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

    private function tiktokMajorityPrice(array $skus): array
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('tiktokMajorityPrice');
        $method->setAccessible(true);

        return $method->invoke($controller, $skus);
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
}
