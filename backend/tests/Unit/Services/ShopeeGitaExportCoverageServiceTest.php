<?php

namespace Tests\Unit\Services;

use App\Services\ShopeeGitaExportCoverageService;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ShopeeGitaExportCoverageServiceTest extends TestCase
{
    public function test_classifies_every_source_variant_exactly_once(): void
    {
        $sources = collect([
            $this->source('100', '1', 'INT-100-RED', 'Produk Lama', 'Red'),
            $this->source('100', '2', 'INT-100-BLUE-NEW', 'Produk Lama', 'Blue'),
            $this->source('100', '3', 'INT-100-GREEN', 'Produk Lama', 'Green'),
            $this->source('200', '4', 'INT-200-BLACK', 'Produk Baru', 'Black'),
        ]);
        $mappings = collect([
            $this->mapping('100', 'INT-100-RED', '900', '91', 'Produk Lama', 'Red'),
            $this->mapping('100', 'INT-100-BLUE-OLD', '900', '92', 'Produk Lama', 'Blue'),
        ]);

        $result = app(ShopeeGitaExportCoverageService::class)->analyze(
            $sources,
            $mappings,
            ['sales_sha256' => str_repeat('a', 64), 'sales_last_modified_at' => '2026-08-29T10:00:00+08:00'],
        );

        $this->assertSame([
            'mass_update_ready' => 1,
            'new_product' => 1,
            'new_variant' => 1,
            'sku_changed' => 1,
            'blocked' => 0,
        ], $result['summary']['variants_by_status']);
        $this->assertCount(4, $result['items']);
        $this->assertSame(4, array_sum($result['summary']['variants_by_status']));
        $this->assertSame([['target_item_id' => '900', 'target_model_id' => '91']], $result['ready_targets']);
    }

    public function test_blocks_duplicate_exact_sku_mappings(): void
    {
        $result = app(ShopeeGitaExportCoverageService::class)->analyze(
            [$this->source('100', '1', 'INT-100-RED', 'Produk', 'Red')],
            [
                $this->mapping('100', 'INT-100-RED', '900', '91', 'Produk', 'Red'),
                $this->mapping('100', 'INT-100-RED', '900', '92', 'Produk', 'Red'),
            ],
            $this->metadata(),
        );

        $this->assertSame('blocked', $result['items'][0]['status']);
        $this->assertSame('duplicate_target_sku_mapping', $result['items'][0]['reason']);
    }

    public function test_blocks_multiple_normalized_name_matches(): void
    {
        $result = app(ShopeeGitaExportCoverageService::class)->analyze(
            [$this->source('100', '1', 'INT-100-BLUE-NEW', 'Produk', 'Blue')],
            [
                $this->mapping('100', 'INT-100-BLUE-A', '900', '91', 'Produk', 'Blue'),
                $this->mapping('100', 'INT-100-BLUE-B', '900', '92', 'Produk', ' blue '),
            ],
            $this->metadata(),
        );

        $this->assertSame('blocked', $result['items'][0]['status']);
        $this->assertSame('ambiguous_target_variant_name', $result['items'][0]['reason']);
    }

    public function test_keeps_punctuation_distinct_when_matching_variant_names(): void
    {
        $result = app(ShopeeGitaExportCoverageService::class)->analyze(
            [$this->source('100', '1', 'INT-100-BLUE-GREY-NEW', 'Produk', 'Blue.Grey')],
            [$this->mapping('100', 'INT-100-BLUE-GREY-OLD', '900', '91', 'Produk', 'Blue Grey')],
            $this->metadata(),
        );

        $this->assertSame('new_variant', $result['items'][0]['status']);
        $this->assertSame('missing_target_variant', $result['items'][0]['reason']);
    }

    public function test_normalizes_canonically_equivalent_unicode_variant_names_without_intl(): void
    {
        $script = <<<'PHP'
function normalizer_normalize(string $value): string
{
    return str_replace("e\u{0301}", 'é', $value);
}

require getcwd().'/backend/app/Services/ShopeeGitaExportCoverageService.php';

$result = (new App\Services\ShopeeGitaExportCoverageService())->analyze(
    [[
        'item_id' => '100',
        'model_id' => '1',
        'seller_sku' => 'INT-100-BLUE-NEW',
        'product_name' => 'Produk',
        'variant_name' => "Bleu e\u{0301}",
    ]],
    [[
        'source_item_id' => '100',
        'source_seller_sku' => 'INT-100-BLUE-OLD',
        'target_item_id' => '900',
        'target_model_id' => '91',
        'target_product_name' => 'Produk',
        'target_variant_name' => 'bleu é',
    ]],
    ['sales_sha256' => str_repeat('a', 64), 'sales_last_modified_at' => '2026-08-29T10:00:00+08:00'],
);

echo $result['items'][0]['status'];
PHP;
        $process = new Process([PHP_BINARY, '-n', '-r', $script], dirname(base_path()));
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame('sku_changed', $process->getOutput());
    }

    public function test_revision_is_order_independent_when_scalar_values_contain_the_prior_sort_delimiter(): void
    {
        $sources = [
            $this->source("100\x1f2", '3', 'INT-100', 'Produk', 'A'),
            $this->source('100', "2\x1f3", 'INT-100', 'Produk', 'A'),
        ];
        $service = app(ShopeeGitaExportCoverageService::class);

        $this->assertSame(
            $service->analyze($sources, [], $this->metadata())['revision'],
            $service->analyze(array_reverse($sources), [], $this->metadata())['revision'],
        );
    }

    public function test_revision_is_order_independent_for_distinct_numeric_equivalent_scalar_values(): void
    {
        $sources = [
            $this->source('01', 'model', 'INT-100', 'Produk', 'A'),
            $this->source('1', 'model', 'INT-100', 'Produk', 'A'),
        ];
        $mappings = [
            $this->mapping('01', 'INT-100', '01', 'model', 'Produk', 'A'),
            $this->mapping('1', 'INT-100', '1', 'model', 'Produk', 'A'),
        ];
        $service = app(ShopeeGitaExportCoverageService::class);

        $this->assertSame(
            $service->analyze($sources, $mappings, $this->metadata())['revision'],
            $service->analyze(array_reverse($sources), array_reverse($mappings), $this->metadata())['revision'],
        );
    }

    public function test_revision_is_order_independent_and_changes_with_source_mapping_or_template_input(): void
    {
        $sources = [
            $this->source('100', '1', 'INT-100-RED', 'Produk', 'Red'),
            $this->source('200', '2', 'INT-200-BLUE', 'Produk Baru', 'Blue'),
        ];
        $mappings = [
            $this->mapping('100', 'INT-100-RED', '900', '91', 'Produk', 'Red'),
            $this->mapping('200', 'INT-200-BLUE', '901', '92', 'Produk Baru', 'Blue'),
        ];
        $service = app(ShopeeGitaExportCoverageService::class);
        $baseline = $service->analyze($sources, $mappings, $this->metadata());

        $this->assertSame($baseline['revision'], $service->analyze(array_reverse($sources), array_reverse($mappings), $this->metadata())['revision']);

        $changedSource = $sources;
        $changedSource[0]['seller_sku'] = 'INT-100-RED-CHANGED';
        $this->assertNotSame($baseline['revision'], $service->analyze($changedSource, $mappings, $this->metadata())['revision']);

        $changedMapping = $mappings;
        $changedMapping[0]['target_model_id'] = '911';
        $this->assertNotSame($baseline['revision'], $service->analyze($sources, $changedMapping, $this->metadata())['revision']);

        $this->assertNotSame($baseline['revision'], $service->analyze($sources, $mappings, [
            'sales_sha256' => str_repeat('b', 64),
            'sales_last_modified_at' => '2026-08-29T10:00:00+08:00',
        ])['revision']);
    }

    public function test_assert_revision_rejects_a_stale_revision_with_http_conflict(): void
    {
        $snapshot = app(ShopeeGitaExportCoverageService::class)->analyze([], [], $this->metadata());

        try {
            app(ShopeeGitaExportCoverageService::class)->assertRevision($snapshot, 'stale');
            $this->fail('Expected stale revisions to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    private function source(string $itemId, string $modelId, string $sellerSku, string $productName, string $variantName): array
    {
        return [
            'item_id' => $itemId,
            'model_id' => $modelId,
            'seller_sku' => $sellerSku,
            'product_name' => $productName,
            'variant_name' => $variantName,
        ];
    }

    private function mapping(string $sourceItemId, string $sourceSellerSku, string $targetItemId, string $targetModelId, string $targetProductName, string $targetVariantName): array
    {
        return [
            'source_item_id' => $sourceItemId,
            'source_seller_sku' => $sourceSellerSku,
            'target_item_id' => $targetItemId,
            'target_model_id' => $targetModelId,
            'target_product_name' => $targetProductName,
            'target_variant_name' => $targetVariantName,
        ];
    }

    private function metadata(): array
    {
        return [
            'sales_sha256' => str_repeat('a', 64),
            'sales_last_modified_at' => '2026-08-29T10:00:00+08:00',
        ];
    }
}
