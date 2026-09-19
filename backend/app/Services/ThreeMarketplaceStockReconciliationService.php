<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ThreeMarketplaceStockReconciliationService
{
    public const ACCOUNT_KEYS = ['shopee-agnishopbjm', 'shopee-gitacollectionbjm', 'tiktok-agnishopbjm'];

    public function __construct(
        private readonly MarketplaceApiService $apiService,
        private readonly MarketplaceSyncService $syncService,
    ) {
    }

    public function reconcile(array $accountKeys = self::ACCOUNT_KEYS): array
    {
        $summary = ['status' => 'success', 'checked' => 0, 'pushed' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0, 'accounts' => []];
        if (! Schema::hasTable('marketplace_listings')) {
            return [...$summary, 'status' => 'warning', 'message' => 'Mapping marketplace_listings belum tersedia.'];
        }

        $rows = DB::table('marketplace_listings')->whereIn('account_key', $accountKeys)->whereRaw('COALESCE(is_active, true) = true')->get();
        foreach ($rows->groupBy('stock_master_id') as $listings) {
            $summary['checked']++;
            $sources = [];
            foreach (self::ACCOUNT_KEYS as $accountKey) {
                $listing = $listings->firstWhere('account_key', $accountKey);
                if (! $listing) continue;
                $candidate = $this->readStock($accountKey, $listing);
                if (($candidate['status'] ?? '') === 'success') {
                    $sources[] = ['listing' => $listing, 'stock' => (int) $candidate['stock']];
                }
            }
            $distinctStocks = array_values(array_unique(array_map(fn (array $row): int => $row['stock'], $sources)));
            if ($sources === [] || count($distinctStocks) !== 1) { $summary['skipped']++; continue; }
            $source = $sources[0]['listing'];
            $stock = $sources[0]['stock'];

            foreach ($listings as $target) {
                if ($target->account_key === $source->account_key) continue;
                $result = $this->syncService->pushTargetStockForAccount((object) [
                    'shopee_product_id' => $target->account_key === 'shopee-agnishopbjm' ? $target->remote_product_id : $source->remote_product_id,
                    'shopee_sku' => $target->account_key === 'shopee-agnishopbjm' ? $target->remote_variant_id : $source->remote_variant_id,
                    'shopee_gita_product_id' => $target->account_key === 'shopee-gitacollectionbjm' ? $target->remote_product_id : null,
                    'shopee_gita_sku' => $target->account_key === 'shopee-gitacollectionbjm' ? $target->remote_variant_id : null,
                    'tiktok_product_id' => $target->account_key === 'tiktok-agnishopbjm' ? $target->remote_product_id : null,
                    'tiktok_sku' => $target->account_key === 'tiktok-agnishopbjm' ? $target->remote_variant_id : null,
                ], $target->account_key, $stock, true, hash('sha256', 'hourly|'.$source->account_key.'|'.$target->account_key.'|'.$target->remote_product_id.'|'.$target->remote_variant_id.'|'.$stock));
                if (($result['status'] ?? '') === 'success') $summary['pushed']++; elseif (($result['status'] ?? '') === 'skipped') $summary['skipped']++; else $summary['failed']++;
                $summary['accounts'][$target->account_key]['last_status'] = $result['status'] ?? 'error';
            }
        }

        $summary['status'] = $summary['failed'] > 0 ? 'warning' : 'success';
        $summary['message'] = sprintf('Rekonsiliasi tiga marketplace selesai. Checked=%s pushed=%s skipped=%s failed=%s.', $summary['checked'], $summary['pushed'], $summary['skipped'], $summary['failed']);
        return $summary;
    }

    private function readStock(string $accountKey, object $listing): array
    {
        if (str_starts_with($accountKey, 'shopee-')) {
            return $this->apiService->fetchShopeeModelStockForAccount($accountKey, (string) $listing->remote_product_id, (string) $listing->remote_variant_id);
        }

        $row = DB::table('tiktok_products')->where('product_id', (string) $listing->remote_product_id)->where('sku_id', (string) $listing->remote_variant_id)->whereRaw('COALESCE(is_active, true) = true')->first();
        return $row && is_numeric($row->stock_qty ?? null) ? ['status' => 'success', 'stock' => (int) $row->stock_qty] : ['status' => 'error', 'message' => 'Stok TikTok aktif belum tersedia.'];
    }
}
