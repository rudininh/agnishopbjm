<?php

namespace App\Services;

use RuntimeException;

class TiktokPartialEditSkuPayloadBuilder
{
    public function deleteSkuIds(array $productDetail, array $targetSkuIds): array
    {
        $targets = [];
        foreach ($targetSkuIds as $targetSkuId) {
            $key = $this->normalizeSkuMatchValue($targetSkuId);
            if ($key !== '') {
                $targets[$key] = trim((string) $targetSkuId);
            }
        }

        $found = [];
        $survivors = [];
        $seenSkuIds = [];

        foreach ($this->normalizeSkuList($productDetail) as $sku) {
            if (! is_array($sku)) {
                throw new RuntimeException('Detail SKU TikTok tidak lengkap atau memiliki ID duplikat.');
            }

            $skuId = trim((string) ($sku['id'] ?? $sku['sku_id'] ?? ''));
            $skuKey = $this->normalizeSkuMatchValue($skuId);
            if ($skuKey === '' || isset($seenSkuIds[$skuKey])) {
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
        $salePrice = is_array($priceNode)
            ? data_get($priceNode, 'sale_price', data_get($priceNode, 'amount', data_get($priceNode, 'tax_exclusive_price')))
            : $priceNode;
        $salePrice = $this->normalizePriceValue($salePrice);

        if ($salePrice === '') {
            return null;
        }

        $currency = is_array($priceNode)
            ? trim((string) data_get($priceNode, 'currency', 'IDR'))
            : 'IDR';
        $taxExclusivePrice = is_array($priceNode)
            ? $this->normalizePriceValue(data_get($priceNode, 'tax_exclusive_price', $salePrice))
            : $salePrice;
        $amount = is_array($priceNode)
            ? $this->normalizePriceValue(data_get($priceNode, 'amount', $salePrice))
            : $salePrice;

        return array_filter([
            'currency' => $currency !== '' ? $currency : 'IDR',
            'sale_price' => $salePrice,
            'tax_exclusive_price' => $taxExclusivePrice !== '' ? $taxExclusivePrice : $salePrice,
            'amount' => $amount !== '' ? $amount : $salePrice,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function normalizePriceValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = data_get($value, 'sale_price', data_get($value, 'amount', data_get($value, 'tax_exclusive_price')));
        }

        if ($value === null || $value === '') {
            return '';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return trim(preg_replace('/[^\d.]/', '', (string) $value) ?: '');
    }

    private function buildInventory(array $sku): array
    {
        $inventoryRows = data_get($sku, 'inventory', data_get($sku, 'inventories', []));
        if (! is_array($inventoryRows) || $inventoryRows === []) {
            $stock = data_get($sku, 'stock', data_get($sku, 'stock_qty'));
            if ($stock === null || $stock === '') {
                return [];
            }

            $row = ['quantity' => max(0, (int) $stock)];
            $warehouseId = trim((string) config('tiktok.default_warehouse_id', ''));
            if ($warehouseId === '') {
                throw new RuntimeException('Kontrak SKU TikTok tersisa tidak lengkap.');
            }
            $row['warehouse_id'] = $warehouseId;

            return [$row];
        }

        $rows = [];
        foreach ($inventoryRows as $inventory) {
            if (! is_array($inventory)) {
                throw new RuntimeException('Kontrak SKU TikTok tersisa tidak lengkap.');
            }

            $quantity = data_get($inventory, 'quantity', data_get($inventory, 'stock'));
            if ($quantity === null || $quantity === '') {
                throw new RuntimeException('Kontrak SKU TikTok tersisa tidak lengkap.');
            }

            $row = ['quantity' => max(0, (int) $quantity)];
            $warehouseId = trim((string) data_get($inventory, 'warehouse_id', data_get($inventory, 'warehouse.id', '')));
            if ($warehouseId === '') {
                throw new RuntimeException('Kontrak SKU TikTok tersisa tidak lengkap.');
            }
            $row['warehouse_id'] = $warehouseId;

            $rows[] = $row;
        }

        return $rows;
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

    private function normalizeSkuMatchValue(mixed $value): string
    {
        $value = strtolower(trim((string) ($value ?? '')));
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
