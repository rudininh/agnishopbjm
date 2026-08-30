<?php

namespace App\Services;

use RuntimeException;

class TiktokPartialEditSkuPayloadBuilder
{
    public function deleteSkuIds(array $productDetail, array $targetSkuIds): array
    {
        $targets = [];
        foreach ($targetSkuIds as $targetSkuId) {
            if (! is_string($targetSkuId)) {
                throw new RuntimeException('ID target SKU TikTok tidak valid.');
            }

            $targetSkuId = trim($targetSkuId);
            $key = $this->normalizeSkuId($targetSkuId);
            if ($key === '' || preg_match('/^-\d+$/D', $targetSkuId) === 1) {
                throw new RuntimeException('ID target SKU TikTok tidak valid.');
            }
            $targets[$key] = $targetSkuId;
        }

        $found = [];
        $survivors = [];
        $seenSkuIds = [];

        foreach ($this->normalizeSkuList($productDetail) as $sku) {
            if (! is_array($sku)) {
                throw new RuntimeException('Detail SKU TikTok tidak lengkap atau memiliki ID duplikat.');
            }

            $sourceSkuId = $sku['id'] ?? $sku['sku_id'] ?? null;
            if (! is_string($sourceSkuId)) {
                throw new RuntimeException('Detail SKU TikTok tidak lengkap atau memiliki ID duplikat.');
            }

            $skuId = trim($sourceSkuId);
            $skuKey = $this->normalizeSkuId($skuId);
            if ($skuKey === '' || preg_match('/^-\d+$/D', $skuId) === 1 || isset($seenSkuIds[$skuKey])) {
                throw new RuntimeException('Detail SKU TikTok tidak lengkap atau memiliki ID duplikat.');
            }
            $seenSkuIds[$skuKey] = true;

            if (isset($targets[$skuKey])) {
                $found[$skuKey] = true;

                continue;
            }

            $row = $this->buildKeepRow($sku);
            if (! isset($row['seller_sku'], $row['price'], $row['inventory'], $row['sales_attributes'])
                || $row['seller_sku'] === ''
                || $row['price'] === []
                || $row['inventory'] === []
                || $row['sales_attributes'] === []) {
                throw new RuntimeException('Kontrak SKU TikTok tersisa tidak lengkap.');
            }
            $survivors[] = $row;
        }

        if ($targets === [] || array_diff_key($targets, $found) !== []) {
            throw new RuntimeException('SKU TikTok target tidak ditemukan di detail produk terbaru.');
        }

        if ($survivors === []) {
            throw new RuntimeException('Varian TikTok tersisa tidak boleh kosong.');
        }

        return [
            'save_mode' => 'LISTING',
            'skus' => $survivors,
        ];
    }

    private function buildKeepRow(array $sku): array
    {
        $skuId = trim((string) ($sku['id'] ?? $sku['sku_id'] ?? ''));
        $row = ['id' => $skuId];
        $sellerSku = $this->extractSellerSku($sku);

        if ($sellerSku !== null) {
            $row['seller_sku'] = $sellerSku;
        }

        $price = $this->buildPrice($sku);
        if ($price !== null) {
            $row['price'] = $price;
        }

        $inventory = $this->buildInventory($sku);
        if ($inventory !== []) {
            $row['inventory'] = $inventory;
        }

        $salesAttributes = data_get($sku, 'sales_attributes', data_get($sku, 'sale_attributes', []));
        if (is_array($salesAttributes) && $salesAttributes !== []) {
            $row['sales_attributes'] = $salesAttributes;
        }

        return $row;
    }

    private function buildPrice(array $sku): ?array
    {
        $priceNode = data_get($sku, 'price');
        if (! is_array($priceNode) || $priceNode === [] || array_is_list($priceNode)) {
            return null;
        }

        $supportedKeys = ['currency', 'sale_price', 'tax_exclusive_price', 'amount'];
        if (array_diff(array_keys($priceNode), $supportedKeys) !== []
            || ! array_key_exists('sale_price', $priceNode)
            || ! $this->isCanonicalPriceValue($priceNode['sale_price'])) {
            throw new RuntimeException('Kontrak SKU TikTok tersisa tidak lengkap.');
        }

        foreach (['tax_exclusive_price', 'amount'] as $key) {
            if (array_key_exists($key, $priceNode) && ! $this->isCanonicalPriceValue($priceNode[$key])) {
                throw new RuntimeException('Kontrak SKU TikTok tersisa tidak lengkap.');
            }
        }

        if (array_key_exists('currency', $priceNode)) {
            $currency = $priceNode['currency'];
            if (! is_string($currency) || $currency === '' || trim($currency) !== $currency) {
                throw new RuntimeException('Kontrak SKU TikTok tersisa tidak lengkap.');
            }
        } else {
            $priceNode = ['currency' => 'IDR', ...$priceNode];
        }

        if (! array_key_exists('tax_exclusive_price', $priceNode)) {
            $priceNode['tax_exclusive_price'] = $priceNode['sale_price'];
        }
        if (! array_key_exists('amount', $priceNode)) {
            $priceNode['amount'] = $priceNode['sale_price'];
        }

        return $priceNode;
    }

    private function buildInventory(array $sku): array
    {
        $inventoryRows = data_get($sku, 'inventory');
        if (! is_array($inventoryRows) || $inventoryRows === [] || ! array_is_list($inventoryRows)) {
            return [];
        }

        $rows = [];
        foreach ($inventoryRows as $inventory) {
            if (! is_array($inventory) || $inventory === [] || array_is_list($inventory)) {
                throw new RuntimeException('Kontrak SKU TikTok tersisa tidak lengkap.');
            }

            if (array_diff(array_keys($inventory), ['warehouse_id', 'quantity']) !== []
                || ! array_key_exists('warehouse_id', $inventory)
                || ! array_key_exists('quantity', $inventory)) {
                throw new RuntimeException('Kontrak SKU TikTok tersisa tidak lengkap.');
            }

            $warehouseId = $inventory['warehouse_id'];
            if (! is_string($warehouseId) || $warehouseId === '' || trim($warehouseId) !== $warehouseId) {
                throw new RuntimeException('Kontrak SKU TikTok tersisa tidak lengkap.');
            }

            if (! $this->isCanonicalInventoryQuantity($inventory['quantity'])) {
                throw new RuntimeException('Kontrak SKU TikTok tersisa tidak lengkap.');
            }

            $rows[] = $inventory;
        }

        return $rows;
    }

    private function isCanonicalPriceValue(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }
        if (is_float($value)) {
            return is_finite($value) && $value >= 0;
        }

        return is_string($value) && preg_match('/^\d+(?:\.\d+)?$/D', $value) === 1;
    }

    private function isCanonicalInventoryQuantity(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }

        return is_string($value) && preg_match('/^\d+$/D', $value) === 1;
    }

    private function normalizeSkuList(array $data): array
    {
        foreach ([
            data_get($data, 'skus', []),
            data_get($data, 'sku_list', []),
            data_get($data, 'sku_info_list', []),
            data_get($data, 'sku_infos', []),
            data_get($data, 'skus_info', []),
            data_get($data, 'variants', []),
            data_get($data, 'model_list', []),
            data_get($data, 'product_skus', []),
        ] as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    private function extractSellerSku(array $sku): ?string
    {
        foreach ([
            data_get($sku, 'seller_sku'),
            data_get($sku, 'sku_code'),
            data_get($sku, 'sku_no'),
            data_get($sku, 'sellerSku'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function normalizeSkuId(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/[a-z0-9]/i', $value) === 1 ? $value : '';
    }
}
