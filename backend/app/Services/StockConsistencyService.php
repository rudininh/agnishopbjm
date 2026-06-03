<?php

namespace App\Services;

class StockConsistencyService
{
    public function __construct(private readonly MarketplaceSyncService $syncService)
    {
    }

    public function run(): array
    {
        $checked = 0;
        $corrected = 0;
        $history = [];

        foreach ($this->syncService->activeSkuMappings() as $mapping) {
            $checked += 1;
            $sku = $this->syncService->canonicalSku($mapping);
            $tiktokStock = $mapping->tiktok_stock === null ? null : (int) $mapping->tiktok_stock;
            $shopeeStock = $mapping->shopee_stock === null ? null : (int) $mapping->shopee_stock;

            if (trim((string) ($mapping->shopee_product_id ?? '')) === '' || trim((string) ($mapping->shopee_sku ?? '')) === '') {
                $this->syncService->logSync('safety_check', 'tiktok', $sku, $tiktokStock, null, 'skipped', 'Safety check dilewati: item/model Shopee belum lengkap.');
                continue;
            }

            $result = $this->syncService->mirrorShopeeStockToTiktok($mapping, 'Safety check Shopee master');
            if (($result['status'] ?? '') === 'success') {
                $newStock = (int) ($result['new_stock'] ?? 0);
                if ($tiktokStock === $newStock && $shopeeStock === $newStock) {
                    $this->syncService->logSync('safety_check', 'tiktok', $sku, $newStock, $newStock, 'checked', 'Stok Shopee dan TikTok konsisten.');
                    continue;
                }

                $corrected += 1;
                $message = sprintf('Stock mismatch. Shopee=%s TikTok=%s. Corrected TikTok to %s.', $newStock, $tiktokStock ?? '-', $newStock);
                $this->syncService->logSync('safety_check', 'tiktok', $sku, $tiktokStock, $newStock, 'success', $message);
                $history[] = [
                    'sku' => $sku,
                    'shopee_stock' => $newStock,
                    'tiktok_stock' => $tiktokStock,
                    'action' => $message,
                ];
                continue;
            }

            $this->syncService->logSync('safety_check', 'tiktok', $sku, $tiktokStock, null, 'error', $result['message'] ?? 'Safety check gagal mirror stok Shopee.');
        }

        return [
            'status' => 'ok',
            'message' => "Safety check selesai. {$checked} SKU dicek, {$corrected} dikoreksi.",
            'total_checked' => $checked,
            'total_corrected' => $corrected,
            'history' => $history,
        ];
    }
}
