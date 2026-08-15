<?php

namespace App\Services;

use App\Http\Controllers\MarketplaceImportController;
use App\Http\Controllers\OmnichannelController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ShopeeMassUploadManifestService
{
    public function __construct(
        private readonly OmnichannelController $omnichannel,
        private readonly ?MarketplaceImportController $marketplaceImportController = null,
    )
    {
    }

    public function refreshSource(): array
    {
        try {
            $result = $this->omnichannel->syncShopeeProductCachesForMassUpdate();
        } catch (\Throwable) {
            throw new RuntimeException('Katalog sumber Shopee tidak dapat disegarkan dengan aman.');
        }

        $shopee = is_array($result['shopee'] ?? null) ? $result['shopee'] : [];
        if (($result['status'] ?? '') !== 'ok' || ($shopee['status'] ?? '') !== 'ok') {
            throw new RuntimeException('Katalog sumber Shopee tidak dapat disegarkan dengan aman.');
        }

        return [
            'products' => max(0, (int) ($shopee['products'] ?? 0)),
            'variants' => max(0, (int) ($shopee['variants'] ?? 0)),
            'refreshed_at' => (string) ($result['source_refreshed_at'] ?? ''),
        ];
    }

    public function buildForJob(int $jobId): array
    {
        if (! Schema::hasTable('shopee_mass_upload_manifests')) {
            throw new RuntimeException('Audit manifest Mass Update belum tersedia.');
        }

        $imports = $this->marketplaceImportController;
        if (! $imports) {
            throw new RuntimeException('Sumber manifest Mass Update tidak tersedia.');
        }

        return DB::transaction(function () use ($jobId, $imports): array {
            $job = DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->lockForUpdate()->first();
            if (! $job) {
                throw new RuntimeException('Job Mass Update tidak ditemukan.');
            }
            if (DB::table('shopee_mass_upload_manifests')->where('job_id', $jobId)->exists()) {
                throw new RuntimeException('Manifest Mass Update untuk job ini sudah terkunci.');
            }

            $sources = [];
            foreach ($imports->shopeeGitaSourceVariants() as $source) {
                $itemId = trim((string) $this->value($source, 'item_id'));
                $modelId = trim((string) $this->value($source, 'model_id'));
                $sellerSku = trim((string) $this->value($source, 'seller_sku'));
                $price = (int) $this->value($source, 'price');
                $stockQty = (int) $this->value($source, 'stock_qty');
                if ($itemId === '' || $modelId === '' || $sellerSku === '' || $price < 99 || $stockQty < 0) {
                    throw new RuntimeException('Data sumber Shopee tidak lengkap untuk manifest Mass Update.');
                }
                $productImageIdentities = $this->imageIdentities($this->value($source, 'product_image_urls'));
                if ($productImageIdentities === []) {
                    throw new RuntimeException('Data gambar sumber Shopee tidak lengkap untuk manifest Mass Update.');
                }
                $productImageUrls = $this->imageUrls($this->value($source, 'product_image_urls'));

                $key = $this->sourceKey($itemId, $sellerSku);
                if (isset($sources[$key])) {
                    throw new RuntimeException('SKU sumber Shopee duplikat pada manifest Mass Update.');
                }
                $sources[$key] = [
                    'item_id' => $itemId,
                    'model_id' => $modelId,
                    'seller_sku' => $sellerSku,
                    'product_name' => trim((string) $this->value($source, 'product_name')),
                    'variant_name' => trim((string) $this->value($source, 'variant_name')),
                    'description' => trim((string) $this->value($source, 'description')),
                    'price' => $price,
                    'stock_qty' => $stockQty,
                    'product_image_urls' => $productImageUrls,
                    'variant_image_url' => trim((string) $this->value($source, 'raw_image_url')),
                    'product_image_identities' => $productImageIdentities,
                    'variant_image_identity' => $this->imageIdentity((string) $this->value($source, 'raw_image_url')),
                ];
            }
            if ($sources === []) {
                throw new RuntimeException('Katalog sumber Shopee tidak memiliki varian untuk diunggah.');
            }

            $mappings = [];
            foreach ($imports->shopeeGitaSalesTargetMappings() as $mapping) {
                $sourceItemId = trim((string) $this->value($mapping, 'source_item_id'));
                $sellerSku = trim((string) $this->value($mapping, 'source_seller_sku'));
                $targetItemId = trim((string) $this->value($mapping, 'target_item_id'));
                $targetModelId = trim((string) $this->value($mapping, 'target_model_id'));
                $key = $this->sourceKey($sourceItemId, $sellerSku);
                if ($sourceItemId === '' || $sellerSku === '' || $targetItemId === '' || $targetModelId === '' || isset($mappings[$key])) {
                    throw new RuntimeException('Pemetaan template Gitashop tidak lengkap atau duplikat.');
                }
                $mappings[$key] = [
                    'target_item_id' => $targetItemId,
                    'target_model_id' => $targetModelId,
                ];
            }

            if (count($sources) !== count($mappings) || array_diff_key($sources, $mappings) !== [] || array_diff_key($mappings, $sources) !== []) {
                throw new RuntimeException('Cakupan template Gitashop tidak sama dengan katalog sumber Shopee.');
            }

            $now = now();
            $rows = [];
            foreach ($sources as $key => $source) {
                $target = $mappings[$key];
                $fingerprintData = [
                    'source_item_id' => $source['item_id'],
                    'source_model_id' => $source['model_id'],
                    'target_item_id' => $target['target_item_id'],
                    'target_model_id' => $target['target_model_id'],
                    'seller_sku' => $source['seller_sku'],
                    'product_name' => $source['product_name'],
                    'variant_name' => $source['variant_name'],
                    'description' => $source['description'],
                    'price' => $source['price'],
                    'stock_qty' => $source['stock_qty'],
                    'product_image_urls' => $source['product_image_urls'],
                    'variant_image_url' => $source['variant_image_url'],
                    'product_image_identities' => $source['product_image_identities'],
                    'variant_image_identity' => $source['variant_image_identity'],
                ];
                $rows[] = [
                    ...$fingerprintData,
                    'job_id' => $jobId,
                    'product_image_urls' => json_encode($source['product_image_urls'], JSON_UNESCAPED_SLASHES),
                    'product_image_identities' => json_encode($source['product_image_identities'], JSON_UNESCAPED_SLASHES),
                    'fingerprint' => hash('sha256', json_encode($fingerprintData, JSON_UNESCAPED_SLASHES)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('shopee_mass_upload_manifests')->insert($rows);
            DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->update([
                'expected_product_count' => count(array_unique(array_column($rows, 'source_item_id'))),
                'expected_variant_count' => count($rows),
                'updated_at' => $now,
            ]);

            return [
                'products' => count(array_unique(array_column($rows, 'source_item_id'))),
                'variants' => count($rows),
            ];
        });
    }

    private function sourceKey(string $itemId, string $sellerSku): string
    {
        return mb_strtolower(trim($itemId).'|'.trim($sellerSku));
    }

    private function value(object|array $value, string $key): mixed
    {
        return is_array($value) ? ($value[$key] ?? null) : ($value->{$key} ?? null);
    }

    private function imageIdentities(mixed $urls): array
    {
        return collect(is_array($urls) ? $urls : [])
            ->map(fn ($url) => $this->imageIdentity((string) $url))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function imageUrls(mixed $urls): array
    {
        return collect(is_array($urls) ? $urls : [])
            ->map(fn ($url) => trim((string) $url))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function imageIdentity(string $url): ?string
    {
        $path = trim((string) parse_url(trim($url), PHP_URL_PATH));

        return $path === '' ? null : mb_strtolower($path);
    }
}
