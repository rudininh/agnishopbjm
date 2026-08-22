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

    public function test_delete_sku_ids_normalizes_duplicate_targets_and_removes_each_target_once(): void
    {
        $payload = app(TiktokPartialEditSkuPayloadBuilder::class)->deleteSkuIds(
            $this->productDetail(),
            [' tt-old-red ', 'TT-OLD-RED', 'tt-old-blue', ' TT-OLD-BLUE ']
        );

        $this->assertSame(['tt-green'], array_column($payload['skus'], 'id'));
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
