<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MarketplaceSyncService
{
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
                    ->orWhere('map.seller_sku', $sku)
                    ->orWhere('spm.model_sku', $sku)
                    ->orWhere('tp.seller_sku', $sku);
            })
            ->select(
                'sm.*',
                'map.seller_sku as mapped_seller_sku',
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
                'spm.stock as shopee_stock',
                'tp.stock_qty as tiktok_stock'
            )
            ->orderBy('sm.product_name')
            ->orderBy('sm.variant_name')
            ->get();
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

    public function pushTargetStock(object $mapping, string $targetMarketplace, int $stock): array
    {
        if (! $this->livePushEnabled()) {
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
        if ($productId === '' || $skuId === '' || $warehouseId === '') {
            return ['status' => 'error', 'message' => 'Push TikTok gagal: product_id/sku_id/warehouse_id belum lengkap.'];
        }
        $activeSku = DB::table('tiktok_products')
            ->where('product_id', $productId)
            ->where('sku_id', $skuId)
            ->whereRaw('COALESCE(is_active, true) = true')
            ->first();
        if (! $activeSku) {
            $variantName = trim((string) ($mapping->variant_name ?? ''));
            $activeSku = DB::table('tiktok_products')
                ->where('product_id', $productId)
                ->whereRaw('LOWER(sku_name) = ?', [mb_strtolower($variantName)])
                ->whereRaw('COALESCE(is_active, true) = true')
                ->orderByDesc('updated_at')
                ->first();
            if ($activeSku) {
                $skuId = (string) $activeSku->sku_id;
            }
        }
        if (! $activeSku) {
            return ['status' => 'error', 'message' => 'Push TikTok dibatalkan: SKU TikTok aktif tidak ditemukan di cache.'];
        }

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
