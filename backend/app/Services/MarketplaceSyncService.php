<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MarketplaceSyncService
{
    public function __construct(private readonly MarketplaceApiService $apiService)
    {
    }

    public function dashboard(): array
    {
        $today = Carbon::today();

        return [
            'statuses' => [
                'shopee' => $this->marketplaceStatus('shopee', $today),
                'tiktok' => $this->marketplaceStatus('tiktok', $today),
            ],
            'engine' => [
                'status' => 'active',
                'realtime_sync' => true,
                'safety_check' => true,
                'live_push' => $this->livePushEnabled(),
                'cron_interval' => 'Every 15 Minutes',
                'last_run' => $this->lastSafetyRun(),
                'next_run' => $this->nextSafetyRun(),
            ],
            'order_sync' => $this->orderSyncSummary($today),
            'webhook_urls' => [
                'shopee' => url('/api/webhooks/shopee'),
                'tiktok' => url('/api/webhooks/tiktok'),
            ],
        ];
    }

    public function webhookLogs(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = DB::table('marketplace_webhook_logs')->orderByDesc('created_at')->orderByDesc('id');

        if (($filters['marketplace'] ?? '') !== '') {
            $query->where('marketplace', $filters['marketplace']);
        }
        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }
        if (($filters['date'] ?? '') !== '') {
            $query->whereDate('created_at', $filters['date']);
        }

        return $this->paginateQuery($query, $page, $perPage);
    }

    public function syncLogs(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = DB::table('marketplace_sync_logs')->orderByDesc('created_at')->orderByDesc('id');

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }
        if (($filters['date'] ?? '') !== '') {
            $query->whereDate('created_at', $filters['date']);
        }
        if (($filters['marketplace'] ?? '') !== '') {
            $marketplace = $filters['marketplace'];
            $query->where(function ($inner) use ($marketplace): void {
                $inner->where('source_marketplace', $marketplace)
                    ->orWhere('target_marketplace', $marketplace);
            });
        }

        return $this->paginateQuery($query, $page, $perPage);
    }

    public function safetyHistory(int $page = 1, int $perPage = 20): array
    {
        $query = DB::table('marketplace_sync_logs')
            ->where('source_marketplace', 'safety_check')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return $this->paginateQuery($query, $page, $perPage);
    }

    public function orderSyncHistory(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyOrderSyncFilters($query, $filters);

        return [
            'summary' => $this->orderSyncSummary(Carbon::today()),
            ...$this->paginateQuery($query, $page, $perPage),
        ];
    }

    public function orderSyncExportRows(array $filters = [], int $limit = 5000)
    {
        $query = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyOrderSyncFilters($query, $filters);

        return $query->limit(min(5000, max(1, $limit)))->get();
    }

    public function stockAnomalies(array $filters = [], int $page = 1, int $perPage = 30): array
    {
        $type = trim((string) ($filters['type'] ?? ''));
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $rows = $this->activeSkuMappings();

        $items = $rows->map(function ($row): array {
            $sku = $this->canonicalSku($row);
            $shopeeStock = $row->shopee_stock !== null ? (int) $row->shopee_stock : null;
            $resolvedTiktokSku = $this->resolveTiktokSku($row, true);
            $tiktokStock = $row->tiktok_stock !== null
                ? (int) $row->tiktok_stock
                : ($resolvedTiktokSku && $resolvedTiktokSku->stock_qty !== null ? (int) $resolvedTiktokSku->stock_qty : null);
            $hasShopeeMapping = trim((string) ($row->shopee_product_id ?? '')) !== '' && trim((string) ($row->shopee_sku ?? '')) !== '';
            $hasTiktokSku = $resolvedTiktokSku !== null;

            $issueType = null;
            $severity = 'warning';
            $message = '';
            if (! $hasShopeeMapping) {
                $issueType = 'incomplete_mapping';
                $severity = 'error';
                $message = 'Mapping Shopee belum lengkap.';
            } elseif ($shopeeStock === null) {
                $issueType = 'missing_shopee_stock';
                $severity = 'error';
                $message = 'Stok Shopee belum tersedia di cache.';
            } elseif (! $hasTiktokSku || $tiktokStock === null) {
                $issueType = 'missing_tiktok_stock';
                $severity = 'error';
                $message = 'Varian TikTok aktif belum ditemukan dari SKU/nama varian.';
            } elseif ($shopeeStock !== $tiktokStock) {
                $issueType = 'stock_mismatch';
                $severity = 'warning';
                $message = sprintf('Stok tidak sinkron. Shopee=%s TikTok=%s.', $shopeeStock, $tiktokStock);
            }

            return [
                'sku' => $sku,
                'product_name' => (string) ($row->product_name ?? ''),
                'variant_name' => (string) ($row->variant_name ?? ''),
                'shopee_stock' => $shopeeStock,
                'tiktok_stock' => $tiktokStock,
                'difference' => $shopeeStock !== null && $tiktokStock !== null ? $shopeeStock - $tiktokStock : null,
                'issue_type' => $issueType,
                'severity' => $severity,
                'message' => $message,
                'shopee_product_id' => (string) ($row->shopee_product_id ?? ''),
                'shopee_model_id' => (string) ($row->shopee_sku ?? ''),
                'tiktok_product_id' => (string) ($row->tiktok_product_id ?? $resolvedTiktokSku->product_id ?? ''),
                'tiktok_sku_id' => (string) ($row->tiktok_sku ?? $resolvedTiktokSku->sku_id ?? ''),
                'updated_at' => (string) ($row->updated_at ?? ''),
            ];
        })->filter(fn (array $row): bool => $row['issue_type'] !== null);

        $summary = [
            'total_anomalies' => $items->count(),
            'stock_mismatch' => $items->where('issue_type', 'stock_mismatch')->count(),
            'missing_shopee_stock' => $items->where('issue_type', 'missing_shopee_stock')->count(),
            'missing_tiktok_stock' => $items->where('issue_type', 'missing_tiktok_stock')->count(),
            'incomplete_mapping' => $items->where('issue_type', 'incomplete_mapping')->count(),
            'last_safety_run' => $this->lastSafetyRun(),
        ];

        if ($type !== '') {
            $items = $items->filter(fn (array $row): bool => $row['issue_type'] === $type);
        }

        if ($search !== '') {
            $items = $items->filter(function (array $row) use ($search): bool {
                return str_contains(mb_strtolower($row['sku']), $search)
                    || str_contains(mb_strtolower($row['product_name']), $search)
                    || str_contains(mb_strtolower($row['variant_name']), $search);
            });
        }

        $items = $items
            ->sortBy([
                fn (array $row): int => $row['severity'] === 'error' ? 0 : 1,
                fn (array $row): string => $row['product_name'],
                fn (array $row): string => $row['variant_name'],
            ])
            ->values();

        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $total = $items->count();

        return [
            'summary' => $summary,
            'items' => $items->forPage($page, $perPage)->values(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function syncStockAnomaly(string $sku, string $sourceMarketplace): array
    {
        $sku = trim($sku);
        $sourceMarketplace = strtolower(trim($sourceMarketplace));
        if ($sku === '') {
            return ['status' => 'error', 'message' => 'SKU anomali wajib diisi.'];
        }
        if (! in_array($sourceMarketplace, ['shopee', 'tiktok'], true)) {
            return ['status' => 'error', 'message' => 'Sumber sinkron wajib Shopee atau TikTok.'];
        }

        $mapping = $this->findSkuMapping($sku);
        if (! $mapping) {
            return ['status' => 'error', 'message' => 'SKU mapping tidak ditemukan untuk '.$sku.'.'];
        }

        if ($sourceMarketplace === 'shopee') {
            return $this->mirrorShopeeStockToTiktok($mapping, 'Manual anomali stok Shopee -> TikTok', true, true);
        }

        $resolvedTiktokSku = $this->resolveTiktokSku($mapping, true);
        if (! $resolvedTiktokSku || $resolvedTiktokSku->stock_qty === null) {
            $this->logSync('manual_anomaly_tiktok_master', 'shopee', $sku, null, null, 'error', 'Manual anomali TikTok -> Shopee gagal: SKU TikTok aktif tidak ditemukan.');

            return ['status' => 'error', 'message' => 'SKU TikTok aktif tidak ditemukan untuk '.$sku.'.'];
        }

        $oldStock = $this->currentStockForMarketplace('shopee', $mapping) ?? (int) ($mapping->stock_qty ?? 0);
        $newStock = (int) $resolvedTiktokSku->stock_qty;
        $pushResult = $this->pushTargetStock($mapping, 'shopee', $newStock, true);
        $status = ($pushResult['status'] ?? '') === 'error' ? 'error' : 'success';

        if ($status === 'success') {
            $this->updateLocalStock($mapping, 'shopee', $newStock);
        }

        $message = sprintf('Manual anomali TikTok -> Shopee: stok Shopee %s -> %s. %s', $oldStock, $newStock, $pushResult['message'] ?? '-');
        $this->logSync('manual_anomaly_tiktok_master', 'shopee', $sku, $oldStock, $newStock, $status, $message);
        $this->updateStatus('tiktok', ['last_sync_at' => now(), 'status' => 'connected']);
        $this->updateStatus('shopee', ['last_sync_at' => now(), 'status' => $status === 'error' ? 'disconnected' : 'connected']);

        return [
            'status' => $status,
            'message' => $message,
            'sku' => $sku,
            'old_stock' => $oldStock,
            'new_stock' => $newStock,
            'push' => $pushResult,
        ];
    }

    public function orderSyncDetail(int $logId): array
    {
        $log = DB::table('marketplace_sync_logs')->where('id', $logId)->first();
        if (! $log) {
            return ['status' => 'error', 'message' => 'Log order sync tidak ditemukan.'];
        }

        $orderRef = $this->extractOrderReference($log);
        $relatedQuery = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->orderBy('created_at')
            ->orderBy('id');
        if ($orderRef !== '') {
            $relatedQuery->where(function ($query) use ($orderRef): void {
                $query->where('sku', $orderRef)
                    ->orWhere('message', 'like', '%'.$orderRef.'%');
            });
        } else {
            $relatedQuery->where('id', $logId);
        }

        $related = $relatedQuery->limit(200)->get();
        $order = null;
        if ($orderRef !== '' && ($log->source_marketplace === 'shopee_order' || $related->contains('source_marketplace', 'shopee_order'))) {
            $detail = $this->apiService->fetchShopeeOrderDetail($orderRef);
            if (($detail['status'] ?? '') === 'success') {
                $order = $this->formatShopeeOrderDetail($detail['order']);
            }
        }
        if ($orderRef !== '' && $order === null && ($log->source_marketplace === 'tiktok_order' || $related->contains('source_marketplace', 'tiktok_order'))) {
            $detail = $this->apiService->fetchTiktokOrderDetail($orderRef);
            if (($detail['status'] ?? '') === 'success') {
                $order = $this->formatTiktokOrderDetail($detail['order']);
            }
        }

        return [
            'status' => 'ok',
            'order_ref' => $orderRef,
            'log' => $log,
            'order' => $order,
            'stock_updates' => $related->map(fn ($row): array => [
                'id' => $row->id,
                'time' => $row->created_at,
                'type' => $row->source_marketplace,
                'target' => $row->target_marketplace,
                'sku' => $row->sku,
                'old_stock' => $row->old_stock,
                'new_stock' => $row->new_stock,
                'status' => $row->status,
                'message' => $row->message,
            ])->values(),
        ];
    }

    public function orderReferenceFromLog(object $log): string
    {
        return $this->extractOrderReference($log);
    }

    private function extractOrderReference(object $log): string
    {
        if (in_array($log->source_marketplace, ['shopee_order', 'tiktok_order'], true)) {
            return trim((string) $log->sku);
        }

        if (preg_match('/(?:Shopee|TikTok) order ([A-Z0-9]+)/i', (string) $log->message, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function formatShopeeOrderDetail(array $order): array
    {
        $items = [];
        foreach (($order['item_list'] ?? []) as $item) {
            $items[] = [
                'product_name' => $item['item_name'] ?? '-',
                'variant_name' => $item['model_name'] ?? '-',
                'qty' => (int) ($item['model_quantity_purchased'] ?? $item['active_qty'] ?? 0),
                'item_id' => (string) ($item['item_id'] ?? ''),
                'model_id' => (string) ($item['model_id'] ?? ''),
                'seller_sku' => (string) ($item['model_sku'] ?? $item['item_sku'] ?? ''),
                'image_url' => data_get($item, 'image_info.image_url'),
            ];
        }

        return [
            'order_sn' => $order['order_sn'] ?? null,
            'order_status' => $order['order_status'] ?? null,
            'create_time' => isset($order['create_time']) ? Carbon::createFromTimestamp((int) $order['create_time'])->toDateTimeString() : null,
            'update_time' => isset($order['update_time']) ? Carbon::createFromTimestamp((int) $order['update_time'])->toDateTimeString() : null,
            'items' => $items,
        ];
    }

    private function formatTiktokOrderDetail(array $order): array
    {
        $items = [];
        foreach (data_get($order, 'line_items', data_get($order, 'items', [])) as $item) {
            $items[] = [
                'product_name' => $item['product_name'] ?? '-',
                'variant_name' => $item['sku_name'] ?? data_get($item, 'sku.name', '-'),
                'qty' => (int) ($item['quantity'] ?? data_get($item, 'sku.quantity', 1)),
                'item_id' => (string) ($item['product_id'] ?? ''),
                'model_id' => (string) ($item['sku_id'] ?? data_get($item, 'sku.id', '')),
                'seller_sku' => (string) ($item['seller_sku'] ?? data_get($item, 'sku.seller_sku', '')),
                'image_url' => $item['sku_image'] ?? data_get($item, 'sku.image_url'),
            ];
        }

        return [
            'order_sn' => $order['id'] ?? $order['order_id'] ?? null,
            'order_status' => $order['status'] ?? $order['order_status'] ?? data_get($order, 'line_items.0.display_status'),
            'create_time' => isset($order['create_time']) ? Carbon::createFromTimestamp((int) $order['create_time'])->toDateTimeString() : null,
            'update_time' => isset($order['update_time']) ? Carbon::createFromTimestamp((int) $order['update_time'])->toDateTimeString() : null,
            'items' => $items,
        ];
    }

    private function applyOrderSyncFilters($query, array $filters): void
    {
        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }
        if (($filters['date'] ?? '') !== '') {
            $query->whereDate('created_at', $filters['date']);
        }
        if (($filters['type'] ?? '') !== '') {
            $query->where('source_marketplace', $filters['type']);
        }
    }

    public function safetySummary(): array
    {
        $lastRun = $this->lastSafetyRun();

        return [
            'last_run' => $lastRun,
            'next_run' => $this->nextSafetyRun(),
            'total_checked' => (int) DB::table('marketplace_sync_logs')
                ->where('source_marketplace', 'safety_check')
                ->whereDate('created_at', Carbon::today())
                ->count(),
            'total_corrected' => (int) DB::table('marketplace_sync_logs')
                ->where('source_marketplace', 'safety_check')
                ->where('status', 'success')
                ->whereDate('created_at', Carbon::today())
                ->count(),
        ];
    }

    public function processMarketplaceStockChange(string $sourceMarketplace, string $eventType, string $sku, int $qty, array $payload = []): array
    {
        $sourceMarketplace = strtolower($sourceMarketplace);
        $targetMarketplace = $sourceMarketplace === 'shopee' ? 'tiktok' : 'shopee';
        $mapping = $this->findSkuMapping($sku);

        if (! $mapping) {
            $this->logSync($sourceMarketplace, $targetMarketplace, $sku, null, null, 'error', 'SKU mapping tidak ditemukan.');
            return [
                'status' => 'error',
                'message' => 'SKU mapping tidak ditemukan.',
            ];
        }

        $oldStock = $this->currentStockForMarketplace($sourceMarketplace, $mapping) ?? (int) ($mapping->stock_qty ?? 0);
        $newStock = $this->resolveWebhookStock($payload, $oldStock, $qty);
        $canonicalSku = $this->canonicalSku($mapping, $sku);

        $this->updateLocalStock($mapping, $sourceMarketplace, $newStock);
        $this->updateLocalStock($mapping, $targetMarketplace, $newStock);
        $pushResult = $this->pushTargetStock($mapping, $targetMarketplace, $newStock);
        $this->updateStatus($sourceMarketplace, ['last_sync_at' => now(), 'status' => 'connected']);
        $this->updateStatus($targetMarketplace, ['last_sync_at' => now(), 'status' => 'connected']);

        $message = sprintf('%s %s diproses: stok %s -> %s. %s', strtoupper($sourceMarketplace), $eventType, $oldStock, $newStock, $pushResult['message']);
        $status = ($pushResult['status'] ?? '') === 'error' ? 'error' : 'success';
        $this->logSync($sourceMarketplace, $targetMarketplace, $canonicalSku, $oldStock, $newStock, $status, $message);

        return [
            'status' => $status,
            'message' => $message,
            'sku' => $canonicalSku,
            'old_stock' => $oldStock,
            'new_stock' => $newStock,
            'target_marketplace' => $targetMarketplace,
            'push' => $pushResult,
        ];
    }

    public function logWebhook(string $marketplace, string $eventType, ?string $sku, ?int $qty, array $payload, string $status, ?string $message = null): int
    {
        $id = DB::table('marketplace_webhook_logs')->insertGetId([
            'marketplace' => strtolower($marketplace),
            'event_type' => $eventType,
            'sku' => $sku,
            'qty' => $qty,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $status,
            'message' => $message,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->updateStatus($marketplace, ['last_webhook_at' => now(), 'status' => $status === 'error' ? 'disconnected' : 'connected']);

        return (int) $id;
    }

    public function logSync(?string $source, ?string $target, ?string $sku, ?int $oldStock, ?int $newStock, string $status, ?string $message = null): int
    {
        return (int) DB::table('marketplace_sync_logs')->insertGetId([
            'source_marketplace' => $source,
            'target_marketplace' => $target,
            'sku' => $sku,
            'old_stock' => $oldStock,
            'new_stock' => $newStock,
            'status' => $status,
            'message' => $message,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function findSkuMapping(string $sku): ?object
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        return DB::table('stock_master as sm')
            ->leftJoin('sku_mappings as map', 'map.stock_master_id', '=', 'sm.id')
            ->leftJoin('shopee_product_model as spm', function ($join): void {
                $join->on(DB::raw('CAST(spm.item_id AS TEXT)'), '=', 'sm.shopee_product_id')
                    ->on(DB::raw('CAST(spm.model_id AS TEXT)'), '=', 'sm.shopee_sku');
            })
            ->leftJoin('tiktok_products as tp', function ($join): void {
                $join->on('tp.product_id', '=', 'sm.tiktok_product_id')
                    ->on('tp.sku_id', '=', 'sm.tiktok_sku')
                    ->whereRaw('COALESCE(tp.is_active, true) = true');
            })
            ->whereRaw('COALESCE(sm.is_hidden_from_mapping, false) = false')
            ->where(function ($query) use ($sku): void {
                $query->where('sm.internal_sku', $sku)
                    ->orWhere('sm.shopee_seller_sku', $sku)
                    ->orWhere('sm.tiktok_seller_sku', $sku)
                    ->orWhere('sm.tiktok_sku', $sku)
                    ->orWhere('map.seller_sku', $sku)
                    ->orWhere('map.tiktok_sku_id', $sku)
                    ->orWhere('spm.model_sku', $sku)
                    ->orWhere('tp.seller_sku', $sku)
                    ->orWhere('tp.sku_id', $sku);
            })
            ->select(
                'sm.*',
                'map.seller_sku as mapped_seller_sku',
                'map.tiktok_product_id as mapped_tiktok_product_id',
                'map.tiktok_sku_id as mapped_tiktok_sku_id',
                'map.tiktok_sku_name as mapped_tiktok_sku_name',
                'spm.stock as shopee_stock',
                'spm.model_sku as shopee_model_sku',
                'tp.stock_qty as tiktok_stock',
                'tp.seller_sku as tiktok_product_seller_sku'
            )
            ->first();
    }

    public function activeSkuMappings()
    {
        return DB::table('stock_master as sm')
            ->leftJoin('sku_mappings as map', 'map.stock_master_id', '=', 'sm.id')
            ->leftJoin('shopee_product_model as spm', function ($join): void {
                $join->on(DB::raw('CAST(spm.item_id AS TEXT)'), '=', 'sm.shopee_product_id')
                    ->on(DB::raw('CAST(spm.model_id AS TEXT)'), '=', 'sm.shopee_sku');
            })
            ->leftJoin('tiktok_products as tp', function ($join): void {
                $join->on('tp.product_id', '=', 'sm.tiktok_product_id')
                    ->on('tp.sku_id', '=', 'sm.tiktok_sku')
                    ->whereRaw('COALESCE(tp.is_active, true) = true');
            })
            ->whereRaw('COALESCE(sm.is_hidden_from_mapping, false) = false')
            ->where(function ($query): void {
                $query->whereNotNull('sm.shopee_sku')
                    ->orWhereNotNull('sm.tiktok_sku')
                    ->orWhereNotNull('map.seller_sku');
            })
            ->select(
                'sm.*',
                'map.seller_sku as mapped_seller_sku',
                'map.tiktok_product_id as mapped_tiktok_product_id',
                'map.tiktok_sku_id as mapped_tiktok_sku_id',
                'map.tiktok_sku_name as mapped_tiktok_sku_name',
                'spm.stock as shopee_stock',
                'tp.stock_qty as tiktok_stock'
            )
            ->orderBy('sm.product_name')
            ->orderBy('sm.variant_name')
            ->get();
    }

    public function syncShopeeStocksToTiktok(bool $forceLive = false): array
    {
        $summary = [
            'status' => 'success',
            'message' => 'Sinkronisasi stok Shopee ke TikTok selesai.',
            'checked' => 0,
            'pushed' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'skipped_inactive_tiktok' => 0,
            'skipped_missing_shopee_stock' => 0,
            'skipped_missing_tiktok_sku' => 0,
            'failed' => 0,
            'live_push' => $forceLive || $this->livePushEnabled(),
        ];

        foreach ($this->activeSkuMappings() as $mapping) {
            $summary['checked']++;
            $sku = $this->canonicalSku($mapping);
            $shopeeStock = $mapping->shopee_stock === null ? null : (int) $mapping->shopee_stock;
            $tiktokStock = $mapping->tiktok_stock === null ? null : (int) $mapping->tiktok_stock;

            if ($shopeeStock === null) {
                $summary['skipped']++;
                $summary['skipped_missing_shopee_stock']++;
                $this->logSync('manual_shopee_master', 'tiktok', $sku, $tiktokStock, null, 'skipped', 'Dilewati: stok Shopee tidak tersedia di cache.');
                continue;
            }

            $tiktokSku = $this->resolveTiktokSku($mapping);
            if (! $tiktokSku) {
                $summary['skipped']++;
                $inactiveTiktokSku = $this->resolveTiktokSku($mapping, false);
                $message = $inactiveTiktokSku
                    ? 'Dilewati: SKU TikTok ditemukan tetapi statusnya nonaktif.'
                    : 'Dilewati: SKU TikTok aktif tidak ditemukan.';
                if ($inactiveTiktokSku) {
                    $summary['skipped_inactive_tiktok']++;
                } else {
                    $summary['skipped_missing_tiktok_sku']++;
                }
                $this->logSync('manual_shopee_master', 'tiktok', $sku, $tiktokStock, $shopeeStock, 'skipped', $message);
                continue;
            }
            $mapping->tiktok_product_id = (string) $tiktokSku->product_id;
            $mapping->tiktok_sku = (string) $tiktokSku->sku_id;
            $mapping->tiktok_stock = $tiktokSku->stock_qty === null ? $tiktokStock : (int) $tiktokSku->stock_qty;
            $tiktokStock = $mapping->tiktok_stock;

            if ($tiktokStock !== null && $tiktokStock === $shopeeStock) {
                $summary['unchanged']++;
                $this->logSync('manual_shopee_master', 'tiktok', $sku, $tiktokStock, $shopeeStock, 'success', 'Manual Shopee -> TikTok: stok sudah sama, tidak perlu push.');
                continue;
            }

            $pushResult = $this->pushTargetStock($mapping, 'tiktok', $shopeeStock, $forceLive);
            $status = ($pushResult['status'] ?? '') === 'error' ? 'error' : 'success';
            if ($status === 'error') {
                $summary['failed']++;
            } else {
                $summary['pushed']++;
                $this->updateLocalStock($mapping, 'tiktok', $shopeeStock);
            }

            $this->logSync(
                'manual_shopee_master',
                'tiktok',
                $sku,
                $tiktokStock,
                $shopeeStock,
                $status,
                sprintf('Manual Shopee -> TikTok: stok TikTok %s -> %s. %s', $tiktokStock ?? '-', $shopeeStock, $pushResult['message'] ?? '-')
            );
        }

        if ($summary['failed'] > 0) {
            $summary['status'] = 'warning';
            $summary['message'] = 'Sinkronisasi selesai, tetapi sebagian SKU gagal dipush ke TikTok.';
        }

        $this->updateStatus('shopee', ['last_sync_at' => now(), 'status' => 'connected']);
        $this->updateStatus('tiktok', ['last_sync_at' => now(), 'status' => $summary['failed'] > 0 ? 'disconnected' : 'connected']);

        return $summary;
    }

    public function mirrorShopeeStockToTiktok(object $mapping, string $reason, bool $forceLive = false, bool $allowCachedFallback = false): array
    {
        $sku = $this->canonicalSku($mapping);
        $itemId = trim((string) ($mapping->shopee_product_id ?? ''));
        $modelId = trim((string) ($mapping->shopee_sku ?? ''));
        if ($itemId === '' || $modelId === '') {
            $this->logSync('shopee_stock_refresh', 'tiktok', $sku, null, null, 'skipped', 'Mirror dilewati: item/model Shopee belum lengkap.');
            return ['status' => 'skipped', 'message' => 'item/model Shopee belum lengkap.', 'sku' => $sku];
        }

        $stockResult = $this->apiService->fetchShopeeModelStock($itemId, $modelId);
        if (($stockResult['status'] ?? '') !== 'success') {
            if (! $allowCachedFallback) {
                $this->logSync('shopee_stock_refresh', 'tiktok', $sku, null, null, 'error', $stockResult['message'] ?? 'Stok Shopee gagal diambil.');
                return ['status' => 'error', 'message' => $stockResult['message'] ?? 'Stok Shopee gagal diambil.', 'sku' => $sku];
            }

            $stockResult = [
                'status' => 'success',
                'stock' => (int) ($mapping->shopee_stock ?? $mapping->stock_qty ?? 0),
                'message' => 'Fallback stok lokal karena stok model Shopee live tidak tersedia.',
            ];
        }

        $oldStock = $mapping->tiktok_stock === null ? null : (int) $mapping->tiktok_stock;
        $newStock = (int) $stockResult['stock'];
        $this->updateLocalStock($mapping, 'shopee', $newStock);

        $pushResult = $this->pushTargetStock($mapping, 'tiktok', $newStock, $forceLive);
        $status = ($pushResult['status'] ?? '') === 'error' ? 'error' : 'success';
        if ($status === 'error' && str_contains((string) ($pushResult['message'] ?? ''), 'SKU TikTok aktif tidak ditemukan')) {
            $status = 'skipped';
        }
        if ($status === 'success') {
            $this->updateLocalStock($mapping, 'tiktok', $newStock);
        }

        $prefix = isset($stockResult['message']) ? $stockResult['message'].' ' : '';
        $message = sprintf('%s: %sstok Shopee terbaru %s, TikTok %s -> %s. %s', $reason, $prefix, $newStock, $oldStock ?? '-', $newStock, $pushResult['message'] ?? '-');
        $this->logSync('shopee_stock_refresh', 'tiktok', $sku, $oldStock, $newStock, $status, $message);
        $this->updateStatus('shopee', ['last_sync_at' => now(), 'status' => 'connected']);
        $this->updateStatus('tiktok', ['last_sync_at' => now(), 'status' => $status === 'error' ? 'disconnected' : 'connected']);

        return [
            'status' => $status,
            'message' => $message,
            'sku' => $sku,
            'old_stock' => $oldStock,
            'new_stock' => $newStock,
            'push' => $pushResult,
        ];
    }

    public function findSkuMappingByShopeeModel(string $itemId, string $modelId, bool $includeHidden = false): ?object
    {
        $itemId = trim($itemId);
        $modelId = trim($modelId);
        if ($itemId === '' || $modelId === '') {
            return null;
        }

        return DB::table('stock_master as sm')
            ->leftJoin('sku_mappings as map', 'map.stock_master_id', '=', 'sm.id')
            ->leftJoin('shopee_product_model as spm', function ($join): void {
                $join->on(DB::raw('CAST(spm.item_id AS TEXT)'), '=', 'sm.shopee_product_id')
                    ->on(DB::raw('CAST(spm.model_id AS TEXT)'), '=', 'sm.shopee_sku');
            })
            ->leftJoin('tiktok_products as tp', function ($join): void {
                $join->on('tp.product_id', '=', 'sm.tiktok_product_id')
                    ->on('tp.sku_id', '=', 'sm.tiktok_sku')
                    ->whereRaw('COALESCE(tp.is_active, true) = true');
            })
            ->when(! $includeHidden, function ($query): void {
                $query->whereRaw('COALESCE(sm.is_hidden_from_mapping, false) = false');
            })
            ->where(function ($query) use ($itemId, $modelId): void {
                $query->where(function ($inner) use ($itemId, $modelId): void {
                    $inner->where('sm.shopee_product_id', $itemId)->where('sm.shopee_sku', $modelId);
                })->orWhere(function ($inner) use ($itemId, $modelId): void {
                    $inner->where('map.shopee_item_id', $itemId)->where('map.shopee_model_id', $modelId);
                });
            })
            ->select(
                'sm.*',
                'map.seller_sku as mapped_seller_sku',
                'map.tiktok_product_id as mapped_tiktok_product_id',
                'map.tiktok_sku_id as mapped_tiktok_sku_id',
                'map.tiktok_sku_name as mapped_tiktok_sku_name',
                'spm.stock as shopee_stock',
                'tp.stock_qty as tiktok_stock'
            )
            ->first();
    }

    public function updateLocalStock(object $mapping, string $marketplace, int $stock): void
    {
        DB::table('stock_master')->where('id', (int) $mapping->id)->update([
            'stock_qty' => $stock,
            'updated_at' => now(),
        ]);

        if ($marketplace === 'shopee' && ($mapping->shopee_product_id ?? null) && ($mapping->shopee_sku ?? null)) {
            DB::table('shopee_product_model')
                ->where('item_id', $mapping->shopee_product_id)
                ->where('model_id', $mapping->shopee_sku)
                ->update(['stock' => $stock, 'updated_at' => now()]);
        }

        if ($marketplace === 'tiktok' && ($mapping->tiktok_product_id ?? null) && ($mapping->tiktok_sku ?? null)) {
            DB::table('tiktok_products')
                ->where('product_id', $mapping->tiktok_product_id)
                ->where('sku_id', $mapping->tiktok_sku)
                ->update(['stock_qty' => $stock, 'updated_at' => now()]);
        }
    }

    public function canonicalSku(object $mapping, ?string $fallback = null): string
    {
        return trim((string) (
            $mapping->internal_sku
            ?? $mapping->mapped_seller_sku
            ?? $mapping->shopee_seller_sku
            ?? $mapping->tiktok_seller_sku
            ?? $fallback
            ?? ''
        ));
    }

    public function currentStockForMarketplace(string $marketplace, object $mapping): ?int
    {
        if ($marketplace === 'shopee' && $mapping->shopee_stock !== null) {
            return (int) $mapping->shopee_stock;
        }

        if ($marketplace === 'tiktok' && $mapping->tiktok_stock !== null) {
            return (int) $mapping->tiktok_stock;
        }

        return null;
    }

    public function updateStatus(string $marketplace, array $values): void
    {
        $marketplace = strtolower($marketplace);
        $exists = DB::table('marketplace_sync_status')->where('marketplace', $marketplace)->exists();
        $payload = [
            ...$values,
            'updated_at' => now(),
        ];
        if (! $exists) {
            $payload['created_at'] = now();
        }

        DB::table('marketplace_sync_status')->updateOrInsert(['marketplace' => $marketplace], $payload);
    }

    public function pushTargetStock(object $mapping, string $targetMarketplace, int $stock, bool $forceLive = false): array
    {
        if (! $forceLive && ! $this->livePushEnabled()) {
            return [
                'status' => 'dry_run',
                'message' => 'Live push disabled (AUTO_SYNC_PUSH_LIVE=false); stok baru disimpan ke cache lokal.',
            ];
        }

        return $targetMarketplace === 'tiktok'
            ? $this->pushTiktokStock($mapping, $stock)
            : $this->pushShopeeStock($mapping, $stock);
    }

    private function marketplaceStatus(string $marketplace, Carbon $today): array
    {
        $row = DB::table('marketplace_sync_status')->where('marketplace', $marketplace)->first();

        return [
            'marketplace' => $marketplace,
            'status' => $row->status ?? 'disconnected',
            'connected' => ($row->status ?? '') === 'connected',
            'last_webhook_at' => $row->last_webhook_at ?? null,
            'last_sync_at' => $row->last_sync_at ?? null,
            'total_webhook_today' => (int) DB::table('marketplace_webhook_logs')
                ->where('marketplace', $marketplace)
                ->whereDate('created_at', $today)
                ->count(),
        ];
    }

    private function livePushEnabled(): bool
    {
        return filter_var(env('AUTO_SYNC_PUSH_LIVE', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function orderSyncSummary(Carbon $today): array
    {
        $lastOrderSync = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->max('created_at');
        $lastError = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->where('status', 'error')
            ->max('created_at');
        $latestIssue = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->whereIn('status', ['error', 'skipped'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        $openIssueQuery = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->whereIn('status', ['error', 'skipped']);
        if ($lastOrderSync) {
            $openIssueQuery->where('created_at', '>', $lastOrderSync);
        } else {
            $openIssueQuery->whereDate('created_at', $today);
        }
        $latestOpenIssue = (clone $openIssueQuery)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return [
            'status' => $lastError && (! $lastOrderSync || $lastError >= $lastOrderSync) ? 'warning' : 'active',
            'polling_interval' => 'Shopee + TikTok Every 5 Minutes',
            'last_order_sync_at' => $lastOrderSync ? (string) $lastOrderSync : null,
            'last_error_at' => $lastError ? (string) $lastError : null,
            'open_issues' => (int) (clone $openIssueQuery)->count(),
            'latest_open_issue_at' => $latestOpenIssue?->created_at ? (string) $latestOpenIssue->created_at : null,
            'latest_open_issue_status' => $latestOpenIssue?->status,
            'latest_open_issue_message' => $latestOpenIssue?->message,
            'errors_today' => (int) DB::table('marketplace_sync_logs')
                ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
                ->where('status', 'error')
                ->whereDate('created_at', $today)
                ->count(),
            'skipped_today' => (int) DB::table('marketplace_sync_logs')
                ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
                ->where('status', 'skipped')
                ->whereDate('created_at', $today)
                ->count(),
            'latest_issue_at' => $latestIssue?->created_at ? (string) $latestIssue->created_at : null,
            'latest_issue_status' => $latestIssue?->status,
            'latest_issue_message' => $latestIssue?->message,
            'shopee_orders_processed_today' => (int) DB::table('marketplace_sync_logs')
                ->where('source_marketplace', 'shopee_order')
                ->where('status', 'success')
                ->whereDate('created_at', $today)
                ->count(),
            'tiktok_orders_processed_today' => (int) DB::table('marketplace_sync_logs')
                ->where('source_marketplace', 'tiktok_order')
                ->where('status', 'success')
                ->whereDate('created_at', $today)
                ->count(),
            'stock_pushes_today' => (int) DB::table('marketplace_sync_logs')
                ->where('source_marketplace', 'shopee_stock_refresh')
                ->where('status', 'success')
                ->whereDate('created_at', $today)
                ->count(),
            'tiktok_to_shopee_pushes_today' => (int) DB::table('marketplace_sync_logs')
                ->where('source_marketplace', 'tiktok_order')
                ->where('target_marketplace', 'shopee')
                ->where('status', 'success')
                ->whereDate('created_at', $today)
                ->count(),
        ];
    }

    private function pushShopeeStock(object $mapping, int $stock): array
    {
        $itemId = trim((string) ($mapping->shopee_product_id ?? ''));
        $modelId = trim((string) ($mapping->shopee_sku ?? ''));
        if ($itemId === '' || $modelId === '') {
            return ['status' => 'error', 'message' => 'Push Shopee gagal: item_id/model_id belum lengkap.'];
        }
        $modelExists = DB::table('shopee_product_model')
            ->where('item_id', $itemId)
            ->where('model_id', $modelId)
            ->exists();
        if (! $modelExists) {
            return ['status' => 'error', 'message' => 'Push Shopee dibatalkan: model Shopee aktif tidak ditemukan di cache.'];
        }

        $token = DB::table('shopee_tokens')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->orderByDesc('created_at')
            ->first();
        if (! $token || trim((string) $token->access_token) === '' || (int) $token->shop_id <= 0) {
            return ['status' => 'error', 'message' => 'Push Shopee gagal: token aktif belum tersedia.'];
        }

        $payload = [
            'item_id' => (int) $itemId,
            'stock_list' => [
                [
                    'model_id' => (int) $modelId,
                    'seller_stock' => [
                        ['stock' => $stock],
                    ],
                ],
            ],
        ];
        $response = $this->shopeeSignedPost('/api/v2/product/update_stock', (int) $token->shop_id, (string) $token->access_token, $payload);
        if (($response['error'] ?? '') !== '') {
            return [
                'status' => 'error',
                'message' => $response['message'] ?? $response['error'] ?? 'Push Shopee gagal.',
                'response' => $response,
            ];
        }

        return ['status' => 'success', 'message' => 'Live push Shopee berhasil dikirim.', 'response' => $response];
    }

    private function pushTiktokStock(object $mapping, int $stock): array
    {
        $productId = trim((string) ($mapping->tiktok_product_id ?? ''));
        $skuId = trim((string) ($mapping->tiktok_sku ?? ''));
        $warehouseId = trim((string) env('TIKTOK_DEFAULT_WAREHOUSE_ID', ''));
        if ($warehouseId === '') {
            return ['status' => 'error', 'message' => 'Push TikTok gagal: warehouse_id belum lengkap.'];
        }
        $activeSku = $this->resolveTiktokSku($mapping);
        if (! $activeSku) {
            return ['status' => 'error', 'message' => 'Push TikTok dibatalkan: SKU TikTok aktif tidak ditemukan di cache.'];
        }
        $productId = (string) $activeSku->product_id;
        $skuId = (string) $activeSku->sku_id;

        $token = DB::table('tiktok_tokens')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->orderByDesc('created_at')
            ->first();
        $shop = DB::table('tiktok_shops')->orderByDesc('updated_at')->first();
        if (! $token || trim((string) $token->access_token) === '') {
            return ['status' => 'error', 'message' => 'Push TikTok gagal: token aktif belum tersedia.'];
        }
        $shopCipher = trim((string) ($shop->cipher ?? $shop->shop_cipher ?? ''));
        if ($shopCipher === '') {
            return ['status' => 'error', 'message' => 'Push TikTok gagal: shop_cipher belum tersedia.'];
        }

        $config = config('tiktok');
        $path = '/product/202309/products/'.$productId.'/inventory/update';
        $query = [
            'app_key' => $config['app_key'],
            'access_token' => (string) $token->access_token,
            'shop_cipher' => $shopCipher,
            'timestamp' => time(),
        ];
        $body = [
            'skus' => [
                [
                    'id' => $skuId,
                    'inventory' => [
                        [
                            'warehouse_id' => $warehouseId,
                            'quantity' => $stock,
                        ],
                    ],
                ],
            ],
        ];
        $bodyString = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $query['sign'] = $this->generateTiktokSign($path, $query, (string) $config['app_secret'], $bodyString);

        $response = Http::timeout(45)
            ->withHeaders([
                'x-tts-access-token' => (string) $token->access_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->withBody($bodyString, 'application/json')
            ->post($config['api_host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $payload = $response->json();

        if (! is_array($payload) || (int) ($payload['code'] ?? -1) !== 0) {
            return [
                'status' => 'error',
                'message' => is_array($payload) ? ($payload['message'] ?? 'Push TikTok gagal.') : 'TikTok tidak mengembalikan JSON valid.',
                'http_status' => $response->status(),
            ];
        }

        return ['status' => 'success', 'message' => 'Live push TikTok berhasil dikirim.'];
    }

    private function resolveTiktokSku(object $mapping, bool $activeOnly = true): ?object
    {
        $activeCondition = $activeOnly ? 'COALESCE(is_active, true) = true' : 'COALESCE(is_active, true) = false';
        $productId = trim((string) ($mapping->tiktok_product_id ?? $mapping->mapped_tiktok_product_id ?? ''));
        $skuId = trim((string) ($mapping->tiktok_sku ?? $mapping->mapped_tiktok_sku_id ?? ''));
        if ($productId !== '' && $skuId !== '') {
            $activeSku = DB::table('tiktok_products')
                ->where('product_id', $productId)
                ->where('sku_id', $skuId)
                ->whereRaw($activeCondition)
                ->first();
            if ($activeSku) {
                return $activeSku;
            }
        }

        $sellerSkus = array_values(array_unique(array_filter(array_map(
            fn ($value): string => trim((string) $value),
            [
                $mapping->internal_sku ?? null,
                $mapping->mapped_seller_sku ?? null,
                $mapping->tiktok_seller_sku ?? null,
                $mapping->shopee_seller_sku ?? null,
            ]
        ))));
        if ($sellerSkus !== []) {
            $activeSku = DB::table('tiktok_products')
                ->whereIn('seller_sku', $sellerSkus)
                ->whereRaw($activeCondition)
                ->orderByDesc('updated_at')
                ->first();
            if ($activeSku) {
                return $activeSku;
            }
        }

        $productName = $this->normalizeSkuMatchValue((string) ($mapping->product_name ?? ''));
        $variantName = $this->normalizeSkuMatchValue((string) ($mapping->variant_name ?? $mapping->mapped_tiktok_sku_name ?? ''));
        if ($productName === '' || $variantName === '') {
            return null;
        }

        return DB::table('tiktok_products')
            ->whereRaw($activeCondition)
            ->whereRaw('LOWER(product_name) = ?', [mb_strtolower((string) ($mapping->product_name ?? ''))])
            ->get()
            ->first(function ($row) use ($variantName): bool {
                return $this->normalizeSkuMatchValue((string) ($row->sku_name ?? '')) === $variantName;
            });
    }

    private function normalizeSkuMatchValue(mixed $value): string
    {
        $value = strtolower(trim((string) ($value ?? '')));
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function generateTiktokWriteSign(string $path, array $params, string $secret): string
    {
        unset($params['sign'], $params['access_token']);
        ksort($params);

        $base = $secret.$path;
        foreach ($params as $key => $value) {
            $base .= $key.$value;
        }
        $base .= $secret;

        return hash_hmac('sha256', $base, $secret);
    }

    private function generateTiktokSign(string $path, array $params, string $secret, ?string $body = null): string
    {
        unset($params['sign'], $params['access_token']);
        ksort($params);

        $base = $secret.$path;
        foreach ($params as $key => $value) {
            $base .= $key.$value;
        }
        $base .= $body ?? '';
        $base .= $secret;

        return hash_hmac('sha256', $base, $secret);
    }

    private function shopeeSignedPost(string $path, int $shopId, string $accessToken, array $payload): array
    {
        $config = config('shopee');
        $timestamp = time();
        $query = [
            'partner_id' => (int) $config['partner_id'],
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $this->generateShopeeApiSign((int) $config['partner_id'], (string) $config['partner_key'], $path, $timestamp, $accessToken, $shopId),
        ];

        $response = Http::timeout(45)
            ->acceptJson()
            ->post($config['host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), $payload);
        $data = $response->json();

        if (! is_array($data)) {
            return [
                'error' => 'invalid_json',
                'message' => 'Shopee tidak mengembalikan JSON valid.',
                '_http_status' => $response->status(),
                '_body' => $response->body(),
            ];
        }

        return [
            ...$data,
            '_http_status' => $response->status(),
        ];
    }

    private function generateShopeeApiSign(int $partnerId, string $partnerKey, string $path, int $timestamp, string $accessToken, int $shopId): string
    {
        return hash_hmac('sha256', $partnerId.$path.$timestamp.$accessToken.$shopId, $partnerKey);
    }

    private function resolveWebhookStock(array $payload, int $oldStock, int $qty): int
    {
        foreach (['new_stock', 'stock', 'stock_qty', 'available_stock'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return max(0, (int) $payload[$key]);
            }
        }

        return max(0, $oldStock - max(0, $qty));
    }

    private function lastSafetyRun(): ?string
    {
        $last = DB::table('marketplace_sync_logs')
            ->where('source_marketplace', 'safety_check')
            ->max('created_at');

        return $last ? (string) $last : null;
    }

    private function nextSafetyRun(): string
    {
        $now = now();
        $minutesToAdd = 15 - ((int) $now->format('i') % 15);
        if ($minutesToAdd === 15 && (int) $now->format('s') === 0) {
            $minutesToAdd = 0;
        }

        return $now->copy()->addMinutes($minutesToAdd)->setSecond(0)->toDateTimeString();
    }

    private function paginateQuery($query, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $total = (clone $query)->count();
        $items = $query->forPage($page, $perPage)->get();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }
}
