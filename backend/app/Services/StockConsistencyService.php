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
            $shopeeStock = $mapping->shopee_stock === null ? null : (int) $mapping->shopee_stock;
            $tiktokStock = $mapping->tiktok_stock === null ? null : (int) $mapping->tiktok_stock;
            $masterStock = (int) ($mapping->stock_qty ?? 0);

            if ($shopeeStock === null && $tiktokStock === null) {
                $this->syncService->logSync('safety_check', null, $sku, null, $masterStock, 'skipped', 'Tidak ada stok marketplace aktif untuk dibandingkan.');
                continue;
            }

            if (($shopeeStock === null || $shopeeStock === $masterStock) && ($tiktokStock === null || $tiktokStock === $masterStock)) {
                $this->syncService->logSync('safety_check', 'all', $sku, $masterStock, $masterStock, 'checked', 'Stok Shopee dan TikTok konsisten.');
                continue;
            }

            $oldStock = $shopeeStock ?? $tiktokStock ?? $masterStock;
            $this->syncService->updateLocalStock($mapping, 'shopee', $masterStock);
            $this->syncService->updateLocalStock($mapping, 'tiktok', $masterStock);
            $corrected += 1;

            $message = sprintf('Stock mismatch. Shopee=%s TikTok=%s. Corrected to %s.', $shopeeStock ?? '-', $tiktokStock ?? '-', $masterStock);
            $this->syncService->logSync('safety_check', 'all', $sku, (int) $oldStock, $masterStock, 'success', $message);
            $history[] = [
                'sku' => $sku,
                'shopee_stock' => $shopeeStock,
                'tiktok_stock' => $tiktokStock,
                'action' => $message,
            ];
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
