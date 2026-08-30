<?php

namespace Tests\Unit\Services;

use App\Services\TiktokPartialEditSkuPayloadBuilder;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TiktokPartialEditSkuPayloadBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    public function test_delete_sku_ids_preserves_the_full_available_contract_for_every_survivor(): void
    {
        $detail = $this->productDetail();

        $payload = app(TiktokPartialEditSkuPayloadBuilder::class)->deleteSkuIds(
            $detail,
            ['tt-old-red', 'tt-old-blue']
        );

        $this->assertSame('LISTING', $payload['save_mode']);
        $this->assertSame(['tt-green'], array_column($payload['skus'], 'id'));
        $this->assertSame('INT-42-GREEN', $payload['skus'][0]['seller_sku']);
        $this->assertSame('25000', $payload['skus'][0]['price']['sale_price']);
        $this->assertSame(4, $payload['skus'][0]['inventory'][0]['quantity']);
        $this->assertSame($detail['skus'][2]['sales_attributes'], $payload['skus'][0]['sales_attributes']);
        Http::assertNothingSent();
    }

    public function test_delete_sku_ids_fails_closed_when_a_target_is_absent(): void
    {
        try {
            app(TiktokPartialEditSkuPayloadBuilder::class)->deleteSkuIds(
                $this->productDetail(),
                ['tt-old-red', 'tt-missing']
            );
            $this->fail('Expected an absent target SKU to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SKU TikTok target tidak ditemukan di detail produk terbaru.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_delete_sku_ids_fails_closed_when_no_sku_would_survive(): void
    {
        try {
            app(TiktokPartialEditSkuPayloadBuilder::class)->deleteSkuIds(
                $this->productDetail(),
                ['tt-old-red', 'tt-old-blue', 'tt-green']
            );
            $this->fail('Expected deletion of every SKU to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Varian TikTok tersisa tidak boleh kosong.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_delete_sku_ids_trims_duplicate_targets_and_removes_each_target_once(): void
    {
        $payload = app(TiktokPartialEditSkuPayloadBuilder::class)->deleteSkuIds(
            $this->productDetail(),
            [' tt-old-red ', 'tt-old-red', 'tt-old-blue', ' tt-old-blue ']
        );

        $this->assertSame(['tt-green'], array_column($payload['skus'], 'id'));
        Http::assertNothingSent();
    }

    /**
     * @dataProvider invalidTargetIdProvider
     */
    public function test_delete_sku_ids_rejects_every_invalid_target_in_a_mixed_request(mixed $invalidTarget): void
    {
        try {
            app(TiktokPartialEditSkuPayloadBuilder::class)->deleteSkuIds(
                $this->productDetail(),
                ['tt-old-red', $invalidTarget]
            );
            $this->fail('Expected every requested target ID to be validated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ID target SKU TikTok tidak valid.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public static function invalidTargetIdProvider(): array
    {
        return [
            'blank string' => ['   '],
            'null' => [null],
            'array' => [['tt-old-blue']],
            'object' => [(object) ['id' => 'tt-old-blue']],
            'boolean' => [true],
            'float' => [123.45],
            'integer' => [123],
        ];
    }

    /**
     * @dataProvider negativeTargetIdProvider
     */
    public function test_delete_sku_ids_rejects_a_negative_target_instead_of_matching_a_positive_id(mixed $negativeTarget): void
    {
        $detail = $this->productDetail();
        $detail['skus'][0]['id'] = '1';
        $detail['skus'][1]['id'] = '2';
        $detail['skus'][2]['id'] = '3';

        try {
            app(TiktokPartialEditSkuPayloadBuilder::class)->deleteSkuIds($detail, [$negativeTarget, '2']);
            $this->fail('Expected a negative target ID to be rejected before matching.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ID target SKU TikTok tidak valid.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public static function negativeTargetIdProvider(): array
    {
        return [
            'negative integer' => [-1],
            'negative numeric string' => ['-1'],
        ];
    }

    public function test_delete_sku_ids_does_not_fold_distinct_id_punctuation_when_matching_targets(): void
    {
        $detail = $this->productDetail();
        $detail['skus'][0]['id'] = 'tt-old_red';
        $detail['skus'][0]['price'] = [
            'currency' => 'IDR',
            'sale_price' => '25000',
            'tax_exclusive_price' => '25000',
            'amount' => '25000',
        ];

        try {
            app(TiktokPartialEditSkuPayloadBuilder::class)->deleteSkuIds($detail, ['tt-old-red']);
            $this->fail('Expected punctuation-distinct SKU IDs not to match.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SKU TikTok target tidak ditemukan di detail produk terbaru.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_delete_sku_ids_does_not_fold_distinct_id_case_when_matching_targets(): void
    {
        $detail = $this->productDetail();
        $detail['skus'][0]['id'] = 'abc';
        $detail['skus'][0]['price'] = [
            'currency' => 'IDR',
            'sale_price' => '25000',
            'tax_exclusive_price' => '25000',
            'amount' => '25000',
        ];

        try {
            app(TiktokPartialEditSkuPayloadBuilder::class)->deleteSkuIds($detail, ['ABC']);
            $this->fail('Expected case-distinct SKU IDs not to match.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SKU TikTok target tidak ditemukan di detail produk terbaru.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_delete_sku_ids_fails_closed_instead_of_silently_omitting_a_source_sku_without_an_id(): void
    {
        $detail = $this->productDetail();
        unset($detail['skus'][2]['id']);

        try {
            app(TiktokPartialEditSkuPayloadBuilder::class)->deleteSkuIds($detail, ['tt-old-red']);
            $this->fail('Expected malformed fresh SKU detail to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Detail SKU TikTok tidak lengkap atau memiliki ID duplikat.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * @dataProvider invalidSourceSkuIdProvider
     */
    public function test_delete_sku_ids_rejects_a_malformed_fresh_source_id_before_casting(mixed $invalidId): void
    {
        $detail = $this->productDetail();
        $detail['skus'][2]['id'] = $invalidId;

        try {
            app(TiktokPartialEditSkuPayloadBuilder::class)
                ->deleteSkuIds($detail, ['tt-old-red', 'tt-old-blue']);
            $this->fail('Expected every fresh source SKU ID to be validated before matching.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Detail SKU TikTok tidak lengkap atau memiliki ID duplikat.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public static function invalidSourceSkuIdProvider(): array
    {
        return [
            'array' => [['tt-green']],
            'object' => [(object) ['id' => 'tt-green']],
            'boolean' => [true],
            'float' => [123.45],
            'positive integer' => [123],
            'negative integer' => [-1],
            'negative numeric string' => ['-1'],
        ];
    }

    /**
     * @dataProvider incompleteSurvivorContractProvider
     */
    public function test_delete_sku_ids_fails_closed_when_a_survivor_contract_is_incomplete(string $missingField): void
    {
        $detail = $this->productDetail();
        unset($detail['skus'][2][$missingField]);

        try {
            app(TiktokPartialEditSkuPayloadBuilder::class)->deleteSkuIds($detail, ['tt-old-red', 'tt-old-blue']);
            $this->fail('Expected an incomplete survivor contract to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Kontrak SKU TikTok tersisa tidak lengkap.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public static function incompleteSurvivorContractProvider(): array
    {
        return [
            'seller SKU' => ['seller_sku'],
            'price' => ['price'],
            'inventory' => ['inventory'],
            'sales attributes' => ['sales_attributes'],
        ];
    }

    public function test_delete_sku_ids_rejects_a_mixed_valid_and_malformed_survivor_inventory_contract(): void
    {
        $detail = $this->productDetail();
        $detail['skus'][2]['inventory'][] = ['warehouse_id' => 'warehouse-2'];

        try {
            app(TiktokPartialEditSkuPayloadBuilder::class)->deleteSkuIds($detail, ['tt-old-red', 'tt-old-blue']);
            $this->fail('Expected every fresh inventory row to be preserved or rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Kontrak SKU TikTok tersisa tidak lengkap.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_delete_sku_ids_rejects_a_survivor_inventory_row_without_warehouse_identity(): void
    {
        $detail = $this->productDetail();
        unset($detail['skus'][2]['inventory'][0]['warehouse_id']);

        try {
            app(TiktokPartialEditSkuPayloadBuilder::class)->deleteSkuIds($detail, ['tt-old-red', 'tt-old-blue']);
            $this->fail('Expected warehouse identity to be required for every inventory row.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Kontrak SKU TikTok tersisa tidak lengkap.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_delete_sku_ids_preserves_supported_price_and_inventory_fields_without_value_changes(): void
    {
        $detail = $this->productDetail();
        $price = [
            'currency' => 'IDR',
            'sale_price' => '25000.00',
            'tax_exclusive_price' => '24000.50',
            'amount' => '25000.00',
        ];
        $inventory = [
            ['warehouse_id' => 'warehouse-1', 'quantity' => '04'],
            ['warehouse_id' => 'warehouse-2', 'quantity' => 0],
        ];
        $detail['skus'][2]['price'] = $price;
        $detail['skus'][2]['inventory'] = $inventory;

        $payload = app(TiktokPartialEditSkuPayloadBuilder::class)
            ->deleteSkuIds($detail, ['tt-old-red', 'tt-old-blue']);

        $this->assertSame($price, $payload['skus'][0]['price']);
        $this->assertSame($inventory, $payload['skus'][0]['inventory']);
        Http::assertNothingSent();
    }

    /**
     * @dataProvider unsupportedSurvivorFieldProvider
     */
    public function test_delete_sku_ids_fails_closed_instead_of_dropping_unsupported_price_or_inventory_fields(
        string $contract,
        string $field,
        mixed $value
    ): void {
        $detail = $this->productDetail();
        if ($contract === 'price') {
            $detail['skus'][2]['price'][$field] = $value;
        } else {
            $detail['skus'][2]['inventory'][0][$field] = $value;
        }

        try {
            app(TiktokPartialEditSkuPayloadBuilder::class)
                ->deleteSkuIds($detail, ['tt-old-red', 'tt-old-blue']);
            $this->fail('Expected unsupported fresh contract fields to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Kontrak SKU TikTok tersisa tidak lengkap.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public static function unsupportedSurvivorFieldProvider(): array
    {
        return [
            'nested price field' => ['price', 'original_price', ['amount' => '30000']],
            'read-only inventory field' => ['inventory', 'reserved_quantity', 2],
            'nested warehouse field' => ['inventory', 'warehouse', ['id' => 'warehouse-1']],
        ];
    }

    /**
     * @dataProvider noncanonicalPriceProvider
     */
    public function test_delete_sku_ids_fails_closed_instead_of_rewriting_noncanonical_prices(mixed $value): void
    {
        $detail = $this->productDetail();
        $detail['skus'][2]['price']['sale_price'] = $value;

        try {
            app(TiktokPartialEditSkuPayloadBuilder::class)
                ->deleteSkuIds($detail, ['tt-old-red', 'tt-old-blue']);
            $this->fail('Expected a noncanonical fresh price to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Kontrak SKU TikTok tersisa tidak lengkap.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public static function noncanonicalPriceProvider(): array
    {
        return [
            'localized price' => ['Rp 25.000'],
            'surrounding whitespace' => [' 25000 '],
            'negative price' => ['-25000'],
            'nested value' => [['amount' => '25000']],
        ];
    }

    public function test_delete_sku_ids_fails_closed_instead_of_clamping_a_negative_quantity(): void
    {
        $detail = $this->productDetail();
        $detail['skus'][2]['inventory'][0]['quantity'] = -4;

        try {
            app(TiktokPartialEditSkuPayloadBuilder::class)
                ->deleteSkuIds($detail, ['tt-old-red', 'tt-old-blue']);
            $this->fail('Expected a negative quantity to be rejected rather than clamped.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Kontrak SKU TikTok tersisa tidak lengkap.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    private function productDetail(): array
    {
        return [
            'id' => 'tt-product-42',
            'skus' => [
                $this->sku('tt-old-red', 'INT-42-RED', 'Rp 25.000', 2, 'red', 'Merah'),
                $this->sku('tt-old-blue', 'INT-42-BLUE', 25000, 3, 'blue', 'Biru'),
                $this->sku('tt-green', 'INT-42-GREEN', '25000', 4, 'green', 'Hijau'),
            ],
        ];
    }

    private function sku(
        string $id,
        string $sellerSku,
        mixed $price,
        int $quantity,
        string $attributeId,
        string $attributeName
    ): array {
        return [
            'id' => $id,
            'seller_sku' => $sellerSku,
            'price' => [
                'currency' => 'IDR',
                'sale_price' => $price,
                'tax_exclusive_price' => $price,
                'amount' => $price,
            ],
            'inventory' => [[
                'warehouse_id' => 'warehouse-1',
                'quantity' => $quantity,
            ]],
            'sales_attributes' => [[
                'id' => '100000',
                'name' => 'Warna',
                'value_id' => $attributeId,
                'value_name' => $attributeName,
                'sku_img' => ['uri' => 'tos-alisg-i-aphluv4xwc-sg/'.$attributeId],
            ]],
        ];
    }
}
