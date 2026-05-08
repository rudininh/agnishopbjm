<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class OmnichannelController extends Controller
{
    private const MARKETPLACE_ACCOUNTS = [
        'shopee-agnishopbjm' => [
            'channel' => 'shopee',
            'name' => 'Shopee AgniShopBJM',
        ],
        'shopee-gitacollectionbjm' => [
            'channel' => 'shopee',
            'name' => 'Shopee GitaCollectionBJM',
        ],
        'tiktok-agnishopbjm' => [
            'channel' => 'tiktok',
            'name' => 'TikTok AgniShopBJM',
        ],
    ];
    private const SHOPEE_ACCESS_TOKEN_REFRESH_BUFFER_MINUTES = 15;
    private const TIKTOK_ACCESS_TOKEN_REFRESH_BUFFER_MINUTES = 15;
    private const SHOPEE_REFRESH_TOKEN_VALID_DAYS = 365;

    public function dashboard(): JsonResponse
    {
        $this->ensureShopeeAuthColumns();
        $this->normalizeActiveMarketplaceTokens();

        return response()->json([
            'summary' => [
                'stock_master' => $this->tableCount('stock_master'),
                'shopee_products' => $this->tableCount('shopee_product'),
                'shopee_variants' => $this->tableCount('shopee_product_model'),
                'tiktok_products' => Schema::hasTable('tiktok_products')
                    ? DB::table('tiktok_products')->whereRaw('COALESCE(is_active, true) = true')->distinct('product_id')->count('product_id')
                    : 0,
                'tiktok_skus' => Schema::hasTable('tiktok_products')
                    ? DB::table('tiktok_products')->whereRaw('COALESCE(is_active, true) = true')->count()
                    : 0,
                'sku_mappings' => $this->tableCount('sku_mapping'),
                'shopee_tokens' => $this->tableCount('shopee_tokens'),
                'tiktok_tokens' => $this->tableCount('tiktok_tokens'),
            ],
            'tokens' => [
                'shopee' => $this->latestShopeeTokens()[0] ?? null,
                'tiktok' => $this->latestTokenPreview('tiktok_tokens'),
            ],
            'token_rows' => [
                'shopee' => $this->latestShopeeTokens(),
                'tiktok' => $this->latestTiktokTokens(),
            ],
            'database' => $this->databaseInfo(),
        ]);
    }

    public function shopeeItems(Request $request): JsonResponse
    {
        $this->ensureShopeeProductTables();

        $syncResult = null;

        if ($request->boolean('sync')) {
            $syncResult = $this->syncShopeeProductsToDatabase();
        }

        return $this->shopeeItemsResponse($syncResult);
    }

    private function shopeeItemsResponse(?array $syncResult = null): JsonResponse
    {
        $shopNames = $this->shopeeShopNames();
        $products = DB::table('shopee_product')
            ->select(
                'item_id',
                'shop_id',
                'name',
                'stock',
                'price_min',
                'price_max',
                'sold',
                'liked_count',
                'rating',
                'status',
                'create_time',
                'update_time',
                'updated_at'
            )
            ->orderBy('name')
            ->get();

        $models = DB::table('shopee_product_model')
            ->select('item_id', 'model_id', 'name', 'price', 'stock', 'updated_at')
            ->orderBy('name')
            ->get()
            ->groupBy('item_id');

        $productImages = DB::table('shopee_product_image')
            ->select('item_id', 'image_url', 'created_at', 'id')
            ->whereNotNull('image_url')
            ->whereNull('model_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('item_id')
            ->map(fn ($rows) => $rows->first()->image_url);

        $modelImages = DB::table('shopee_product_image')
            ->select('item_id', 'model_id', 'image_url', 'created_at', 'id')
            ->whereNotNull('image_url')
            ->whereNotNull('model_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('item_id')
            ->map(fn ($rows) => $rows->groupBy('model_id')->map(fn ($modelRows) => $modelRows->first()->image_url));

        $lastSyncAt = DB::table('shopee_sync_logs')->latest('synced_at')->value('synced_at')
            ?: DB::table('shopee_product')->max('updated_at');

        return response()->json([
            'status' => $syncResult['status'] ?? 'ok',
            'message' => $syncResult['message'] ?? ($lastSyncAt ? 'Data Shopee dari cache database.' : 'Belum ada cache produk Shopee. Klik Sinkronkan Produk.'),
            'count' => $products->count(),
            'last_sync_at' => $lastSyncAt,
            'sync' => [
                'status' => $syncResult['status'] ?? 'cached',
                'message' => $syncResult['message'] ?? ($lastSyncAt ? 'Terakhir sinkron: '.$lastSyncAt : 'Belum pernah sinkron.'),
                'last_sync_at' => $lastSyncAt,
                ...($syncResult ?? []),
            ],
            'items' => $products->map(fn ($item, int $index) => [
                'no' => $index + 1,
                'item_id' => (string) $item->item_id,
                'shop_id' => $item->shop_id ? (string) $item->shop_id : null,
                'shop_name' => $shopNames[(string) $item->shop_id] ?? 'Shopee',
                'image_url' => $productImages[$item->item_id] ?? null,
                'nama' => $item->name,
                'sku' => (string) $item->item_id,
                'stok' => (int) ($item->stock ?? 0),
                'price_min' => (int) ($item->price_min ?? 0),
                'price_max' => (int) ($item->price_max ?? 0),
                'harga' => $this->formatRupiah((int) ($item->price_min ?? $item->price_max ?? 0)),
                'sales' => (int) ($item->sold ?? 0),
                'likes' => (int) ($item->liked_count ?? 0),
                'rating' => (float) ($item->rating ?? 0),
                'status' => $item->status,
                'is_live' => $this->isLiveShopeeStatus($item->status),
                'created_at' => $item->create_time,
                'updated_at' => $item->update_time ?: $item->updated_at,
                'models' => ($models[$item->item_id] ?? collect())->map(fn ($model) => [
                    'model_id' => (string) $model->model_id,
                    'name' => $model->name,
                    'price' => (int) ($model->price ?? 0),
                    'stock' => (int) ($model->stock ?? 0),
                    'image_url' => $modelImages[$item->item_id][$model->model_id] ?? null,
                    'fallback_image_url' => $productImages[$item->item_id] ?? null,
                    'updated_at' => $model->updated_at,
                ])->values(),
            ])->values(),
        ]);
    }

    private function syncShopeeProductsToDatabase(): array
    {
        $this->ensureShopeeAuthColumns();
        $this->normalizeActiveMarketplaceTokens();

        if (! Schema::hasTable('shopee_tokens')) {
            return [
                'status' => 'error',
                'message' => 'Tabel token Shopee belum tersedia.',
                'accounts' => [],
            ];
        }

        $tokens = $this->activeShopeeTokensForSync();

        if ($tokens->isEmpty()) {
            return [
                'status' => 'error',
                'message' => 'Belum ada token Shopee aktif. Jalankan AUTH / REFRESH Shopee dari dashboard dulu.',
                'accounts' => [],
            ];
        }

        $accounts = [];
        $productCount = 0;
        $variantCount = 0;
        $config = $this->shopeeConfig();

        foreach ($tokens as $token) {
            $shopId = (int) $token->shop_id;
            $accessToken = (string) $token->access_token;
            $accountName = $token->account_name ?: 'Shopee';

            try {
                $itemIds = $this->fetchShopeeItemIds($config, $shopId, $accessToken);
                $baseItems = [];

                foreach (array_chunk($itemIds, 50) as $chunk) {
                    $baseItems = array_merge($baseItems, $this->fetchShopeeBaseInfo($config, $shopId, $accessToken, $chunk));
                }

                foreach ($baseItems as $baseItem) {
                    $modelPayload = $this->fetchShopeeModelList($config, $shopId, $accessToken, (int) ($baseItem['item_id'] ?? 0));
                    $models = data_get($modelPayload, 'model', []);
                    $tierVariations = data_get($modelPayload, 'tier_variation', []);
                    $variantCount += max(1, count($models));
                    $this->storeShopeeProductPayload($baseItem, $models, $tierVariations, $shopId);
                }

                $productCount += count($baseItems);
                $accounts[] = [
                    'status' => 'ok',
                    'account_key' => $token->account_key,
                    'account_name' => $accountName,
                    'shop_id' => (string) $shopId,
                    'products' => count($baseItems),
                ];
            } catch (\Throwable $exception) {
                $accounts[] = [
                    'status' => 'error',
                    'account_key' => $token->account_key,
                    'account_name' => $accountName,
                    'shop_id' => (string) $shopId,
                    'products' => 0,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $hasError = collect($accounts)->contains(fn ($account) => ($account['status'] ?? '') === 'error');
        $message = $productCount.' produk Shopee dan '.$variantCount.' varian berhasil disinkronkan ke database.';

        if ($hasError && $productCount === 0) {
            $message = collect($accounts)->firstWhere('status', 'error')['message'] ?? 'Gagal mengambil data Shopee.';
        }

        if ($productCount > 0) {
            DB::table('shopee_sync_logs')->insert([
                'status' => $hasError ? 'partial' : 'ok',
                'message' => $message,
                'product_count' => $productCount,
                'variant_count' => $variantCount,
                'synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'status' => $hasError ? ($productCount ? 'partial' : 'error') : 'ok',
            'message' => $message,
            'products' => $productCount,
            'variants' => $variantCount,
            'accounts' => $accounts,
            'last_sync_at' => now()->toDateTimeString(),
        ];
    }

    private function activeShopeeTokensForSync()
    {
        $tokens = DB::table('shopee_tokens')
            ->whereRaw('is_active = true')
            ->whereNotNull('shop_id')
            ->whereNotNull('access_token')
            ->orderBy('account_name')
            ->get();

        foreach ($tokens as $token) {
            if (! $this->shopeeAccessTokenNeedsRefresh($token)) {
                continue;
            }

            $account = $this->resolveAccount((string) ($token->account_key ?: 'shopee-agnishopbjm'), 'shopee');
            $this->refreshShopeeToken($account);
        }

        return DB::table('shopee_tokens')
            ->whereRaw('is_active = true')
            ->whereNotNull('shop_id')
            ->whereNotNull('access_token')
            ->orderBy('account_name')
            ->get()
            ->reject(fn ($token) => $this->shopeeAccessTokenIsExpired($token))
            ->values();
    }

    private function fetchShopeeItemIds(array $config, int $shopId, string $accessToken): array
    {
        $ids = [];
        $offset = 0;
        $pageSize = 100;
        $statuses = ['NORMAL', 'UNLIST'];

        foreach ($statuses as $status) {
            $offset = 0;

            do {
                $response = $this->shopeeSignedGet($config, '/api/v2/product/get_item_list', $shopId, $accessToken, [
                    'offset' => $offset,
                    'page_size' => $pageSize,
                    'item_status' => $status,
                ]);

                $items = data_get($response, 'response.item', []);
                foreach ($items as $item) {
                    if (! empty($item['item_id'])) {
                        $ids[(string) $item['item_id']] = (int) $item['item_id'];
                    }
                }

                $hasNextPage = (bool) data_get($response, 'response.has_next_page', false);
                $offset = (int) data_get($response, 'response.next_offset', $offset + $pageSize);
            } while ($hasNextPage);
        }

        return array_values($ids);
    }

    private function fetchShopeeBaseInfo(array $config, int $shopId, string $accessToken, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $response = $this->shopeeSignedGet($config, '/api/v2/product/get_item_base_info', $shopId, $accessToken, [
            'item_id_list' => implode(',', $itemIds),
            'need_tax_info' => 'false',
            'need_complaint_policy' => 'false',
        ]);

        return data_get($response, 'response.item_list', []);
    }

    private function fetchShopeeModelList(array $config, int $shopId, string $accessToken, int $itemId): array
    {
        if ($itemId <= 0) {
            return [];
        }

        $response = $this->shopeeSignedGet($config, '/api/v2/product/get_model_list', $shopId, $accessToken, [
            'item_id' => $itemId,
        ]);

        return data_get($response, 'response', []);
    }

    private function shopeeSignedGet(array $config, string $path, int $shopId, string $accessToken, array $params = []): array
    {
        $timestamp = time();
        $query = [
            'partner_id' => $config['partner_id'],
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $this->generateShopeeApiSign($config['partner_id'], $config['partner_key'], $path, $timestamp, $accessToken, $shopId),
            ...$params,
        ];

        $response = Http::timeout(45)->acceptJson()->get($config['host'].$path, $query);
        $data = $response->json();

        if (! is_array($data)) {
            throw new \RuntimeException('Shopee tidak mengembalikan JSON valid untuk '.$path.'.');
        }

        if (($data['error'] ?? '') !== '') {
            throw new \RuntimeException(($data['message'] ?? $data['error']).' ['.$path.']');
        }

        return $data;
    }

    private function storeShopeeProductPayload(array $item, array $models, array $tierVariations, int $shopId): void
    {
        $itemId = (int) ($item['item_id'] ?? 0);

        if ($itemId <= 0) {
            return;
        }

        $priceMin = $this->shopeePrice($this->shopeePriceInfoValue($item['price_info'] ?? null, 'current_price', $item['price_min'] ?? 0));
        $priceMax = $this->shopeePrice($this->shopeePriceInfoValue($item['price_info'] ?? null, 'original_price', $item['price_max'] ?? $priceMin));
        $stock = $this->shopeeStock($item);
        $now = now();

        DB::table('shopee_product')->updateOrInsert(
            ['item_id' => $itemId],
            [
                'shop_id' => $shopId,
                'name' => $item['item_name'] ?? '',
                'description' => $item['description'] ?? null,
                'category_id' => $this->toInt($item['category_id'] ?? null),
                'price_min' => $priceMin,
                'price_max' => max($priceMin, $priceMax),
                'price_before_discount' => $this->shopeePrice($item['price_before_discount'] ?? null),
                'currency' => $item['currency'] ?? null,
                'stock' => $stock,
                'sold' => $this->toInt($item['sold'] ?? null),
                'liked_count' => $this->toInt($item['liked_count'] ?? null),
                'rating' => (float) ($item['rating_star'] ?? 0),
                'historical_sold' => $this->toInt($item['historical_sold'] ?? null),
                'status' => $item['item_status'] ?? null,
                'create_time' => $this->timestampToDate($item['create_time'] ?? null) ?? $now,
                'update_time' => $this->timestampToDate($item['update_time'] ?? null) ?? $now,
                'is_active' => DB::raw('true'),
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $this->storeShopeeImages($itemId, null, data_get($item, 'image.image_url_list', []));

        if ($models === []) {
            $models = [[
                'model_id' => 0,
                'model_name' => 'Tanpa Varian',
                'model_sku' => $item['item_sku'] ?? '',
                'price_info' => [['current_price' => $priceMin]],
                'stock' => $stock,
            ]];
        }

        foreach ($models as $model) {
            $this->storeShopeeModelPayload($itemId, (string) ($item['item_name'] ?? ''), $model);
            $this->storeShopeeImages($itemId, (string) ($model['model_id'] ?? '0'), $this->shopeeModelImageUrls($model, $tierVariations));
        }
    }

    private function storeShopeeModelPayload(int $itemId, string $itemName, array $model): void
    {
        $modelId = (string) ($model['model_id'] ?? '0');
        $modelName = (string) ($model['model_name'] ?? $model['name'] ?? 'Tanpa Varian');
        $modelSku = (string) ($model['model_sku'] ?? '');
        $price = $this->shopeePrice($this->shopeePriceInfoValue($model['price_info'] ?? null, 'current_price', $model['price'] ?? 0));
        $stock = $this->shopeeModelStock($model);
        $now = now();

        DB::table('shopee_product_model')->updateOrInsert(
            ['model_id' => $modelId, 'item_id' => $itemId],
            [
                'name' => $modelName,
                'price' => $price,
                'stock' => $stock,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $skuFragment = $modelSku !== '' ? $modelSku : $modelName;
        $internalSku = 'INT-'.$itemId.'-'.$this->sanitizeSkuFragment($skuFragment);

        DB::table('stock_master')->updateOrInsert(
            ['internal_sku' => $internalSku],
            [
                'shopee_product_id' => (string) $itemId,
                'shopee_sku' => $modelId,
                'shopee_seller_sku' => $modelSku !== '' ? $modelSku : null,
                'product_name' => $itemName,
                'variant_name' => $modelName,
                'stock_qty' => $stock,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    private function storeShopeeImages(int $itemId, ?string $modelId, array $urls): void
    {
        foreach ($urls as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            $cachedUrl = $this->cacheMarketplaceImageUrl($url, 'shopee', (string) $itemId, (string) ($modelId ?? 'product'));

            if (! DB::table('shopee_product_image')->where('item_id', $itemId)->where('model_id', $modelId)->where('image_url', $cachedUrl)->exists()) {
                DB::table('shopee_product_image')->insert([
                    'item_id' => $itemId,
                    'model_id' => $modelId,
                    'image_url' => $cachedUrl,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function shopeeModelImageUrls(array $model, array $tierVariations): array
    {
        return $this->imageUrlsFromCandidates([
            data_get($model, 'image'),
            data_get($model, 'image_id'),
            data_get($model, 'image_url'),
            data_get($model, 'image_info.image_id'),
            data_get($model, 'image_info.image_url'),
            data_get($model, 'image_info.image_url_list'),
            data_get($model, 'image_info.image.url'),
            data_get($model, 'image_info.image.urls'),
            data_get($model, 'image.image_id'),
            data_get($model, 'image.image_url'),
            data_get($model, 'image.image_url_list'),
            ...$this->shopeeTierVariationImageCandidates($model, $tierVariations),
        ]);
    }

    private function shopeeTierVariationImageCandidates(array $model, array $tierVariations): array
    {
        $tierIndexes = $this->normalizeShopeeIndexList(data_get($model, 'tier_index', []));

        if (! is_array($tierIndexes) || ! is_array($tierVariations)) {
            return [];
        }

        $candidates = [];

        foreach ($tierIndexes as $tierPosition => $optionIndex) {
            if (! is_numeric($optionIndex)) {
                continue;
            }

            $option = data_get($tierVariations, $tierPosition.'.option_list.'.((int) $optionIndex), []);

            if (! is_array($option)) {
                continue;
            }

            $candidates[] = data_get($option, 'image');
            $candidates[] = data_get($option, 'image.image_id');
            $candidates[] = data_get($option, 'image.image_url');
            $candidates[] = data_get($option, 'image.image_url_list');
            $candidates[] = data_get($option, 'image.url');
            $candidates[] = data_get($option, 'image.urls');
            $candidates[] = data_get($option, 'image_id');
            $candidates[] = data_get($option, 'image_url');
            $candidates[] = data_get($option, 'image_url_list');
        }

        return $candidates;
    }

    private function normalizeShopeeIndexList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, fn ($item) => $item !== null && $item !== ''));
        }

        if (is_string($value)) {
            $trimmed = trim($value, "[] \t\n\r\0\x0B");

            if ($trimmed === '') {
                return [];
            }

            return preg_split('/[\s,]+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (is_numeric($value)) {
            return [(string) $value];
        }

        return [];
    }

    private function imageUrlsFromCandidates(array $candidates): array
    {
        $urls = [];

        $collect = function (mixed $value) use (&$collect, &$urls): void {
            if (is_string($value)) {
                $trimmed = trim($value);

                if ($trimmed !== '' && $this->isImageUrl($trimmed)) {
                    $urls[] = $trimmed;
                }

                return;
            }

            if (! is_array($value)) {
                return;
            }

            foreach ($value as $key => $child) {
                if (is_string($key) && in_array($key, ['url', 'uri', 'image_url', 'thumb_url'], true) && is_string($child)) {
                    $trimmed = trim($child);

                    if ($trimmed !== '' && $this->isImageUrl($trimmed)) {
                        $urls[] = $trimmed;
                    }
                }

                if (is_string($key) && in_array($key, ['image_id', 'image_id_list'], true)) {
                    foreach ($this->imageIdCandidates($child) as $imageId) {
                        $resolved = $this->resolveShopeeImageId($imageId);

                        if ($resolved) {
                            $urls[] = $resolved;
                        }
                    }
                }

                $collect($child);
            }
        };

        foreach ($candidates as $candidate) {
            $collect($candidate);
        }

        return array_values(array_unique($urls));
    }

    private function imageIdCandidates(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? [$trimmed] : [];
        }

        if (is_array($value)) {
            $values = [];

            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $values[] = trim($item);
                }
            }

            return $values;
        }

        return [];
    }

    private function resolveShopeeImageId(string $imageId): ?string
    {
        if ($imageId === '' || $this->isImageUrl($imageId)) {
            return $this->isImageUrl($imageId) ? $imageId : null;
        }

        $normalized = ltrim($imageId, '/');

        if ($normalized === '') {
            return null;
        }

        return 'https://down-id.img.susercontent.com/file/'.$normalized;
    }

    private function isImageUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            || str_starts_with($value, '//');
    }

    private function isLiveShopeeStatus(?string $status): bool
    {
        $normalized = strtoupper(trim((string) $status));

        return $normalized === '' || in_array($normalized, ['NORMAL', 'LIVE', 'PUBLISHED', 'ACTIVE'], true);
    }

    public function tokenAction(string $action): JsonResponse
    {
        $this->ensureShopeeAuthColumns();
        $this->normalizeActiveMarketplaceTokens();

        $account = $this->resolveAccountFromAction($action);

        if ($account && str_starts_with($action, 'connect-shopee')) {
            $result = $this->connectShopee($account);

            return response()->json($this->maskShopeeTokenPayload($result), ($result['status'] ?? '') === 'error' ? 422 : 200);
        }

        if ($account && str_starts_with($action, 'connect-tiktok')) {
            $result = $this->connectTiktok($account);

            return response()->json($this->maskTiktokTokenPayload($result), ($result['status'] ?? '') === 'error' ? 422 : 200);
        }

        if ($account && str_starts_with($action, 'auth-shopee')) {
            return response()->json([
                'status' => 'redirect',
                'action' => $action,
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Membuka halaman authorization '.$account['name'].'.',
                'redirect_url' => $this->buildShopeeAuthUrl($account),
            ]);
        }

        if ($account && str_starts_with($action, 'get-token-shopee')) {
            $callback = DB::table('shopee_callbacks')
                ->where('account_key', $account['key'])
                ->whereNull('used_at')
                ->latest('created_at')
                ->first();

            if (! $callback) {
                return response()->json([
                    'status' => 'error',
                    'action' => $action,
                    'account_key' => $account['key'],
                    'account_name' => $account['name'],
                    'message' => 'Belum ada callback '.$account['name'].' yang bisa ditukar menjadi token. Klik AUTH dulu.',
                ], 422);
            }

            return response()->json($this->maskShopeeTokenPayload($this->exchangeShopeeToken($callback)));
        }

        if ($account && str_starts_with($action, 'refresh-token-shopee')) {
            $result = $this->refreshShopeeToken($account);

            return response()->json($this->maskShopeeTokenPayload($result), ($result['status'] ?? '') === 'ok' ? 200 : 422);
        }

        if ($account && str_starts_with($action, 'auth-tiktok')) {
            return response()->json([
                'status' => 'redirect',
                'action' => $action,
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Membuka halaman authorization '.$account['name'].'.',
                'redirect_url' => $this->buildTiktokAuthUrl($account),
            ]);
        }

        if ($account && str_starts_with($action, 'get-token-tiktok')) {
            $result = $this->exchangeTiktokToken($account);

            return response()->json($this->maskTiktokTokenPayload($result), ($result['status'] ?? '') === 'ok' ? 200 : 422);
        }

        if ($account && str_starts_with($action, 'refresh-token-tiktok')) {
            $result = $this->refreshTiktokToken($account);

            return response()->json($this->maskTiktokTokenPayload($result), ($result['status'] ?? '') === 'ok' ? 200 : 422);
        }

        if ($account && str_starts_with($action, 'get-auth-shop-tiktok')) {
            $result = $this->getTiktokAuthorizedShops($account);

            return response()->json($result, ($result['status'] ?? '') === 'ok' ? 200 : 422);
        }

        return response()->json([
            'status' => 'error',
            'action' => $action,
            'account_key' => $account['key'] ?? null,
            'account_name' => $account['name'] ?? null,
            'message' => 'Aksi marketplace tidak dikenali.',
        ], 422);
    }

    public function shopeeCallback(Request $request): Response
    {
        $this->ensureShopeeAuthColumns();

        $code = $request->query('code');
        $account = $this->resolveAccount((string) $request->query('account', 'shopee-agnishopbjm'), 'shopee');

        if (! $code) {
            return response('Callback Shopee tidak membawa code.', 422);
        }

        $callbackId = DB::table('shopee_callbacks')->insertGetId([
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'code' => $code,
            'shop_id' => $request->query('shop_id') ? (int) $request->query('shop_id') : null,
            'main_account_id' => $request->query('main_account_id') ? (int) $request->query('main_account_id') : null,
            'partner_id' => $this->shopeeConfig()['partner_id'],
            'query_payload' => json_encode($request->query()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $callback = DB::table('shopee_callbacks')->where('id', $callbackId)->first();
        $result = $this->exchangeShopeeToken($callback);
        $ok = ($result['error'] ?? '') === '' && ! empty($result['access_token']);

        $title = $ok ? 'Token Shopee berhasil disimpan' : 'Token Shopee gagal diproses';
        $message = $ok
            ? 'Authorization berhasil. Kamu bisa kembali ke dashboard.'
            : ($result['message'] ?? 'Shopee mengembalikan error.');

        return response($this->renderShopeeCallbackPage($title, $message, $result), $ok ? 200 : 422)
            ->header('Content-Type', 'text/html');
    }

    public function tiktokCallback(Request $request): Response
    {
        $code = $request->query('code');
        $account = $this->resolveAccount((string) $request->query('state', 'tiktok-agnishopbjm'), 'tiktok');

        if (! $code) {
            return response('Callback TikTok tidak membawa code.', 422);
        }

        $this->ensureTiktokAuthTables();

        DB::table('tiktok_callbacks')->insert([
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'code' => $code,
            'app_key' => $request->query('app_key'),
            'shop_region' => $request->query('shop_region'),
            'state' => $request->query('state'),
            'query_payload' => json_encode($request->query()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [
            'Status' => 'ok',
            'Akun' => $account['name'],
            'App Key' => $request->query('app_key', '-'),
            'Shop Region' => $request->query('shop_region', '-'),
            'Code' => $this->maskToken((string) $code),
        ];

        $tableRows = collect($rows)->map(fn ($value, string $label) => '<tr><th>'.e($label).'</th><td>'.e((string) ($value ?: '-')).'</td></tr>')->implode('');

        return response('<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Callback TikTok tersimpan</title><style>body{font-family:Arial,sans-serif;padding:32px;line-height:1.5;color:#0f172a}h1{margin-bottom:12px}table{border-collapse:collapse;width:100%;margin:18px 0;background:#fff}th,td{border:1px solid #d9e2ec;padding:10px 12px;text-align:left}th{width:180px;background:#f8fafc}a{color:#0f5fc7}</style></head><body><h1>Callback TikTok tersimpan</h1><p>Authorization berhasil. Kembali ke dashboard lalu klik GET TOKEN.</p><table>'.$tableRows.'</table><p><a href="/dashboard">Kembali ke Dashboard</a></p></body></html>')
            ->header('Content-Type', 'text/html');
    }

    public function tiktokItems(): JsonResponse
    {
        $this->ensureTiktokProductTables();

        $syncResult = null;

        if (request()->boolean('sync')) {
            $syncResult = $this->syncTiktokProductsToDatabase();
        }

        $rows = DB::table('tiktok_products')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->select('product_id', 'product_name', 'image_url', 'sku_id', 'sku_name', 'stock_qty', 'price', 'subtotal', 'updated_at')
            ->orderBy('product_name')
            ->orderBy('sku_name')
            ->get()
            ->groupBy('product_id');

        $lastSyncAt = Schema::hasTable('tiktok_products')
            ? DB::table('tiktok_products')->whereRaw('COALESCE(is_active, true) = true')->max('updated_at')
            : null;

        return response()->json([
            'count' => $rows->count(),
            'last_sync_at' => $lastSyncAt,
            'message' => $syncResult['message'] ?? ($lastSyncAt ? 'Data TikTok dari cache database.' : 'Belum ada cache produk TikTok.'),
            'sync' => [
                'status' => $syncResult['status'] ?? ($lastSyncAt ? 'cached' : 'empty'),
                'message' => $syncResult['message'] ?? ($lastSyncAt ? 'Terakhir sinkron: '.$lastSyncAt : 'Belum pernah sinkron.'),
                'last_sync_at' => $lastSyncAt,
                ...($syncResult ?? []),
                'mode' => 'cache',
            ],
            'items' => $rows->map(function ($group, string $productId) {
                $first = $group->first();

                return [
                'product_id' => $productId,
                'product_name' => $first->product_name,
                'image_url' => $first->image_url ?? null,
                'updated_at' => $first->updated_at,
                'skus' => $group->map(fn ($sku) => [
                        'sku_id' => $sku->sku_id ?? null,
                        'sku_name' => $sku->sku_name,
                        'stock_qty' => (int) ($sku->stock_qty ?? 0),
                        'price' => (int) ($sku->price ?? 0),
                        'subtotal' => (int) ($sku->subtotal ?? 0),
                        'image_url' => $sku->image_url ?? null,
                    ])->values(),
                ];
            })->values(),
        ]);
    }

    private function syncTiktokProductsToDatabase(): array
    {
        $this->ensureTiktokProductTables();

        try {
            $config = $this->tiktokConfig();
            $shop = $this->latestTiktokShop();
            $accessToken = $this->activeTiktokAccessTokenForSync();

            if (! $shop) {
                return [
                    'status' => 'error',
                    'message' => 'Belum ada shop TikTok tersimpan. Jalankan AUTH / GET SHOP dulu.',
                    'products' => 0,
                    'variants' => 0,
                    'debug' => [
                        'shop' => null,
                        'access_token_present' => $accessToken !== '',
                        'app_key' => $config['app_key'] ?? null,
                    ],
                ];
            }

            if ($accessToken === '') {
                return [
                    'status' => 'error',
                    'message' => 'Belum ada access token TikTok aktif. Jalankan AUTH / GET TOKEN dulu.',
                    'products' => 0,
                    'variants' => 0,
                    'debug' => [
                        'shop_id' => (string) ($shop->shop_id ?? $shop->id ?? ''),
                        'shop_cipher' => (string) ($shop->cipher ?? $shop->shop_cipher ?? ''),
                        'app_key' => $config['app_key'] ?? null,
                    ],
                ];
            }

            $syncCount = 0;
            $variantCount = 0;
            $pageSize = 100;
            $pageToken = null;
            $apiHost = rtrim((string) $config['api_host'], '/');
            $searchUrl = $apiHost.'/product/202502/products/search';
            $detailBaseUrl = $apiHost.'/product/202309/products/';

            do {
                $timestamp = time();
                $shopCipher = (string) ($shop->cipher ?? $shop->shop_cipher ?? '');
                if ($shopCipher === '') {
                    return [
                        'status' => 'error',
                        'message' => 'Shop cipher TikTok belum tersimpan. Jalankan GET AUTH SHOP dulu.',
                        'products' => 0,
                        'variants' => 0,
                        'debug' => [
                            'shop' => $shop,
                            'shop_cipher' => $shopCipher,
                            'access_token_present' => $accessToken !== '',
                            'app_key' => $config['app_key'] ?? null,
                        ],
                    ];
                }
                $searchParams = [
                    'app_key' => $config['app_key'],
                    'timestamp' => $timestamp,
                    'shop_cipher' => $shopCipher,
                    'page_size' => $pageSize,
                ];
                if ($pageToken) {
                    $searchParams['page_token'] = $pageToken;
                }
                $searchBody = [
                    'status' => 'ALL',
                ];
                $searchBodyString = json_encode($searchBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $searchParams['sign'] = $this->generateTiktokSign(
                    '/product/202502/products/search',
                    $searchParams,
                    $config['app_secret'],
                    $searchBodyString
                );

                $searchResponse = Http::timeout(45)
                    ->asJson()
                    ->withHeaders(['x-tts-access-token' => $accessToken])
                    ->withOptions(['query' => $searchParams])
                    ->post($searchUrl, $searchBody);

                $payload = $searchResponse->json();

                if (! is_array($payload)) {
                    return [
                        'status' => 'error',
                        'message' => 'TikTok search API tidak mengembalikan JSON valid.',
                        'products' => 0,
                        'variants' => 0,
                        'debug' => [
                            'url' => $searchUrl,
                            'query' => $searchParams,
                            'request_body' => $searchBody,
                            'http_status' => $searchResponse->status(),
                            'response_body' => $searchResponse->body(),
                            'shop_id' => (string) ($shop->shop_id ?? $shop->id ?? ''),
                            'shop_cipher' => (string) ($shop->cipher ?? $shop->shop_cipher ?? ''),
                            'app_key' => $config['app_key'] ?? null,
                            'curl' => $this->buildTiktokCurl('POST', $searchUrl, $searchParams, [
                                'x-tts-access-token' => $accessToken,
                                'content-type' => 'application/json',
                            ], $searchBody),
                        ],
                    ];
                }

                if ((int) ($payload['code'] ?? -1) !== 0) {
                    return [
                        'status' => 'error',
                        'message' => $payload['message'] ?? 'TikTok search API error.',
                        'products' => 0,
                        'variants' => 0,
                        'debug' => [
                            'url' => $searchUrl,
                            'query' => $searchParams,
                            'request_body' => $searchBody,
                            'response' => $payload,
                            'shop_id' => (string) ($shop->shop_id ?? $shop->id ?? ''),
                            'shop_cipher' => (string) ($shop->cipher ?? $shop->shop_cipher ?? ''),
                            'app_key' => $config['app_key'] ?? null,
                            'curl' => $this->buildTiktokCurl('POST', $searchUrl, $searchParams, [
                                'x-tts-access-token' => $accessToken,
                                'content-type' => 'application/json',
                            ], $searchBody),
                        ],
                    ];
                }

                $products = data_get($payload, 'data.products', []);
                $pageToken = data_get($payload, 'data.next_page_token');
                if (! is_array($products) || $products === []) {
                    break;
                }

                foreach ($products as $product) {
                    $productId = (string) ($product['id'] ?? '');
                    if ($productId === '') {
                        continue;
                    }

                    $detail = $this->fetchTiktokProductDetail($config, $accessToken, $shop, $productId, $detailBaseUrl);
                    $this->storeTiktokProductPayload($detail ?: $product);
                    $syncCount++;
                    $variantCount += count(data_get($detail ?: $product, 'skus', []));
                }

            } while ($pageToken);

            DB::table('tiktok_sync_logs')->insert([
                'status' => 'ok',
                'message' => $syncCount.' produk TikTok berhasil disinkronkan.',
                'product_count' => $syncCount,
                'variant_count' => $variantCount,
                'synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'status' => 'ok',
                'message' => $syncCount.' produk TikTok berhasil disinkronkan.',
                'products' => $syncCount,
                'variants' => $variantCount,
                'last_sync_at' => now()->toDateTimeString(),
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'message' => $exception->getMessage(),
                'products' => 0,
                'variants' => 0,
                'debug' => [
                    'shop_id' => isset($shop) ? (string) ($shop->shop_id ?? $shop->id ?? '') : null,
                    'shop_cipher' => isset($shop) ? (string) ($shop->cipher ?? $shop->shop_cipher ?? '') : null,
                    'app_key' => $config['app_key'] ?? null,
                    'curl' => isset($searchUrl, $searchParams, $accessToken)
                            ? $this->buildTiktokCurl('POST', $searchUrl, $searchParams, [
                            'x-tts-access-token' => $accessToken,
                            'content-type' => 'application/json',
                        ], $searchBody ?? ['status' => 'ALL'])
                        : null,
                ],
            ];
        }
    }

    private function fetchTiktokProductDetail(array $config, string $accessToken, ?object $shop, string $productId, string $detailBaseUrl): ?array
    {
        $shopCipher = (string) ($shop->cipher ?? $shop->shop_cipher ?? '');
        $shopId = (string) ($shop->shop_id ?? $shop->id ?? '');
        $timestamp = time();
        $params = [
            'app_key' => $config['app_key'],
            'shop_cipher' => $shopCipher,
            'shop_id' => $shopId,
            'timestamp' => $timestamp,
            'version' => '202309',
        ];
        $params['sign'] = $this->generateTiktokSign('/product/202309/products/'.$productId, $params, $config['app_secret']);

        $response = Http::timeout(45)
            ->withHeaders([
                'x-tts-access-token' => $accessToken,
                'content-type' => 'application/json',
            ])
            ->get($detailBaseUrl.$productId, $params);

        $payload = $response->json();

        if (! is_array($payload) || (int) ($payload['code'] ?? -1) !== 0) {
            logger()->warning('TikTok detail request failed', [
                'product_id' => $productId,
                'url' => $detailBaseUrl.$productId,
                'params' => $params,
                'http_status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);
        }

        return is_array($payload) ? data_get($payload, 'data') : null;
    }

    private function buildTiktokCurl(string $method, string $url, array $query, array $headers = [], ?array $body = null): string
    {
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $fullUrl = $queryString ? $url.'?'.$queryString : $url;
        $parts = [
            "curl -k -X '".strtoupper($method)."'",
        ];

        foreach ($headers as $name => $value) {
            $parts[] = "-H '".str_replace("'", "'\"'\"'", $name.': '.$value)."'";
        }

        $parts[] = "'".str_replace("'", "'\"'\"'", $fullUrl)."'";

        if ($body !== null && strtoupper($method) !== 'GET') {
            $parts[] = "-d '".str_replace("'", "'\"'\"'", json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))."'";
        }

        return implode(' ', $parts);
    }

    private function storeTiktokProductPayload(array $data): void
    {
        $productId = (string) ($data['id'] ?? $data['product_id'] ?? '');
        if ($productId === '') {
            return;
        }

        $productName = (string) ($data['title'] ?? $data['product_name'] ?? 'TikTok Product');
        $imageUrl = $this->cacheMarketplaceImageUrl($this->extractTiktokImageUrl($data), 'tiktok', $productId, 'product');
        $skus = $this->normalizeTiktokSkuList($data);
        $statusInfo = $this->tiktokPayloadStatusInfo($data);

        if (! is_array($skus) || $skus === []) {
            $skus = [[
                'id' => $productId.'-default',
                'sku_name' => 'Default',
                'stock' => data_get($data, 'stock', 0),
                'price' => ['sale_price' => data_get($data, 'price', 0)],
            ]];
        }

        foreach ($skus as $sku) {
            $skuId = (string) ($sku['id'] ?? $sku['sku_id'] ?? $sku['sku_no'] ?? $sku['sku_code'] ?? '');
            $skuName = $this->deriveTiktokSkuName($sku);
            $sellerSku = $this->extractTiktokSellerSku($sku);
            $price = (int) data_get($sku, 'price.sale_price', data_get($sku, 'price', 0));
            $stock = (int) data_get($sku, 'inventory.0.quantity', data_get($sku, 'stock', 0));
            $skuImageUrl = $this->cacheMarketplaceImageUrl($this->extractTiktokSkuImageUrl($sku), 'tiktok', $productId, $skuId !== '' ? $skuId : $skuName);

            DB::table('tiktok_products')->updateOrInsert(
                ['product_id' => $productId, 'sku_id' => $skuId !== '' ? $skuId : $skuName],
                [
                    'product_name' => $productName,
                    'sku_id' => $skuId !== '' ? $skuId : null,
                    'image_url' => $skuImageUrl,
                    'sku_name' => $skuName,
                    'seller_sku' => $sellerSku,
                    'stock_qty' => $stock,
                    'price' => $price,
                    'subtotal' => $price * $stock,
                    'product_status' => $statusInfo['product_status'],
                    'audit_status' => $statusInfo['audit_status'],
                    'is_active' => DB::raw($statusInfo['is_active'] ? 'true' : 'false'),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    private function extractTiktokImageUrl(array $data): ?string
    {
        $mainImages = $this->normalizeTiktokImageCandidates(data_get($data, 'main_images', []));

        if (is_array($mainImages)) {
            foreach ($mainImages as $image) {
                $url = $this->extractTiktokImageNodeUrl($image);
                if ($url) {
                    return $url;
                }
            }
        }

        $skus = $this->normalizeTiktokSkuList($data);

        if (is_array($skus)) {
            foreach ($skus as $sku) {
                $skuImage = $this->extractTiktokSkuImageUrl($sku);
                if ($skuImage) {
                    return $skuImage;
                }
            }
        }

        return null;
    }

    private function tiktokPayloadStatusInfo(array $data): array
    {
        $candidates = [
            data_get($data, 'status'),
            data_get($data, 'product_status'),
            data_get($data, 'audit_status'),
            data_get($data, 'display_status'),
            data_get($data, 'status_text'),
            data_get($data, 'listing_status'),
        ];

        $normalized = collect($candidates)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->first();

        if ($normalized === null) {
            $normalized = 'LIVE';
        }

        $inactiveNeedles = ['DELETE', 'DELETED', 'DEACTIVATED', 'INACTIVE', 'DRAFT', 'FREEZE', 'FROZEN', 'REJECT', 'REMOVED', 'PENDING', 'SELLER_DEACTIVATED', 'PLATFORM_DEACTIVATED'];
        $activeNeedles = ['LIVE', 'ACTIVE', 'NORMAL', 'PUBLISHED', 'APPROVED'];

        foreach ($inactiveNeedles as $needle) {
            if (str_contains($normalized, $needle)) {
                return [
                    'product_status' => data_get($data, 'product_status', $normalized),
                    'audit_status' => data_get($data, 'audit_status', null),
                    'is_active' => false,
                ];
            }
        }

        foreach ($activeNeedles as $needle) {
            if (str_contains($normalized, $needle)) {
                return [
                    'product_status' => data_get($data, 'product_status', $normalized),
                    'audit_status' => data_get($data, 'audit_status', null),
                    'is_active' => true,
                ];
            }
        }

        return [
            'product_status' => data_get($data, 'product_status', $normalized),
            'audit_status' => data_get($data, 'audit_status', null),
            'is_active' => true,
        ];
    }

    private function extractTiktokSellerSku(array $sku): ?string
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

    private function extractTiktokSkuImageUrl(array $sku): ?string
    {
        $candidates = [
            data_get($sku, 'sku_img'),
            data_get($sku, 'sku_image'),
            data_get($sku, 'sku_image_url'),
            data_get($sku, 'image'),
            data_get($sku, 'image_url'),
            data_get($sku, 'image_urls'),
            data_get($sku, 'images.0'),
            data_get($sku, 'image_list.0'),
            data_get($sku, 'image_list'),
            data_get($sku, 'sales_attributes.0.sku_img'),
            data_get($sku, 'sales_attributes.0.image'),
            data_get($sku, 'sales_attributes.1.sku_img'),
            data_get($sku, 'sales_attributes.1.image'),
            data_get($sku, 'sales_attributes.0.image_url'),
            data_get($sku, 'sales_attributes.1.image_url'),
            data_get($sku, 'sku_img_list'),
            data_get($sku, 'sku_image_list'),
        ];

        $salesAttributes = data_get($sku, 'sales_attributes', []);
        if (is_array($salesAttributes)) {
            foreach ($salesAttributes as $attribute) {
                $candidates[] = data_get($attribute, 'sku_img');
                $candidates[] = data_get($attribute, 'image');
            }
        }

        foreach ($candidates as $candidate) {
            $url = $this->extractTiktokImageNodeUrl($candidate);
            if ($url) {
                return $url;
            }
        }

        return null;
    }

    private function cacheMarketplaceImageUrl(?string $sourceUrl, string $channel, string $scope, string $variant = 'image'): ?string
    {
        if (! is_string($sourceUrl)) {
            return null;
        }

        $sourceUrl = trim($sourceUrl);
        if ($sourceUrl === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $sourceUrl)) {
            return $sourceUrl;
        }

        $cacheDir = storage_path('app/public/marketplace-images/'.$channel);
        if (! is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        $hash = sha1($channel.'|'.$scope.'|'.$variant.'|'.$sourceUrl);
        $extension = $this->guessImageExtensionFromUrl($sourceUrl);
        $fileName = $hash.$extension;
        $absolutePath = $cacheDir.DIRECTORY_SEPARATOR.$fileName;

        if (! is_file($absolutePath)) {
            try {
                $response = Http::timeout(30)
                    ->retry(2, 250)
                    ->accept('image/*')
                    ->get($sourceUrl);

                if ($response->successful()) {
                    $body = $response->body();
                    if (is_string($body) && $body !== '') {
                        $contentType = strtolower((string) $response->header('Content-Type', ''));
                        if ($extension === '' || $extension === '.bin') {
                            $extension = $this->guessImageExtensionFromContentType($contentType);
                            $fileName = $hash.$extension;
                            $absolutePath = $cacheDir.DIRECTORY_SEPARATOR.$fileName;
                        }

                        file_put_contents($absolutePath, $body);
                    }
                }
            } catch (\Throwable) {
                return $sourceUrl;
            }
        }

        if (is_file($absolutePath)) {
            return '/cached-images/marketplace-images/'.$channel.'/'.$fileName;
        }

        return $sourceUrl;
    }

    private function guessImageExtensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return '.jpg';
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($extension) {
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'avif' => '.'.$extension,
            default => '.jpg',
        };
    }

    private function guessImageExtensionFromContentType(string $contentType): string
    {
        return match (true) {
            str_contains($contentType, 'png') => '.png',
            str_contains($contentType, 'webp') => '.webp',
            str_contains($contentType, 'gif') => '.gif',
            str_contains($contentType, 'bmp') => '.bmp',
            str_contains($contentType, 'avif') => '.avif',
            default => '.jpg',
        };
    }

    private function deriveTiktokSkuName(array $sku): string
    {
        foreach ([
            data_get($sku, 'sku_name'),
            data_get($sku, 'name'),
            data_get($sku, 'sku_title'),
            data_get($sku, 'seller_sku'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $trimmed = trim($candidate);
                if (strtolower($trimmed) !== 'default') {
                    return $trimmed;
                }
            }
        }

        $salesAttributes = data_get($sku, 'sales_attributes', []);
        if (is_array($salesAttributes) && $salesAttributes !== []) {
            $parts = [];

            foreach ($salesAttributes as $attribute) {
                foreach ([
                    data_get($attribute, 'value_name'),
                    data_get($attribute, 'original_value_name'),
                    data_get($attribute, 'name'),
                ] as $candidate) {
                    if (is_string($candidate) && trim($candidate) !== '') {
                        $parts[] = trim($candidate);
                        break;
                    }
                }
            }

            $parts = array_values(array_filter(array_unique($parts), fn ($value) => $value !== ''));
            if ($parts !== []) {
                return implode(' / ', $parts);
            }
        }

        return 'Default';
    }

    private function normalizeTiktokSkuList(array $data): array
    {
        $candidates = [
            data_get($data, 'skus', []),
            data_get($data, 'sku_list', []),
            data_get($data, 'sku_info_list', []),
            data_get($data, 'sku_infos', []),
            data_get($data, 'skus_info', []),
            data_get($data, 'variants', []),
            data_get($data, 'model_list', []),
            data_get($data, 'product_skus', []),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    private function normalizeTiktokImageCandidates(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }

        return [];
    }

    private function extractTiktokImageNodeUrl(mixed $image): ?string
    {
        if (is_string($image) && trim($image) !== '') {
            return trim($image);
        }

        if (! is_array($image)) {
            return null;
        }

        foreach (['urls', 'thumb_urls', 'url_list', 'image_url_list', 'image_urls'] as $key) {
            $urls = data_get($image, $key, []);
            if (is_array($urls) && ! empty($urls[0])) {
                return (string) $urls[0];
            }
        }

        foreach (['url', 'uri', 'image_url', 'thumb_url', 'image_id'] as $key) {
            $url = data_get($image, $key);
            if (is_string($url) && trim($url) !== '') {
                $trimmed = trim($url);

                if ($key === 'image_id' && ! $this->isImageUrl($trimmed)) {
                    return $this->resolveTiktokImageId($trimmed);
                }

                return $trimmed;
            }
        }

        return null;
    }

    private function resolveTiktokImageId(string $imageId): ?string
    {
        if ($imageId === '' || $this->isImageUrl($imageId)) {
            return $this->isImageUrl($imageId) ? $imageId : null;
        }

        return 'https://p16-tiktokcdn-com.akamaized.net/obj/'.$imageId;
    }

    private function latestTiktokAccessToken(): string
    {
        return (string) (DB::table('tiktok_tokens')->whereRaw('is_active = true')->orderByDesc('created_at')->value('access_token') ?? DB::table('tiktok_tokens')->orderByDesc('created_at')->value('access_token') ?? '');
    }

    private function activeTiktokAccessTokenForSync(): string
    {
        $account = $this->resolveAccount('tiktok-agnishopbjm', 'tiktok');
        $token = $this->latestActiveTiktokToken($account);

        if (! $token) {
            return $this->latestTiktokAccessToken();
        }

        if ($this->tiktokAccessTokenNeedsRefresh($token) && $this->tiktokRefreshTokenIsUsable($token)) {
            $this->refreshTiktokToken($account);
            $token = $this->latestActiveTiktokToken($account);
        }

        if (! $token || $this->tiktokAccessTokenIsExpired($token)) {
            return '';
        }

        return (string) ($token->access_token ?? '');
    }

    private function ensureTiktokProductTables(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS tiktok_products (
                id BIGSERIAL PRIMARY KEY,
                product_id TEXT NOT NULL,
                product_name TEXT NULL,
                image_url TEXT NULL,
                sku_name TEXT NULL,
                seller_sku TEXT NULL,
                stock_qty INTEGER DEFAULT 0,
                price BIGINT DEFAULT 0,
                subtotal BIGINT DEFAULT 0,
                product_status TEXT NULL,
                audit_status TEXT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                updated_at TIMESTAMP DEFAULT NOW(),
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("ALTER TABLE tiktok_products ADD COLUMN IF NOT EXISTS sku_id TEXT NULL");
        DB::statement("ALTER TABLE tiktok_products ADD COLUMN IF NOT EXISTS seller_sku TEXT NULL");
        DB::statement("ALTER TABLE tiktok_products ADD COLUMN IF NOT EXISTS product_status TEXT NULL");
        DB::statement("ALTER TABLE tiktok_products ADD COLUMN IF NOT EXISTS audit_status TEXT NULL");
        DB::statement("ALTER TABLE tiktok_products ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT TRUE");

        DB::statement("
            CREATE TABLE IF NOT EXISTS tiktok_sync_logs (
                id BIGSERIAL PRIMARY KEY,
                status TEXT NULL,
                message TEXT NULL,
                product_count INTEGER DEFAULT 0,
                variant_count INTEGER DEFAULT 0,
                synced_at TIMESTAMP DEFAULT NOW(),
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");
    }

    private function ensureSkuMappingTables(): void
    {
        $this->ensureShopeeProductTables();
        $this->ensureTiktokProductTables();
        $this->ensureSkuVariantActionTables();

        DB::statement("
            CREATE TABLE IF NOT EXISTS sku_mappings (
                id BIGSERIAL PRIMARY KEY,
                stock_master_id BIGINT NOT NULL UNIQUE,
                shopee_item_id TEXT NULL,
                shopee_model_id TEXT NULL,
                tiktok_product_id TEXT NULL,
                tiktok_sku_id TEXT NULL,
                tiktok_sku_name TEXT NULL,
                seller_sku TEXT NULL,
                internal_image_url TEXT NULL,
                shopee_image_url TEXT NULL,
                tiktok_image_url TEXT NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("CREATE INDEX IF NOT EXISTS sku_mappings_stock_master_id_idx ON sku_mappings (stock_master_id)");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS shopee_product_id TEXT NULL");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS shopee_sku TEXT NULL");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS shopee_seller_sku TEXT NULL");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS tiktok_product_id TEXT NULL");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS tiktok_sku TEXT NULL");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS tiktok_seller_sku TEXT NULL");
        DB::statement("ALTER TABLE sku_mappings ADD COLUMN IF NOT EXISTS seller_sku TEXT NULL");
    }

    private function ensureSkuVariantActionTables(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS sku_variant_actions (
                id BIGSERIAL PRIMARY KEY,
                stock_master_id BIGINT NOT NULL,
                target_channel TEXT NOT NULL,
                source_channel TEXT NULL,
                action_type TEXT NOT NULL,
                payload JSONB NULL,
                status TEXT NOT NULL DEFAULT 'ready_to_create',
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("CREATE INDEX IF NOT EXISTS sku_variant_actions_stock_master_id_idx ON sku_variant_actions (stock_master_id)");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS sku_variant_actions_unique_idx ON sku_variant_actions (stock_master_id, target_channel, action_type)");
    }

    public function skuMapping(Request $request): JsonResponse
    {
        $this->ensureSkuMappingTables();

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');
        $sort = (string) $request->query('sort', 'updated_desc');

        $query = DB::table('stock_master as sm')
            ->leftJoin('sku_mappings as map', 'map.stock_master_id', '=', 'sm.id')
            ->leftJoin('shopee_product_model as spm', function ($join) {
                $join->on('spm.model_id', '=', DB::raw("COALESCE(NULLIF(map.shopee_model_id, ''), NULLIF(sm.shopee_sku, ''))"));
            })
            ->leftJoin('shopee_product as sp', function ($join) {
                $join->on('sp.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"));
            })
            ->leftJoin(DB::raw('(SELECT item_id, model_id, MIN(image_url) as image_url FROM shopee_product_image WHERE model_id IS NOT NULL GROUP BY item_id, model_id) as spmi'), function ($join) {
                $join->on('spmi.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"))
                    ->on('spmi.model_id', '=', DB::raw("COALESCE(NULLIF(map.shopee_model_id, ''), NULLIF(sm.shopee_sku, ''))"));
            })
            ->leftJoin(DB::raw('(SELECT item_id, MIN(image_url) as image_url FROM shopee_product_image WHERE model_id IS NULL GROUP BY item_id) as spi'), function ($join) {
                $join->on('spi.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"));
            })
            ->leftJoin(DB::raw("(
                SELECT DISTINCT ON (stock_master_id)
                    stock_master_id,
                    target_channel,
                    source_channel,
                    action_type,
                    status AS variant_action_status,
                    payload AS variant_action_payload,
                    created_at,
                    updated_at
                FROM sku_variant_actions
                ORDER BY stock_master_id, created_at DESC, id DESC
            ) as sva"), function ($join) {
                $join->on('sva.stock_master_id', '=', 'sm.id');
            })
            ->select(
            'sm.id',
            'sm.internal_sku',
            'sm.shopee_product_id as stock_shopee_item_id',
            'sm.shopee_sku as stock_shopee_model_id',
            'sm.shopee_seller_sku as stock_shopee_seller_sku',
            'sm.product_name',
            'sm.variant_name',
            'sm.stock_qty',
            'sm.tiktok_product_id as stock_tiktok_product_id',
            'sm.tiktok_sku as stock_tiktok_sku_id',
            'sm.tiktok_seller_sku as stock_tiktok_seller_sku',
            'sm.updated_at',
            'map.id as mapping_id',
            'map.shopee_item_id',
            'map.shopee_model_id',
            'map.tiktok_product_id as mapped_tiktok_product_id',
            'map.tiktok_sku_id',
            'map.tiktok_sku_name',
            'map.seller_sku as mapped_seller_sku',
            'map.internal_image_url',
            'map.shopee_image_url',
            'map.tiktok_image_url',
            'map.notes',
            'sva.target_channel as variant_action_target_channel',
            'sva.source_channel as variant_action_source_channel',
            'sva.action_type as variant_action_type',
            'sva.variant_action_status',
            'sva.variant_action_payload',
            'sp.name as shopee_name',
            'spm.name as shopee_variant_name',
            'spm.stock as shopee_variant_stock',
            'spmi.image_url as shopee_model_image_url',
            'spi.image_url as shopee_product_image_url'
        );

        $rows = $query->get();
        $tiktokLookup = $this->tiktokSkuMappingLookup();
        $tiktokProductGroups = $tiktokLookup['product_groups'];
        $stockGroupTiktokMatches = $this->suggestTiktokProductsForStockGroups($rows, $tiktokProductGroups);
        $matchedTiktokToStockGroup = [];
        foreach ($stockGroupTiktokMatches as $stockGroupKey => $productId) {
            if ($productId !== null && $productId !== '' && ! isset($matchedTiktokToStockGroup[$productId])) {
                $matchedTiktokToStockGroup[$productId] = $stockGroupKey;
            }
        }
        $matchedTiktokVariantKeys = [];
        $items = [];

        foreach ($rows as $row) {
            $shopeeItemId = $row->shopee_item_id ?: $row->stock_shopee_item_id;
            $shopeeModelId = $row->shopee_model_id ?: $row->stock_shopee_model_id;
            $shopeeSellerSku = $row->mapped_seller_sku ?: $row->stock_shopee_seller_sku;
            $statusShopee = $shopeeModelId ? 'mapped' : ($shopeeItemId ? 'partial' : 'unmapped');
            $stockGroupKey = $this->stockMappingGroupKey($row);
            $matchedProductId = $stockGroupTiktokMatches[$stockGroupKey] ?? null;
            $canonicalGroupKey = $matchedProductId && isset($matchedTiktokToStockGroup[$matchedProductId])
                ? $matchedTiktokToStockGroup[$matchedProductId]
                : $stockGroupKey;

            [$tiktokMatch, $tiktokMatchSource] = $this->resolveSkuMappingTiktokMatch(
                $row,
                $tiktokLookup,
                $matchedProductId
            );

            $tiktokProductId = $row->mapped_tiktok_product_id ?: $row->stock_tiktok_product_id;
            $tiktokSkuId = $row->tiktok_sku_id ?: $row->stock_tiktok_sku_id;
            $tiktokSkuName = $row->tiktok_sku_name ?: null;
            $tiktokSellerSku = $row->mapped_seller_sku ?: $row->stock_tiktok_seller_sku ?: ($tiktokMatch->seller_sku ?? null);
            $hasSavedTiktokMapping = $this->filledString($tiktokProductId)
                || $this->filledString($tiktokSkuId)
                || $this->filledString($tiktokSkuName)
                || $this->filledString($tiktokSellerSku);
            $statusTikTok = $hasSavedTiktokMapping
                ? 'mapped'
                : ($tiktokMatch ? 'suggested' : 'unmapped');
            $variantActionStatus = trim((string) ($row->variant_action_status ?? ''));

            $shopeeImageUrl = $row->shopee_image_url
                ?: $row->shopee_model_image_url
                ?: $row->shopee_product_image_url;
            $tiktokImageUrl = $row->tiktok_image_url
                ?: ($tiktokMatch->image_url ?? null)
                ?: ($tiktokMatch->product_image_url ?? null);

            if ($tiktokMatch) {
                $matchedTiktokVariantKeys[$this->tiktokVariantKey($tiktokMatch)] = true;
            }

            $items[] = [
                'id' => $row->id,
                'group_key' => $canonicalGroupKey,
                'stock_master_id' => $row->id,
                'internal_sku' => $row->internal_sku,
                'product_name' => $row->product_name,
                'variant_name' => $row->variant_name,
                'stock_qty' => (int) ($row->stock_qty ?? 0),
                'image_url' => $row->internal_image_url ?: $shopeeImageUrl ?: $tiktokImageUrl ?: $row->shopee_product_image_url,
                'mapping_id' => $row->mapping_id,
                'seller_sku' => $shopeeSellerSku ?: $tiktokSellerSku,
                'variant_action_status' => $variantActionStatus !== '' ? $variantActionStatus : null,
                'variant_action_target_channel' => $row->variant_action_target_channel ?? null,
                'shopee' => [
                    'item_id' => $shopeeItemId,
                    'model_id' => $shopeeModelId,
                    'product_name' => $row->shopee_name ?: $row->product_name,
                    'variant_name' => $row->shopee_variant_name ?: $row->variant_name,
                    'seller_sku' => $shopeeSellerSku,
                    'stock_qty' => (int) ($row->shopee_variant_stock ?? $row->stock_qty ?? 0),
                    'image_url' => $shopeeImageUrl,
                    'status' => $statusShopee,
                ],
                'tiktok' => [
                    'product_id' => $tiktokProductId ?: ($tiktokMatch->product_id ?? null),
                    'sku_id' => $tiktokSkuId ?: ($tiktokMatch->sku_id ?? null),
                    'sku_name' => $tiktokSkuName ?: ($tiktokMatch->sku_name ?? null),
                    'seller_sku' => $tiktokSellerSku ?: ($tiktokMatch->seller_sku ?? null),
                    'product_name' => $tiktokMatch->product_name ?? null,
                    'variant_name' => $tiktokMatch->sku_name ?? $tiktokSkuName,
                    'stock_qty' => $tiktokMatch ? (int) ($tiktokMatch->stock_qty ?? 0) : null,
                    'image_url' => $tiktokImageUrl,
                    'status' => $statusTikTok,
                    'source' => $tiktokMatchSource,
                ],
                'status' => $statusShopee === 'mapped' && $statusTikTok === 'mapped'
                    ? 'ready_to_sync'
                    : ($variantActionStatus === 'ready_to_create'
                        ? 'ready_to_create'
                        : (($statusShopee !== 'unmapped' || $statusTikTok !== 'unmapped') ? 'needs_creation' : 'unmapped')),
                'updated_at' => $row->updated_at,
            ];
        }

        $tiktokRows = $tiktokLookup['rows_by_product_id'] ?? DB::table('tiktok_products')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->select('product_id', 'product_name', 'image_url', 'sku_id', 'sku_name', 'seller_sku', 'stock_qty', 'price', 'subtotal', 'updated_at')
            ->orderBy('product_name')
            ->orderBy('sku_name')
            ->get()
            ->groupBy('product_id');

        foreach ($tiktokRows as $productId => $group) {
            $canonicalGroupKey = isset($matchedTiktokToStockGroup[$productId])
                ? $matchedTiktokToStockGroup[$productId]
                : 'tiktok:'.$productId;
            $groupFirst = $group->first();
            $productImageUrl = $group->firstWhere('image_url')?->image_url ?? null;

            foreach ($group as $skuRow) {
                $variantKey = $this->tiktokVariantKey($skuRow);
                if (isset($matchedTiktokVariantKeys[$variantKey])) {
                    continue;
                }

                $items[] = [
                    'id' => 'tiktok-'.$skuRow->product_id.'-'.($skuRow->sku_id ?: $this->normalizeSkuMatchValue($skuRow->sku_name ?? '')),
                    'group_key' => $canonicalGroupKey,
                    'stock_master_id' => null,
                    'internal_sku' => null,
                    'product_name' => $groupFirst->product_name ?? null,
                    'variant_name' => $skuRow->sku_name,
                    'stock_qty' => (int) ($skuRow->stock_qty ?? 0),
                    'image_url' => $skuRow->image_url ?: $productImageUrl,
                    'mapping_id' => null,
                    'shopee' => [
                        'item_id' => null,
                        'model_id' => null,
                        'product_name' => null,
                        'variant_name' => null,
                        'stock_qty' => null,
                        'image_url' => null,
                        'status' => 'unmapped',
                    ],
                    'tiktok' => [
                        'product_id' => $skuRow->product_id,
                        'sku_id' => $skuRow->sku_id ?? null,
                        'sku_name' => $skuRow->sku_name,
                        'product_name' => $skuRow->product_name,
                        'variant_name' => $skuRow->sku_name,
                        'stock_qty' => (int) ($skuRow->stock_qty ?? 0),
                        'image_url' => $skuRow->image_url ?: $productImageUrl,
                        'status' => 'mapped',
                        'source' => 'actual',
                    ],
                    'status' => 'partially_mapped',
                    'updated_at' => $skuRow->updated_at,
                ];
            }
        }

        $items = collect($items)
            ->filter(function (array $item) use ($search, $status) {
                $matchesStatus = match ($status) {
                    'mapped' => $item['shopee']['status'] !== 'unmapped' || $item['tiktok']['status'] !== 'unmapped',
                    'unmapped' => $item['shopee']['status'] === 'unmapped' && $item['tiktok']['status'] === 'unmapped',
                    default => true,
                };

                if (! $matchesStatus) {
                    return false;
                }

                if ($search === '') {
                    return true;
                }

                $haystack = implode(' ', array_filter([
                    $item['internal_sku'] ?? '',
                    $item['product_name'] ?? '',
                    $item['variant_name'] ?? '',
                    $item['shopee']['item_id'] ?? '',
                    $item['shopee']['model_id'] ?? '',
                    $item['tiktok']['product_id'] ?? '',
                    $item['tiktok']['sku_id'] ?? '',
                    $item['tiktok']['sku_name'] ?? '',
                    $item['tiktok']['product_name'] ?? '',
                ], fn ($value) => trim((string) $value) !== ''));

                return str_contains(strtolower($haystack), strtolower($search));
            })
            ->sort(function (array $a, array $b) use ($sort) {
                return match ($sort) {
                    'name_asc' => strcmp(
                        strtolower((string) ($a['product_name'] ?? '')),
                        strtolower((string) ($b['product_name'] ?? ''))
                    ),
                    'created_desc' => strcmp(
                        (string) ($b['updated_at'] ?? ''),
                        (string) ($a['updated_at'] ?? '')
                    ),
                    default => strcmp(
                        (string) ($b['updated_at'] ?? ''),
                        (string) ($a['updated_at'] ?? '')
                    ),
                };
            })
            ->values();

        return response()->json([
            'summary' => [
                'total' => DB::table('stock_master')->count(),
                'mapped' => DB::table('sku_mappings')->count(),
                'last_shopee_sync_at' => DB::table('shopee_sync_logs')->max('synced_at'),
                'last_tiktok_sync_at' => DB::table('tiktok_sync_logs')->max('synced_at'),
            ],
            'items' => $items,
        ]);
    }

    private function tiktokSkuMappingLookup(): array
    {
        $empty = [
            'by_sku_id' => [],
            'by_product_sku_id' => [],
            'by_product_sku_name' => [],
            'by_product_variant_name' => [],
            'by_variant_name' => [],
            'product_groups' => [],
            'rows_by_product_id' => [],
        ];

        if (! Schema::hasTable('tiktok_products')) {
            return $empty;
        }

        $rows = DB::table('tiktok_products')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->select('product_id', 'product_name', 'image_url', 'sku_id', 'sku_name', 'seller_sku', 'stock_qty', 'updated_at')
            ->get();

        if ($rows->isEmpty()) {
            return $empty;
        }

        $bySkuId = [];
        $bySellerSku = [];
        $byProductSellerSku = [];
        $byProductSkuId = [];
        $byProductSkuName = [];
        $byProductVariantName = [];
        $byVariantName = [];
        $productGroups = [];
        $rowsByProductId = $rows->groupBy('product_id');

        foreach ($rowsByProductId as $productId => $group) {
            $first = $group->first();
            $productImageUrl = null;
            $skuNames = [];
            $sellerSkus = [];
            $rowsBySkuName = [];
            $rowsBySellerSku = [];

            foreach ($group as $skuRow) {
                if (! $this->filledString($productImageUrl) && $this->filledString($skuRow->image_url ?? null)) {
                    $productImageUrl = $skuRow->image_url;
                }
            }

            foreach ($group as $skuRow) {
                $skuRow->product_image_url = $productImageUrl;
                $skuNameKey = $this->normalizeSkuMatchValue($skuRow->sku_name ?? '');
                $sellerSkuKey = $this->normalizeSkuMatchValue($skuRow->seller_sku ?? '');

                if ($skuNameKey !== '') {
                    $skuNames[$skuNameKey] = true;
                    $rowsBySkuName[$skuNameKey] ??= $skuRow;
                }

                if ($sellerSkuKey !== '') {
                    $sellerSkus[$sellerSkuKey] = true;
                    $rowsBySellerSku[$sellerSkuKey] ??= $skuRow;
                }
            }

            $productGroups[(string) $productId] = [
                'product_id' => (string) $productId,
                'product_name' => $first->product_name ?? '',
                'product_name_key' => $this->normalizeSkuMatchValue($first->product_name ?? ''),
                'tokens' => $this->skuMappingNameTokens($first->product_name ?? ''),
                'sku_names' => array_keys($skuNames),
                'seller_skus' => array_keys($sellerSkus),
                'rows_by_sku_name' => $rowsBySkuName,
                'rows_by_seller_sku' => $rowsBySellerSku,
            ];
        }

        $setFirst = function (array &$index, string $key, object $row): void {
            if ($key !== '' && ! isset($index[$key])) {
                $index[$key] = $row;
            }
        };

        foreach ($rows as $row) {
            $productId = trim((string) ($row->product_id ?? ''));
            $skuId = trim((string) ($row->sku_id ?? ''));
            $skuNameKey = $this->normalizeSkuMatchValue($row->sku_name ?? '');
            $sellerSkuKey = $this->normalizeSkuMatchValue($row->seller_sku ?? '');
            $productNameKey = $this->normalizeSkuMatchValue($row->product_name ?? '');

            if ($skuId !== '') {
                $setFirst($bySkuId, $skuId, $row);
            }

            if ($sellerSkuKey !== '') {
                $setFirst($bySellerSku, $sellerSkuKey, $row);
            }

            if ($productId !== '' && $skuId !== '') {
                $setFirst($byProductSkuId, $productId.'|'.$skuId, $row);
            }

            if ($productId !== '' && $sellerSkuKey !== '') {
                $setFirst($byProductSellerSku, $productId.'|'.$sellerSkuKey, $row);
            }

            if ($productId !== '' && $skuNameKey !== '') {
                $setFirst($byProductSkuName, $productId.'|'.$skuNameKey, $row);
            }

            if ($productNameKey !== '' && $skuNameKey !== '') {
                $setFirst($byProductVariantName, $productNameKey.'|'.$skuNameKey, $row);
            }

            if ($skuNameKey !== '') {
                $byVariantName[$skuNameKey] ??= [];
                $byVariantName[$skuNameKey][] = $row;
            }
        }

        return [
            'by_sku_id' => $bySkuId,
            'by_seller_sku' => $bySellerSku,
            'by_product_seller_sku' => $byProductSellerSku,
            'by_product_sku_id' => $byProductSkuId,
            'by_product_sku_name' => $byProductSkuName,
            'by_product_variant_name' => $byProductVariantName,
            'by_variant_name' => $byVariantName,
            'product_groups' => $productGroups,
            'rows_by_product_id' => $rowsByProductId,
        ];
    }

    private function suggestTiktokProductsForStockGroups($rows, array $tiktokProductGroups): array
    {
        if ($rows->isEmpty() || $tiktokProductGroups === []) {
            return [];
        }

        $stockGroups = [];

        foreach ($rows as $row) {
            $groupKey = $this->stockMappingGroupKey($row);

            if (! isset($stockGroups[$groupKey])) {
                $stockGroups[$groupKey] = [
                    'product_name_key' => $this->normalizeSkuMatchValue($row->product_name ?? ''),
                    'tokens' => $this->skuMappingNameTokens($row->product_name ?? ''),
                    'variant_names' => [],
                ];
            }

            $variantKey = $this->normalizeSkuMatchValue($row->variant_name ?? '');
            if ($variantKey !== '') {
                $stockGroups[$groupKey]['variant_names'][$variantKey] = true;
            }
        }

        $matches = [];

        foreach ($stockGroups as $groupKey => $stockGroup) {
            $variantNames = array_keys($stockGroup['variant_names']);
            if ($variantNames === []) {
                continue;
            }

            $bestProductId = null;
            $bestScore = 0;

            foreach ($tiktokProductGroups as $productId => $tiktokGroup) {
                if ($stockGroup['product_name_key'] === '' || $stockGroup['product_name_key'] !== $tiktokGroup['product_name_key']) {
                    continue;
                }

                $variantOverlap = count(array_intersect($variantNames, $tiktokGroup['sku_names']));

                if ($variantOverlap === 0) {
                    continue;
                }

                $tokenOverlap = count(array_intersect($stockGroup['tokens'], $tiktokGroup['tokens']));
                $nameContains = $stockGroup['product_name_key'] !== ''
                    && $tiktokGroup['product_name_key'] !== ''
                    && (
                        str_contains($stockGroup['product_name_key'], $tiktokGroup['product_name_key'])
                        || str_contains($tiktokGroup['product_name_key'], $stockGroup['product_name_key'])
                    );

                if ($variantOverlap < 2 && $tokenOverlap < 2 && ! $nameContains) {
                    continue;
                }

                $score = ($variantOverlap * 1000) + ($tokenOverlap * 50) + ($nameContains ? 25 : 0);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestProductId = (string) $productId;
                }
            }

            if ($bestProductId !== null) {
                $matches[$groupKey] = $bestProductId;
            }
        }

        return $matches;
    }

    private function resolveSkuMappingTiktokMatch(object $row, array $lookup, ?string $suggestedProductId): array
    {
        $productId = trim((string) (($row->mapped_tiktok_product_id ?: $row->stock_tiktok_product_id) ?? ''));
        $skuId = trim((string) (($row->tiktok_sku_id ?: $row->stock_tiktok_sku_id) ?? ''));
        $sellerSku = '';
        foreach ([
            $row->mapped_seller_sku ?? null,
            $row->stock_tiktok_seller_sku ?? null,
            $row->stock_shopee_seller_sku ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $sellerSku = trim($candidate);
                break;
            }
        }
        $sellerSkuKey = $this->normalizeSkuMatchValue($sellerSku);
        $savedSkuNameKey = $this->normalizeSkuMatchValue($row->tiktok_sku_name ?? '');
        $variantKey = $this->normalizeSkuMatchValue($row->variant_name ?? '');
        $productNameKey = $this->normalizeSkuMatchValue($row->product_name ?? '');
        $lookupSkuNameKey = $savedSkuNameKey !== '' ? $savedSkuNameKey : $variantKey;
        $hasSavedMapping = $this->filledString($productId) || $this->filledString($skuId) || $savedSkuNameKey !== '' || $sellerSku !== '';

        foreach ([
            $productId !== '' && $sellerSkuKey !== '' ? ['by_product_seller_sku', $productId.'|'.$sellerSkuKey] : null,
            $sellerSkuKey !== '' ? ['by_seller_sku', $sellerSkuKey] : null,
            $productId !== '' && $skuId !== '' ? ['by_product_sku_id', $productId.'|'.$skuId] : null,
            $skuId !== '' ? ['by_sku_id', $skuId] : null,
            $productId !== '' && $lookupSkuNameKey !== '' ? ['by_product_sku_name', $productId.'|'.$lookupSkuNameKey] : null,
        ] as $candidate) {
            if ($candidate && isset($lookup[$candidate[0]][$candidate[1]])) {
                return [$lookup[$candidate[0]][$candidate[1]], 'saved'];
            }
        }

        if ($productId !== '' && $variantKey !== '') {
            $match = $lookup['product_groups'][$productId]['rows_by_sku_name'][$variantKey] ?? null;
            if ($match) {
                return [$match, 'saved'];
            }
        }

        if ($productId !== '' && $sellerSkuKey !== '') {
            $match = $lookup['product_groups'][$productId]['rows_by_seller_sku'][$sellerSkuKey] ?? null;
            if ($match) {
                return [$match, 'saved'];
            }
        }

        if ($productNameKey !== '' && $variantKey !== '') {
            $match = $lookup['by_product_variant_name'][$productNameKey.'|'.$variantKey] ?? null;
            if ($match) {
                return [$match, $hasSavedMapping ? 'saved' : 'suggested'];
            }
        }

        if ($suggestedProductId && $variantKey !== '') {
            $match = $lookup['product_groups'][$suggestedProductId]['rows_by_sku_name'][$variantKey] ?? null;
            if ($match) {
                return [$match, $hasSavedMapping ? 'saved' : 'suggested'];
            }
        }

        return [null, $hasSavedMapping ? 'saved' : null];
    }

    private function bestTiktokVariantCandidateForStockRow(object $row, array $candidates, array $tiktokProductGroups): ?object
    {
        if ($candidates === []) {
            return null;
        }

        $stockTokens = $this->skuMappingNameTokens($row->product_name ?? '');
        $stockProductNameKey = $this->normalizeSkuMatchValue($row->product_name ?? '');
        $bestMatch = null;
        $bestScore = 0;

        foreach ($candidates as $candidate) {
            $productId = (string) ($candidate->product_id ?? '');
            $tiktokGroup = $tiktokProductGroups[$productId] ?? null;

            if (! $tiktokGroup) {
                continue;
            }

            $tokenOverlap = count(array_intersect($stockTokens, $tiktokGroup['tokens']));
            $nameContains = $stockProductNameKey !== ''
                && $tiktokGroup['product_name_key'] !== ''
                && (
                    str_contains($stockProductNameKey, $tiktokGroup['product_name_key'])
                    || str_contains($tiktokGroup['product_name_key'], $stockProductNameKey)
                );

            if ($tokenOverlap < 2 && ! $nameContains) {
                continue;
            }

            $score = ($tokenOverlap * 100) + ($nameContains ? 25 : 0);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $candidate;
            }
        }

        return $bestMatch;
    }

    private function stockMappingGroupKey(object $row): string
    {
        $shopeeItemId = $row->shopee_item_id ?: ($row->stock_shopee_item_id ?? '');

        if ($this->filledString($shopeeItemId)) {
            return 'shopee:'.trim((string) $shopeeItemId);
        }

        $productNameKey = $this->normalizeSkuMatchValue($row->product_name ?? '');

        return $productNameKey !== '' ? 'product:'.$productNameKey : 'stock:'.$row->id;
    }

    private function normalizeSkuMatchValue(mixed $value): string
    {
        $value = strtolower(trim((string) ($value ?? '')));
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function tiktokVariantKey(object $row): string
    {
        $productId = trim((string) ($row->product_id ?? ''));
        $skuId = trim((string) ($row->sku_id ?? ''));
        $skuNameKey = $this->normalizeSkuMatchValue($row->sku_name ?? '');

        if ($productId === '') {
            return $skuId !== '' ? 'sku:'.$skuId : 'name:'.$skuNameKey;
        }

        if ($skuId !== '') {
            return $productId.'|sku:'.$skuId;
        }

        return $productId.'|name:'.$skuNameKey;
    }

    private function skuMappingNameTokens(mixed $value): array
    {
        $tokens = preg_split('/\s+/', $this->normalizeSkuMatchValue($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ignored = ['agni', 'and', 'bjm', 'by', 'dan', 'for', 'kw', 'ori', 'shop', 'the', 'yang'];

        return array_values(array_unique(array_filter(
            $tokens,
            fn ($token) => strlen($token) >= 3 && ! in_array($token, $ignored, true)
        )));
    }

    private function filledString(mixed $value): bool
    {
        return trim((string) ($value ?? '')) !== '';
    }

    public function saveSkuMapping(Request $request): JsonResponse
    {
        $this->ensureSkuMappingTables();

        $data = $request->validate([
            'stock_master_id' => ['required', 'integer'],
            'shopee_item_id' => ['nullable', 'string'],
            'shopee_model_id' => ['nullable', 'string'],
            'tiktok_product_id' => ['nullable', 'string'],
            'tiktok_sku_id' => ['nullable', 'string'],
            'tiktok_sku_name' => ['nullable', 'string'],
            'seller_sku' => ['nullable', 'string'],
            'internal_image_url' => ['nullable', 'string'],
            'shopee_image_url' => ['nullable', 'string'],
            'tiktok_image_url' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        DB::table('sku_mappings')->updateOrInsert(
            ['stock_master_id' => $data['stock_master_id']],
            [
                'shopee_item_id' => $data['shopee_item_id'] ?? null,
                'shopee_model_id' => $data['shopee_model_id'] ?? null,
                'tiktok_product_id' => $data['tiktok_product_id'] ?? null,
                'tiktok_sku_id' => $data['tiktok_sku_id'] ?? null,
                'tiktok_sku_name' => $data['tiktok_sku_name'] ?? null,
                'seller_sku' => $data['seller_sku'] ?? null,
                'internal_image_url' => $data['internal_image_url'] ?? null,
                'shopee_image_url' => $data['shopee_image_url'] ?? null,
                'tiktok_image_url' => $data['tiktok_image_url'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('stock_master')->where('id', $data['stock_master_id'])->update([
            'shopee_product_id' => $data['shopee_item_id'] ?? null,
            'shopee_sku' => $data['shopee_model_id'] ?? null,
            'shopee_seller_sku' => $data['seller_sku'] ?? null,
            'tiktok_product_id' => $data['tiktok_product_id'] ?? null,
            'tiktok_sku' => $data['tiktok_sku_id'] ?? null,
            'tiktok_seller_sku' => $data['seller_sku'] ?? null,
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'ok', 'message' => 'Mapping SKU berhasil disimpan.']);
    }

    public function prepareMissingVariant(Request $request): JsonResponse
    {
        $this->ensureSkuMappingTables();

        $data = $request->validate([
            'stock_master_id' => ['required', 'integer'],
            'target_channel' => ['required', 'in:shopee,tiktok'],
        ]);

        $stock = DB::table('stock_master as sm')
            ->leftJoin('sku_mappings as map', 'map.stock_master_id', '=', 'sm.id')
            ->leftJoin('shopee_product_model as spm', function ($join) {
                $join->on('spm.model_id', '=', DB::raw("COALESCE(NULLIF(map.shopee_model_id, ''), NULLIF(sm.shopee_sku, ''))"));
            })
            ->leftJoin('shopee_product as sp', function ($join) {
                $join->on('sp.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"));
            })
            ->leftJoin(DB::raw('(SELECT item_id, model_id, MIN(image_url) as image_url FROM shopee_product_image WHERE model_id IS NOT NULL GROUP BY item_id, model_id) as spmi'), function ($join) {
                $join->on('spmi.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"))
                    ->on('spmi.model_id', '=', DB::raw("COALESCE(NULLIF(map.shopee_model_id, ''), NULLIF(sm.shopee_sku, ''))"));
            })
            ->leftJoin(DB::raw('(SELECT item_id, MIN(image_url) as image_url FROM shopee_product_image WHERE model_id IS NULL GROUP BY item_id) as spi'), function ($join) {
                $join->on('spi.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"));
            })
            ->where('sm.id', $data['stock_master_id'])
            ->select(
                'sm.id',
                'sm.internal_sku',
                'sm.product_name',
                'sm.variant_name',
                'sm.stock_qty',
                'sm.shopee_product_id',
                'sm.shopee_sku',
                'sm.shopee_seller_sku',
                'sm.tiktok_product_id',
                'sm.tiktok_sku',
                'sm.tiktok_seller_sku',
                'map.seller_sku as mapped_seller_sku',
                'map.shopee_item_id',
                'map.shopee_model_id',
                'map.tiktok_product_id as mapped_tiktok_product_id',
                'map.tiktok_sku_id',
                'map.tiktok_sku_name',
                'map.tiktok_image_url',
                'map.shopee_image_url',
                'map.internal_image_url',
                'sp.name as shopee_product_name',
                'spm.name as shopee_variant_name',
                'spm.price as shopee_variant_price',
                'spm.stock as shopee_variant_stock',
                'spmi.image_url as shopee_model_image_url',
                'spi.image_url as shopee_product_image_url'
            )
            ->first();

        abort_if(! $stock, 404, 'Varian tidak ditemukan.');

        $targetChannel = $data['target_channel'];
        $sourceChannel = $targetChannel === 'tiktok' ? 'shopee' : 'tiktok';
        $draftPayload = null;

        if ($targetChannel === 'tiktok') {
            $sourceProductId = trim((string) ($stock->shopee_product_id ?? ''));
            $sourceModelId = trim((string) ($stock->shopee_sku ?? ''));
            $sourceVariantName = trim((string) ($stock->shopee_variant_name ?? $stock->variant_name ?? ''));
            $sourceSellerSku = trim((string) ($stock->mapped_seller_sku ?? $stock->shopee_seller_sku ?? ''));
            $sourceImageUrl = $stock->internal_image_url ?: $stock->shopee_image_url ?: $stock->shopee_model_image_url ?: $stock->shopee_product_image_url;

            abort_if($sourceProductId === '' && $sourceModelId === '' && $sourceVariantName === '', 422, 'Data Shopee belum cukup untuk membuat draft TikTok.');

            $draftPayload = [
                'target_channel' => 'tiktok',
                'source_channel' => 'shopee',
                'stock_master_id' => (int) $stock->id,
                'product_name' => $stock->product_name,
                'variant_name' => $stock->variant_name,
                'source' => [
                    'item_id' => $sourceProductId ?: null,
                    'model_id' => $sourceModelId ?: null,
                    'variant_name' => $sourceVariantName ?: null,
                    'seller_sku' => $sourceSellerSku ?: null,
                    'image_url' => $sourceImageUrl ?: null,
                    'stock_qty' => (int) ($stock->stock_qty ?? 0),
                    'price' => (int) ($stock->shopee_variant_price ?? 0),
                ],
                'target' => [
                    'variant_name' => $stock->variant_name,
                    'seller_sku' => $sourceSellerSku ?: $stock->internal_sku,
                    'image_url' => $sourceImageUrl ?: null,
                    'stock_qty' => (int) ($stock->stock_qty ?? 0),
                ],
            ];
        } else {
            $tiktokSource = DB::table('tiktok_products')
                ->whereRaw('COALESCE(is_active, true) = true')
                ->where(function ($query) use ($stock) {
                    $query->where(function ($sub) use ($stock) {
                        $sub->where('product_id', (string) ($stock->tiktok_product_id ?? ''))
                            ->where(function ($inner) use ($stock) {
                                $inner->where('sku_id', (string) ($stock->tiktok_sku ?? ''))
                                    ->orWhereRaw('LOWER(TRIM(sku_name)) = LOWER(TRIM(?))', [$stock->variant_name ?? '']);
                            });
                    })
                    ->orWhereRaw('LOWER(TRIM(product_name)) = LOWER(TRIM(?))', [$stock->product_name ?? '']);
                })
                ->orderByDesc('updated_at')
                ->first();

            abort_if(! $tiktokSource, 422, 'Data TikTok belum cukup untuk membuat draft Shopee.');

            $draftPayload = [
                'target_channel' => 'shopee',
                'source_channel' => 'tiktok',
                'stock_master_id' => (int) $stock->id,
                'product_name' => $stock->product_name,
                'variant_name' => $stock->variant_name,
                'source' => [
                    'product_id' => $tiktokSource->product_id,
                    'sku_id' => $tiktokSource->sku_id,
                    'sku_name' => $tiktokSource->sku_name,
                    'seller_sku' => $tiktokSource->seller_sku ?? null,
                    'image_url' => $tiktokSource->image_url ?? null,
                    'stock_qty' => (int) ($tiktokSource->stock_qty ?? 0),
                    'price' => (int) ($tiktokSource->price ?? 0),
                ],
                'target' => [
                    'variant_name' => $stock->variant_name,
                    'seller_sku' => $tiktokSource->seller_sku ?? $stock->internal_sku,
                    'image_url' => $tiktokSource->image_url ?? null,
                    'stock_qty' => (int) ($tiktokSource->stock_qty ?? 0),
                ],
            ];
        }

        DB::table('sku_variant_actions')->updateOrInsert(
            [
                'stock_master_id' => $stock->id,
                'target_channel' => $targetChannel,
                'action_type' => 'create_variant',
            ],
            [
                'source_channel' => $sourceChannel,
                'payload' => json_encode($draftPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'ready_to_create',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'status' => 'ok',
            'message' => 'Draft varian berhasil disiapkan.',
            'draft' => $draftPayload,
        ]);
    }

    public function stockMaster(): JsonResponse
    {
        $summary = DB::selectOne("
            SELECT
                COUNT(*) FILTER (
                    WHERE EXISTS (
                        SELECT 1 FROM tiktok_products tp
                        WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
                          AND LOWER(TRIM(tp.sku_name)) = LOWER(TRIM(sm.variant_name))
                          AND COALESCE(tp.is_active, true) = true
                    )
                ) AS total_match,
                COUNT(*) FILTER (
                    WHERE EXISTS (
                        SELECT 1 FROM tiktok_products tp
                        WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
                          AND COALESCE(tp.is_active, true) = true
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM tiktok_products tp
                        WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
                          AND LOWER(TRIM(tp.sku_name)) = LOWER(TRIM(sm.variant_name))
                          AND COALESCE(tp.is_active, true) = true
                    )
                ) AS total_variant_missing,
                COUNT(*) FILTER (
                    WHERE NOT EXISTS (
                        SELECT 1 FROM tiktok_products tp
                        WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
                          AND COALESCE(tp.is_active, true) = true
                    )
                ) AS total_product_missing,
                COUNT(*) AS total_all
            FROM stock_master sm
        ");

        $items = DB::select("
            SELECT
                sm.id,
                sm.internal_sku,
                sm.product_name,
                sm.variant_name,
                sm.stock_qty AS stock_shopee,
                COALESCE(tp.stock_qty, 0) AS stock_tiktok,
                sm.updated_at::text,
                CASE
                    WHEN tp.sku_name IS NOT NULL THEN 'MATCH'
                    WHEN EXISTS (
                        SELECT 1 FROM tiktok_products tpx
                        WHERE LOWER(TRIM(tpx.product_name)) = LOWER(TRIM(sm.product_name))
                    ) THEN 'VARIANT MISSING'
                    ELSE 'PRODUCT MISSING'
                END AS status_tiktok,
                CASE
                    WHEN tp.sku_name IS NOT NULL THEN 1
                    WHEN EXISTS (
                        SELECT 1 FROM tiktok_products tpx
                        WHERE LOWER(TRIM(tpx.product_name)) = LOWER(TRIM(sm.product_name))
                    ) THEN 2
                    ELSE 3
                END AS status_order
            FROM stock_master sm
            LEFT JOIN tiktok_products tp
              ON LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
             AND LOWER(TRIM(tp.sku_name)) = LOWER(TRIM(sm.variant_name))
             AND COALESCE(tp.is_active, true) = true
            ORDER BY status_order, sm.product_name, sm.variant_name
        ");

        return response()->json([
            'summary' => $summary,
            'items' => $items,
        ]);
    }

    public function syncShopeeToTiktok(): JsonResponse
    {
        $rows = DB::table('stock_master as sm')
            ->leftJoin('tiktok_products as tp', function ($join) {
                $join->on('tp.product_id', '=', 'sm.tiktok_product_id')
                    ->on('tp.sku_name', '=', 'sm.variant_name')
                    ->whereRaw('COALESCE(tp.is_active, true) = true');
            })
            ->whereNotNull('sm.tiktok_product_id')
            ->where('sm.tiktok_product_id', '<>', '')
            ->select(
                'sm.tiktok_product_id as product_id',
                'sm.tiktok_sku as sku',
                'sm.variant_name',
                'sm.stock_qty as shopee_stock',
                DB::raw('COALESCE(tp.stock_qty, 0) as tiktok_stock')
            )
            ->orderBy('sm.product_name')
            ->get();

        $items = $rows->map(function ($row) {
            $isMismatch = (int) $row->shopee_stock !== (int) $row->tiktok_stock;

            return [
                'product_id' => $row->product_id,
                'sku' => $row->sku ?: $row->variant_name,
                'shopee_stock' => (int) $row->shopee_stock,
                'tiktok_stock' => (int) $row->tiktok_stock,
                'status' => $isMismatch ? 'SUCCESS' : 'SKIP',
                'error' => null,
                'is_mismatch' => $isMismatch,
            ];
        });

        return response()->json([
            'success' => $items->where('status', 'SUCCESS')->count(),
            'failed' => 0,
            'skipped' => $items->where('status', 'SKIP')->count(),
            'items' => $items->values(),
            'mode' => 'preview',
        ]);
    }

    private function formatRupiah(int $value): string
    {
        return 'Rp '.number_format($value, 0, ',', '.');
    }

    private function latestShopeeTokens(): array
    {
        if (! Schema::hasTable('shopee_tokens')) {
            return [];
        }

        return DB::table('shopee_tokens')
            ->select([
                'id',
                'account_key',
                'account_name',
                'partner_id',
                'shop_id',
                'merchant_id',
                'supplier_id',
                'user_id',
                'access_token',
                'refresh_token',
                'expire_in',
                'expire_at',
                'access_token_expire_at',
                'refresh_token_expire_at',
                'request_id',
                'error',
                'message',
                'is_active',
                'created_at',
            ])
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'account_key' => $token->account_key,
                'account_name' => $token->account_name,
                'partner_id' => $token->partner_id,
                'shop_id' => $token->shop_id,
                'merchant_id' => $token->merchant_id,
                'supplier_id' => $token->supplier_id,
                'user_id' => $token->user_id,
                'access_token' => $this->maskToken($token->access_token),
                'refresh_token' => $this->maskToken($token->refresh_token),
                'expire_in' => $token->expire_in,
                'expire_at' => $token->expire_at,
                'access_token_expire_at' => $token->access_token_expire_at,
                'refresh_token_expire_at' => $token->refresh_token_expire_at,
                'request_id' => $token->request_id,
                'error' => $token->error,
                'message' => $token->message,
                'is_active' => $this->isLatestActiveToken('shopee_tokens', (int) $token->id, (string) $token->account_key),
                'created_at' => $token->created_at,
            ])
            ->all();
    }

    private function shopeeShopNames(): array
    {
        if (! Schema::hasTable('shopee_tokens')) {
            return [];
        }

        return DB::table('shopee_tokens')
            ->whereNotNull('shop_id')
            ->orderByDesc('created_at')
            ->get(['shop_id', 'account_name'])
            ->reduce(function (array $names, object $token) {
                $key = (string) $token->shop_id;

                if (! isset($names[$key])) {
                    $names[$key] = $token->account_name ?: 'Shopee';
                }

                return $names;
            }, []);
    }

    private function latestTiktokTokens(): array
    {
        if (! Schema::hasTable('tiktok_tokens')) {
            return [];
        }

        return DB::table('tiktok_tokens')
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(function ($token) {
                $row = (array) $token;

                $accountKey = $row['account_key'] ?? 'tiktok-agnishopbjm';

                return [
                    'id' => $row['id'] ?? null,
                    'account_key' => $accountKey,
                    'account_name' => $row['account_name'] ?? 'TikTok AgniShopBJM',
                    'shop_id' => $row['shop_id'] ?? $row['seller_id'] ?? $row['shop_cipher'] ?? null,
                    'access_token' => $this->maskToken($row['access_token'] ?? null),
                    'refresh_token' => $this->maskToken($row['refresh_token'] ?? null),
                    'expire_in' => $row['expire_in'] ?? $row['expires_in'] ?? null,
                    'expire_at' => $row['expire_at'] ?? $row['access_token_expire_at'] ?? null,
                    'request_id' => $row['request_id'] ?? null,
                    'error' => $row['error'] ?? null,
                    'message' => $row['message'] ?? null,
                    'is_active' => $this->isLatestActiveToken('tiktok_tokens', (int) ($row['id'] ?? 0), (string) $accountKey),
                    'created_at' => $row['created_at'] ?? null,
                ];
            })
            ->all();
    }

    private function isLatestActiveToken(string $table, int $id, string $accountKey): bool
    {
        if ($id <= 0 || ! Schema::hasTable($table)) {
            return false;
        }

        return (int) DB::table($table)
            ->where('account_key', $accountKey)
            ->whereRaw('is_active = true')
            ->latest('created_at')
            ->value('id') === $id;
    }

    private function tableCount(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }

    private function latestTokenPreview(string $table): ?array
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $row = DB::table($table)->latest('created_at')->first();

        if (! $row) {
            return null;
        }

        $data = (array) $row;
        $data['access_token'] = $this->maskToken($data['access_token'] ?? null);
        $data['refresh_token'] = $this->maskToken($data['refresh_token'] ?? null);

        return $data;
    }

    private function databaseInfo(): array
    {
        $row = DB::selectOne('select current_database() as name, current_user as username');

        return [
            'name' => $row->name ?? config('database.connections.pgsql.database'),
            'username' => $row->username ?? config('database.connections.pgsql.username'),
        ];
    }

    private function maskToken(?string $token): string
    {
        if (! $token) {
            return '-';
        }

        if (strlen($token) <= 12) {
            return $token;
        }

        return substr($token, 0, 8).'...'.substr($token, -6);
    }

    private function maskShopeeTokenPayload(array $payload): array
    {
        foreach (['access_token', 'refresh_token'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = $this->maskToken($payload[$key]);
            }
        }

        return $payload;
    }

    private function maskTiktokTokenPayload(array $payload): array
    {
        foreach (['access_token', 'refresh_token'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = $this->maskToken($payload[$key]);
            }
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            foreach (['access_token', 'refresh_token'] as $key) {
                if (array_key_exists($key, $payload['data'])) {
                    $payload['data'][$key] = $this->maskToken($payload['data'][$key]);
                }
            }
        }

        return $payload;
    }

    private function renderShopeeCallbackPage(string $title, string $message, array $result): string
    {
        $rows = [
            'Status' => $result['status'] ?? '-',
            'Action' => $result['action'] ?? '-',
            'Akun' => $result['account_name'] ?? '-',
            'Shop ID' => implode(', ', $result['shop_id_list'] ?? []),
            'Access Token' => $this->maskToken($result['access_token'] ?? null),
            'Refresh Token' => $this->maskToken($result['refresh_token'] ?? null),
            'Expire In' => $result['expire_in'] ?? '-',
            'Request ID' => $result['request_id'] ?? '-',
            'Error' => $result['error'] ?? '-',
            'Message' => $result['message'] ?? '-',
        ];

        $tableRows = collect($rows)->map(function ($value, string $label) {
            return '<tr><th>'.e($label).'</th><td>'.e((string) ($value ?: '-')).'</td></tr>';
        })->implode('');

        return '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>'.e($title).'</title><style>body{font-family:Arial,sans-serif;padding:32px;line-height:1.5;color:#0f172a}h1{margin-bottom:12px}table{border-collapse:collapse;width:100%;margin:18px 0;background:#fff}th,td{border:1px solid #d9e2ec;padding:10px 12px;text-align:left}th{width:180px;background:#f8fafc}a{color:#0f5fc7}</style></head><body><h1>'.e($title).'</h1><p>'.e($message).'</p><table>'.$tableRows.'</table><p><a href="/dashboard">Kembali ke Dashboard</a></p></body></html>';
    }

    private function buildShopeeAuthUrl(array $account): string
    {
        $config = $this->shopeeConfig();
        $path = '/api/v2/shop/auth_partner';
        $timestamp = time();
        $sign = $this->generateShopeeSign($config['partner_id'], $config['partner_key'], $path, $timestamp);
        $redirectUrl = $config['redirect_url'].(str_contains($config['redirect_url'], '?') ? '&' : '?').http_build_query([
            'account' => $account['key'],
        ]);

        return $config['host'].$path.'?'.http_build_query([
            'partner_id' => $config['partner_id'],
            'timestamp' => $timestamp,
            'sign' => $sign,
            'redirect' => $redirectUrl,
        ]);
    }

    private function connectShopee(array $account): array
    {
        $token = $this->latestActiveShopeeToken($account);

        if ($token && ! $this->shopeeAccessTokenNeedsRefresh($token)) {
            return [
                'status' => 'ok',
                'action' => 'connect-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Token '.$account['name'].' masih aktif. AUTH ulang tidak diperlukan.',
                'access_token' => $token->access_token,
                'refresh_token' => $token->refresh_token,
                'expire_in' => $token->expire_in,
                'expire_at' => $token->expire_at,
                'access_token_expire_at' => $token->access_token_expire_at ?? $token->expire_at,
                'refresh_token_expire_at' => $this->shopeeRefreshTokenExpireAt($token)?->toDateTimeString(),
            ];
        }

        if ($token && $this->shopeeRefreshTokenIsUsable($token)) {
            $result = $this->refreshShopeeToken($account);

            if (($result['status'] ?? '') === 'ok') {
                return $result;
            }

            if (! $this->shopeeRefreshFailureNeedsAuth($result)) {
                return $result;
            }
        }

        $callback = DB::table('shopee_callbacks')
            ->where('account_key', $account['key'])
            ->whereNull('used_at')
            ->latest('created_at')
            ->first();

        if ($callback) {
            return $this->exchangeShopeeToken($callback);
        }

        return [
            'status' => 'redirect',
            'action' => 'connect-'.$account['key'],
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'message' => 'Token '.$account['name'].' perlu authorization ulang.',
            'redirect_url' => $this->buildShopeeAuthUrl($account),
        ];
    }

    private function exchangeShopeeToken(object $callback): array
    {
        $config = $this->shopeeConfig();
        $path = '/api/v2/auth/token/get';
        $timestamp = time();
        $sign = $this->generateShopeeSign($config['partner_id'], $config['partner_key'], $path, $timestamp);
        $url = $config['host'].$path.'?'.http_build_query([
            'partner_id' => $config['partner_id'],
            'timestamp' => $timestamp,
            'sign' => $sign,
        ]);

        $payload = [
            'code' => $callback->code,
            'partner_id' => $config['partner_id'],
        ];

        if (! empty($callback->shop_id)) {
            $payload['shop_id'] = (int) $callback->shop_id;
        }

        if (! empty($callback->main_account_id)) {
            $payload['main_account_id'] = (int) $callback->main_account_id;
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        $data = $response->json() ?: [
            'error' => 'error_network',
            'message' => $response->body(),
        ];

        if (($data['error'] ?? '') === '' && ! empty($data['access_token'])) {
            $this->storeShopeeToken($data, $config['partner_id'], $callback);

            DB::table('shopee_callbacks')
                ->where('id', $callback->id)
                ->update(['used_at' => now(), 'updated_at' => now()]);
        }

        return [
            'status' => ($data['error'] ?? '') === '' ? 'ok' : 'error',
            'action' => 'get-token-'.$callback->account_key,
            'account_key' => $callback->account_key,
            'account_name' => $callback->account_name,
            'access_token_expire_at' => $this->resolveShopeeExpireAt($data['expire_in'] ?? null)?->toDateTimeString(),
            'refresh_token_expire_at' => (($data['error'] ?? '') === '' ? now()->addDays(self::SHOPEE_REFRESH_TOKEN_VALID_DAYS)->toDateTimeString() : null),
            ...$data,
        ];
    }

    private function refreshShopeeToken(array $account): array
    {
        $token = DB::table('shopee_tokens')
            ->where('account_key', $account['key'])
            ->whereRaw('is_active = true')
            ->whereNotNull('refresh_token')
            ->latest('created_at')
            ->first();

        if (! $token) {
            return [
                'status' => 'error',
                'action' => 'refresh-token-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Belum ada token aktif '.$account['name'].' yang bisa di-refresh. Jalankan AUTH dan GET TOKEN dulu.',
            ];
        }

        if (! $this->shopeeRefreshTokenIsUsable($token)) {
            return [
                'status' => 'error',
                'action' => 'refresh-token-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'error' => 'refresh_token_expired',
                'message' => 'Refresh token '.$account['name'].' sudah kedaluwarsa. Jalankan AUTH Shopee ulang.',
                'refresh_token_expire_at' => $this->shopeeRefreshTokenExpireAt($token)?->toDateTimeString(),
            ];
        }

        $identifier = $this->shopeeRefreshIdentifier($token);

        if (! $identifier) {
            return [
                'status' => 'error',
                'action' => 'refresh-token-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Token aktif '.$account['name'].' tidak memiliki shop_id, merchant_id, supplier_id, atau user_id.',
            ];
        }

        $config = $this->shopeeConfig();
        $path = '/api/v2/auth/access_token/get';
        $timestamp = time();
        $sign = $this->generateShopeeSign($config['partner_id'], $config['partner_key'], $path, $timestamp);
        $url = $config['host'].$path.'?'.http_build_query([
            'partner_id' => $config['partner_id'],
            'timestamp' => $timestamp,
            'sign' => $sign,
        ]);

        $payload = [
            'refresh_token' => $token->refresh_token,
            'partner_id' => $config['partner_id'],
            $identifier['key'] => $identifier['value'],
        ];

        $response = Http::timeout(20)
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        $data = $response->json() ?: [
            'error' => 'error_network',
            'message' => $response->body(),
        ];

        if (($data['error'] ?? '') === '' && ! empty($data['access_token']) && ! empty($data['refresh_token'])) {
            $this->storeShopeeRefreshToken($data, $config['partner_id'], $account, $token);

            return [
                ...$data,
                'status' => 'ok',
                'action' => 'refresh-token-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Refresh token '.$account['name'].' berhasil. Token baru sudah disimpan.',
                'access_token_expire_at' => $this->resolveShopeeExpireAt($data['expire_in'] ?? null)?->toDateTimeString(),
                'refresh_token_expire_at' => now()->addDays(self::SHOPEE_REFRESH_TOKEN_VALID_DAYS)->toDateTimeString(),
            ];
        }

        return [
            'status' => 'error',
            'action' => 'refresh-token-'.$account['key'],
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            ...$data,
        ];
    }

    private function latestActiveShopeeToken(array $account): ?object
    {
        if (! Schema::hasTable('shopee_tokens')) {
            return null;
        }

        return DB::table('shopee_tokens')
            ->where('account_key', $account['key'])
            ->whereRaw('is_active = true')
            ->whereNotNull('refresh_token')
            ->latest('created_at')
            ->first();
    }

    private function storeShopeeToken(array $data, int $partnerId, object $callback): void
    {
        $shopIdList = $data['shop_id_list'] ?? [];
        $merchantIdList = $data['merchant_id_list'] ?? [];
        $supplierIdList = $data['supplier_id_list'] ?? [];
        $userIdList = $data['user_id_list'] ?? [];
        $shopId = $callback->shop_id ?: ($shopIdList[0] ?? null);

        if ($shopId) {
            DB::table('shopee_tokens')
                ->where('shop_id', $shopId)
                ->where('account_key', $callback->account_key)
                ->update(['is_active' => DB::raw('false'), 'updated_at' => now()]);
        }

        DB::table('shopee_tokens')->insert([
            'account_key' => $callback->account_key,
            'account_name' => $callback->account_name,
            'partner_id' => $partnerId,
            'shop_id' => $shopId,
            'merchant_id' => $merchantIdList[0] ?? null,
            'supplier_id' => $supplierIdList[0] ?? null,
            'user_id' => $userIdList[0] ?? null,
            'shop_id_list' => json_encode($shopIdList),
            'merchant_id_list' => json_encode($merchantIdList),
            'supplier_id_list' => json_encode($supplierIdList),
            'user_id_list' => json_encode($userIdList),
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'expire_in' => $data['expire_in'] ?? null,
            'expire_at' => $this->resolveShopeeExpireAt($data['expire_in'] ?? null),
            'access_token_expire_at' => $this->resolveShopeeExpireAt($data['expire_in'] ?? null),
            'refresh_token_expire_at' => now()->addDays(self::SHOPEE_REFRESH_TOKEN_VALID_DAYS),
            'request_id' => $data['request_id'] ?? null,
            'error' => $data['error'] ?? null,
            'message' => $data['message'] ?? null,
            'raw_response' => json_encode($data),
            'is_active' => DB::raw('true'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeShopeeRefreshToken(array $data, int $partnerId, array $account, object $previousToken): void
    {
        $shopId = $data['shop_id'] ?? $previousToken->shop_id ?? null;
        $merchantId = $data['merchant_id'] ?? $previousToken->merchant_id ?? null;
        $supplierId = $data['supplier_id'] ?? $previousToken->supplier_id ?? null;
        $userId = $data['user_id'] ?? $previousToken->user_id ?? null;

        DB::table('shopee_tokens')
            ->where('account_key', $account['key'])
            ->whereRaw('is_active = true')
            ->update(['is_active' => DB::raw('false'), 'updated_at' => now()]);

        DB::table('shopee_tokens')->insert([
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'partner_id' => $data['partner_id'] ?? $partnerId,
            'shop_id' => $shopId,
            'merchant_id' => $merchantId,
            'supplier_id' => $supplierId,
            'user_id' => $userId,
            'shop_id_list' => json_encode($shopId ? [(int) $shopId] : []),
            'merchant_id_list' => json_encode($merchantId ? [(int) $merchantId] : []),
            'supplier_id_list' => json_encode($supplierId ? [(int) $supplierId] : []),
            'user_id_list' => json_encode($userId ? [(int) $userId] : []),
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'expire_in' => $data['expire_in'] ?? null,
            'expire_at' => $this->resolveShopeeExpireAt($data['expire_in'] ?? null),
            'access_token_expire_at' => $this->resolveShopeeExpireAt($data['expire_in'] ?? null),
            'refresh_token_expire_at' => now()->addDays(self::SHOPEE_REFRESH_TOKEN_VALID_DAYS),
            'request_id' => $data['request_id'] ?? null,
            'error' => $data['error'] ?? null,
            'message' => $data['message'] ?? null,
            'raw_response' => json_encode($data),
            'is_active' => DB::raw('true'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function shopeeRefreshIdentifier(object $token): ?array
    {
        foreach (['shop_id', 'merchant_id', 'supplier_id', 'user_id'] as $key) {
            if (! empty($token->{$key})) {
                return ['key' => $key, 'value' => (int) $token->{$key}];
            }
        }

        return null;
    }

    private function shopeeAccessTokenNeedsRefresh(object $token): bool
    {
        $expireAt = $this->shopeeAccessTokenExpireAt($token);

        if (! $expireAt) {
            return true;
        }

        return $expireAt->lessThanOrEqualTo(now()->addMinutes(self::SHOPEE_ACCESS_TOKEN_REFRESH_BUFFER_MINUTES));
    }

    private function shopeeAccessTokenIsExpired(object $token): bool
    {
        $expireAt = $this->shopeeAccessTokenExpireAt($token);

        return ! $expireAt || $expireAt->isPast();
    }

    private function shopeeAccessTokenExpireAt(object $token): ?Carbon
    {
        $value = $token->access_token_expire_at ?? $token->expire_at ?? null;

        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function shopeeRefreshTokenIsUsable(object $token): bool
    {
        $expireAt = $this->shopeeRefreshTokenExpireAt($token);

        return $expireAt && $expireAt->isFuture();
    }

    private function shopeeRefreshTokenExpireAt(object $token): ?Carbon
    {
        $value = $token->refresh_token_expire_at ?? null;

        if ($value) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        if (! empty($token->created_at)) {
            try {
                return Carbon::parse($token->created_at)->addDays(self::SHOPEE_REFRESH_TOKEN_VALID_DAYS);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function shopeeRefreshFailureNeedsAuth(array $result): bool
    {
        $error = strtolower((string) ($result['error'] ?? ''));
        $message = strtolower((string) ($result['message'] ?? ''));

        foreach (['refresh_token_expired', 'access_expired', 'no_linked', 'invalid refresh_token'] as $needle) {
            if (str_contains($error, $needle) || str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function resolveShopeeExpireAt(?int $expireIn): ?Carbon
    {
        if (! $expireIn) {
            return null;
        }

        return $expireIn > time()
            ? Carbon::createFromTimestamp($expireIn)
            : now()->addSeconds($expireIn);
    }

    private function buildTiktokAuthUrl(array $account): string
    {
        $config = $this->tiktokConfig();
        $timestamp = time();
        $params = [
            'app_key' => $config['app_key'],
            'timestamp' => $timestamp,
            'redirect_uri' => $config['redirect_url'],
            'state' => $account['key'],
        ];
        $params['sign'] = $this->generateTiktokSign('/openapi/v2/oauth/authorize', $params, $config['app_secret']);

        return $config['auth_host'].'/openapi/v2/oauth/authorize?'.http_build_query($params);
    }

    private function connectTiktok(array $account): array
    {
        $token = $this->latestActiveTiktokToken($account);

        if ($token && ! $this->tiktokAccessTokenNeedsRefresh($token)) {
            return [
                'status' => 'ok',
                'action' => 'connect-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Token '.$account['name'].' masih aktif. AUTH ulang tidak diperlukan.',
                'access_token' => $token->access_token,
                'refresh_token' => $token->refresh_token,
                'expire_in' => $token->expire_in,
                'expire_at' => $token->expire_at,
                'access_token_expire_at' => $token->access_token_expire_at ?? $token->expire_at,
                'refresh_token_expire_at' => $this->tiktokRefreshTokenExpireAt($token)?->toDateTimeString(),
            ];
        }

        if ($token && $this->tiktokRefreshTokenIsUsable($token)) {
            $result = $this->refreshTiktokToken($account);

            if (($result['status'] ?? '') === 'ok') {
                return $result;
            }

            if (! $this->tiktokRefreshFailureNeedsAuth($result)) {
                return $result;
            }
        }

        $callback = DB::table('tiktok_callbacks')
            ->where('account_key', $account['key'])
            ->whereNull('used_at')
            ->latest('created_at')
            ->first();

        if ($callback && $this->callbackIsFresh($callback->created_at ?? null)) {
            $result = $this->exchangeTiktokToken($account);

            if (($result['status'] ?? '') === 'ok') {
                return $result;
            }

            if (str_contains(strtolower((string) ($result['message'] ?? '')), 'invalid auth code')) {
                DB::table('tiktok_callbacks')
                    ->where('id', $callback->id)
                    ->update(['used_at' => now(), 'updated_at' => now()]);
            }
        } elseif ($callback) {
            DB::table('tiktok_callbacks')
                ->where('id', $callback->id)
                ->update(['used_at' => now(), 'updated_at' => now()]);
        }

        return [
            'status' => 'redirect',
            'action' => 'connect-'.$account['key'],
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'message' => 'Token '.$account['name'].' perlu authorization ulang.',
            'redirect_url' => $this->buildTiktokAuthUrl($account),
        ];
    }

    private function exchangeTiktokToken(array $account): array
    {
        $this->ensureTiktokAuthTables();

        $callback = DB::table('tiktok_callbacks')
            ->where('account_key', $account['key'])
            ->whereNull('used_at')
            ->latest('created_at')
            ->first();

        if (! $callback) {
            return [
                'status' => 'error',
                'action' => 'get-token-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Belum ada callback '.$account['name'].' yang bisa ditukar menjadi token. Klik AUTH dulu.',
            ];
        }

        $config = $this->tiktokConfig();
        $response = Http::timeout(20)->get($config['auth_host'].'/api/v2/token/get', [
            'app_key' => $config['app_key'],
            'app_secret' => $config['app_secret'],
            'auth_code' => $callback->code,
            'grant_type' => 'authorized_code',
        ]);

        $data = $response->json() ?: ['code' => $response->status(), 'message' => $response->body()];
        $ok = (int) ($data['code'] ?? -1) === 0 && ! empty($data['data']['access_token']);

        if ($ok) {
            $this->storeTiktokToken($data, $account);

            DB::table('tiktok_callbacks')
                ->where('id', $callback->id)
                ->update(['used_at' => now(), 'updated_at' => now()]);
        } elseif (str_contains(strtolower((string) ($data['message'] ?? '')), 'invalid auth code')) {
            DB::table('tiktok_callbacks')
                ->where('id', $callback->id)
                ->update(['used_at' => now(), 'updated_at' => now()]);
        }

        return [
            'status' => $ok ? 'ok' : 'error',
            'action' => 'get-token-'.$account['key'],
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'message' => $ok ? 'Token TikTok berhasil disimpan.' : ($data['message'] ?? 'TikTok mengembalikan error.'),
            ...$data,
        ];
    }

    private function refreshTiktokToken(array $account): array
    {
        $this->ensureTiktokAuthTables();

        $token = DB::table('tiktok_tokens')
            ->where('account_key', $account['key'])
            ->whereRaw('is_active = true')
            ->whereNotNull('refresh_token')
            ->latest('created_at')
            ->first();

        if (! $token) {
            return [
                'status' => 'error',
                'action' => 'refresh-token-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Belum ada token TikTok yang bisa di-refresh. Jalankan AUTH dan GET TOKEN dulu.',
            ];
        }

        if (! $this->tiktokRefreshTokenIsUsable($token)) {
            return [
                'status' => 'error',
                'action' => 'refresh-token-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'code' => 'refresh_token_expired',
                'message' => 'Refresh token '.$account['name'].' sudah kedaluwarsa. Jalankan AUTH TikTok ulang.',
                'refresh_token_expire_at' => $this->tiktokRefreshTokenExpireAt($token)?->toDateTimeString(),
            ];
        }

        $config = $this->tiktokConfig();
        $response = Http::timeout(20)->get($config['auth_host'].'/api/v2/token/refresh', [
            'app_key' => $config['app_key'],
            'app_secret' => $config['app_secret'],
            'refresh_token' => $token->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        $data = $response->json() ?: ['code' => $response->status(), 'message' => $response->body()];
        $ok = (int) ($data['code'] ?? -1) === 0 && ! empty($data['data']['access_token']);

        if ($ok) {
            $this->storeTiktokToken($data, $account);
        }

        return [
            ...$data,
            'status' => $ok ? 'ok' : 'error',
            'action' => 'refresh-token-'.$account['key'],
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'message' => $ok ? 'Refresh token TikTok berhasil. Token baru sudah disimpan.' : ($data['message'] ?? 'TikTok mengembalikan error.'),
            'access_token_expire_at' => $ok ? $this->resolveTiktokExpireAt($data['data']['access_token_expire_in'] ?? null)?->toDateTimeString() : null,
            'refresh_token_expire_at' => $ok ? $this->resolveTiktokExpireAt($data['data']['refresh_token_expire_in'] ?? null)?->toDateTimeString() : null,
        ];
    }

    private function latestActiveTiktokToken(array $account): ?object
    {
        if (! Schema::hasTable('tiktok_tokens')) {
            return null;
        }

        return DB::table('tiktok_tokens')
            ->where('account_key', $account['key'])
            ->whereRaw('is_active = true')
            ->whereNotNull('refresh_token')
            ->latest('created_at')
            ->first();
    }

    private function tiktokAccessTokenNeedsRefresh(object $token): bool
    {
        $expireAt = $this->tiktokAccessTokenExpireAt($token);

        if (! $expireAt) {
            return true;
        }

        return $expireAt->lessThanOrEqualTo(now()->addMinutes(self::TIKTOK_ACCESS_TOKEN_REFRESH_BUFFER_MINUTES));
    }

    private function tiktokAccessTokenIsExpired(object $token): bool
    {
        $expireAt = $this->tiktokAccessTokenExpireAt($token);

        return ! $expireAt || $expireAt->isPast();
    }

    private function tiktokAccessTokenExpireAt(object $token): ?Carbon
    {
        return $this->parseTokenDate($token->access_token_expire_at ?? $token->expire_at ?? null);
    }

    private function tiktokRefreshTokenIsUsable(object $token): bool
    {
        $expireAt = $this->tiktokRefreshTokenExpireAt($token);

        return $expireAt && $expireAt->isFuture();
    }

    private function tiktokRefreshTokenExpireAt(object $token): ?Carbon
    {
        return $this->parseTokenDate($token->refresh_token_expire_at ?? null);
    }

    private function tiktokRefreshFailureNeedsAuth(array $result): bool
    {
        $code = strtolower((string) ($result['code'] ?? ''));
        $message = strtolower((string) ($result['message'] ?? ''));

        foreach (['refresh_token_expired', 'invalid refresh_token', 'invalid refresh token', 'refresh token expired'] as $needle) {
            if (str_contains($code, $needle) || str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function getTiktokAuthorizedShops(array $account): array
    {
        $this->ensureTiktokAuthTables();

        $token = $this->latestActiveTiktokToken($account);

        if (! $token) {
            return [
                'status' => 'error',
                'action' => 'get-auth-shop-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Belum ada access token TikTok. Jalankan AUTH dan GET TOKEN dulu.',
            ];
        }

        if ($this->tiktokAccessTokenNeedsRefresh($token)) {
            $refreshResult = $this->refreshTiktokToken($account);

            if (($refreshResult['status'] ?? '') !== 'ok') {
                return [
                    ...$refreshResult,
                    'status' => 'error',
                    'action' => 'get-auth-shop-'.$account['key'],
                    'account_key' => $account['key'],
                    'account_name' => $account['name'],
                    'message' => $refreshResult['message'] ?? 'Access token TikTok perlu refresh, tetapi refresh gagal.',
                ];
            }

            $token = $this->latestActiveTiktokToken($account);
        }

        $config = $this->tiktokConfig();
        $path = '/authorization/202309/shops';
        $timestamp = time();
        $params = [
            'app_key' => $config['app_key'],
            'timestamp' => $timestamp,
        ];
        $params['sign'] = $this->generateTiktokSign($path, $params, $config['app_secret']);

        $response = Http::timeout(20)
            ->withHeaders(['x-tts-access-token' => $token->access_token])
            ->get($config['api_host'].$path, $params);

        $data = $response->json() ?: ['code' => $response->status(), 'message' => $response->body()];
        $shops = $data['data']['shops'] ?? [];
        $ok = (int) ($data['code'] ?? -1) === 0 && is_array($shops) && count($shops) > 0;

        if ($ok) {
            $this->storeTiktokShops($shops);
        }

        return [
            'status' => $ok ? 'ok' : 'error',
            'action' => 'get-auth-shop-'.$account['key'],
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'message' => $ok ? count($shops).' shop TikTok berhasil disimpan.' : ($data['message'] ?? 'TikTok tidak mengembalikan data shop.'),
            ...$data,
        ];
    }

    private function storeTiktokToken(array $response, array $account): void
    {
        $this->ensureTiktokAuthTables();

        $data = $response['data'] ?? [];
        $expireAt = $this->resolveTiktokExpireAt($data['access_token_expire_in'] ?? null);
        $refreshExpireAt = $this->resolveTiktokExpireAt($data['refresh_token_expire_in'] ?? null);

        $expireIn = $expireAt ? (int) floor(now()->diffInSeconds($expireAt, false)) : null;

        DB::table('tiktok_tokens')
            ->where('account_key', $account['key'])
            ->whereRaw('is_active = true')
            ->update(['is_active' => DB::raw('false'), 'updated_at' => now()]);

        DB::table('tiktok_tokens')->insert([
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'open_id' => $data['open_id'] ?? null,
            'seller_name' => $data['seller_name'] ?? null,
            'seller_region' => $data['seller_base_region'] ?? null,
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'expire_at' => $expireAt,
            'expire_in' => $expireIn,
            'access_token_expire_at' => $expireAt,
            'refresh_token_expire_at' => $refreshExpireAt,
            'granted_scopes' => json_encode($data['granted_scopes'] ?? []),
            'request_id' => $response['request_id'] ?? null,
            'message' => $response['message'] ?? null,
            'raw_response' => json_encode($response),
            'is_active' => DB::raw('true'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeTiktokShops(array $shops): void
    {
        $this->ensureTiktokAuthTables();

        foreach ($shops as $shop) {
            $shopId = (string) ($shop['id'] ?? $shop['shop_id'] ?? '');
            $shopCipher = $shop['cipher'] ?? $shop['shop_cipher'] ?? null;

            if ($shopId === '') {
                continue;
            }

            DB::table('tiktok_shops')->updateOrInsert(
                ['id' => $shopId],
                [
                    'shop_id' => $shopId,
                    'code' => $shop['code'] ?? null,
                    'name' => $shop['name'] ?? null,
                    'region' => $shop['region'] ?? null,
                    'seller_type' => $shop['seller_type'] ?? null,
                    'cipher' => $shopCipher,
                    'shop_cipher' => $shopCipher,
                    'raw_response' => json_encode($shop),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    private function latestTiktokShop(): ?object
    {
        if (! Schema::hasTable('tiktok_shops')) {
            return null;
        }

        return DB::table('tiktok_shops')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();
    }

    private function resolveTiktokExpireAt(null|int|string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        $seconds = (int) $value;

        return $seconds > time()
            ? Carbon::createFromTimestamp($seconds)
            : now()->addSeconds($seconds);
    }

    private function tokenDateIsFuture(mixed $value): bool
    {
        if (! $value) {
            return false;
        }

        return (bool) $this->parseTokenDate($value)?->isFuture();
    }

    private function parseTokenDate(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function callbackIsFresh(mixed $value): bool
    {
        if (! $value) {
            return false;
        }

        try {
            return Carbon::parse($value)->greaterThan(now()->subMinutes(30));
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeActiveMarketplaceTokens(): void
    {
        $tables = [
            'shopee_tokens' => 'shopee',
            'tiktok_tokens' => 'tiktok',
        ];

        foreach ($tables as $table => $channel) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

            $accountColumn = Schema::hasColumn($table, 'account_key') ? 'account_key' : (Schema::hasColumn($table, 'account_name') ? 'account_name' : null);

            if (! $accountColumn) {
                continue;
            }

            foreach (self::MARKETPLACE_ACCOUNTS as $key => $account) {
                if ($account['channel'] !== $channel) {
                    continue;
                }

                $latestId = DB::table($table)
                    ->where($accountColumn, $accountColumn === 'account_key' ? $key : $account['name'])
                    ->whereRaw('is_active = true')
                    ->latest('created_at')
                    ->value('id');

                if (! $latestId) {
                    continue;
                }

                DB::table($table)
                    ->where($accountColumn, $accountColumn === 'account_key' ? $key : $account['name'])
                    ->where('id', '<>', $latestId)
                    ->whereRaw('is_active = true')
                    ->update(['is_active' => DB::raw('false'), 'updated_at' => now()]);
            }
        }
    }

    private function tiktokConfig(): array
    {
        $this->ensureTiktokAuthTables();

        $row = SchemaCache::activeTiktokConfig();
        $envAppKey = trim((string) config('tiktok.app_key'));
        $envAppSecret = trim((string) config('tiktok.app_secret'));
        $dbAppKey = trim((string) ($row->app_key ?? ''));
        $dbAppSecret = trim((string) ($row->app_secret ?? ''));

        $appKey = $envAppKey !== '' ? $envAppKey : $dbAppKey;
        $appSecret = $envAppSecret !== '' ? $envAppSecret : $dbAppSecret;
        $redirectUrl = trim((string) ($row->redirect_url ?? config('tiktok.redirect_url')));
        $authHost = trim((string) config('tiktok.auth_host'));
        $apiHost = trim((string) config('tiktok.api_host'));

        if ($appKey === '' || $appSecret === '') {
            abort(422, 'Konfigurasi TikTok belum valid. Isi `TIKTOK_APP_KEY` / `TIKTOK_APP_SECRET` dengan kredensial asli dari TikTok Shop Partner.');
        }

        return [
            'app_key' => $appKey,
            'app_secret' => $appSecret,
            'auth_host' => rtrim($authHost, '/'),
            'api_host' => rtrim($apiHost, '/'),
            'redirect_url' => $redirectUrl,
        ];
    }

    private function ensureShopeeAuthColumns(): void
    {
        if (! Schema::hasTable('shopee_tokens')) {
            return;
        }

        foreach ([
            'access_token_expire_at TIMESTAMP NULL',
            'refresh_token_expire_at TIMESTAMP NULL',
        ] as $definition) {
            DB::statement('ALTER TABLE shopee_tokens ADD COLUMN IF NOT EXISTS '.$definition);
        }

        DB::table('shopee_tokens')
            ->whereNotNull('expire_at')
            ->whereNull('access_token_expire_at')
            ->update(['access_token_expire_at' => DB::raw('expire_at')]);

        DB::table('shopee_tokens')
            ->whereNotNull('refresh_token')
            ->whereNull('refresh_token_expire_at')
            ->whereNotNull('created_at')
            ->update(['refresh_token_expire_at' => DB::raw("created_at + INTERVAL '".self::SHOPEE_REFRESH_TOKEN_VALID_DAYS." days'")]);

        DB::table('shopee_tokens')
            ->whereNotNull('refresh_token')
            ->whereNotNull('refresh_token_expire_at')
            ->whereNotNull('created_at')
            ->whereRaw("refresh_token_expire_at < created_at + INTERVAL '".self::SHOPEE_REFRESH_TOKEN_VALID_DAYS." days'")
            ->update(['refresh_token_expire_at' => DB::raw("created_at + INTERVAL '".self::SHOPEE_REFRESH_TOKEN_VALID_DAYS." days'")]);
    }

    private function generateTiktokSign(string $path, array $params, string $secret, ?string $body = null): string
    {
        unset($params['sign'], $params['access_token']);
        ksort($params);

        $stringToSign = $secret.$path;
        foreach ($params as $key => $value) {
            $stringToSign .= $key.$value;
        }

        $stringToSign .= $body ?? '';
        $stringToSign .= $secret;

        return hash_hmac('sha256', $stringToSign, $secret);
    }

    private function ensureTiktokAuthTables(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS tiktok_config (
                id BIGSERIAL PRIMARY KEY,
                app_key TEXT NOT NULL,
                app_secret TEXT NOT NULL,
                auth_host TEXT DEFAULT 'https://auth.tiktok-shops.com',
                api_host TEXT DEFAULT 'https://open-api.tiktokglobalshop.com',
                redirect_url TEXT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS tiktok_callbacks (
                id SERIAL PRIMARY KEY,
                account_key TEXT,
                account_name TEXT,
                code TEXT,
                app_key TEXT,
                shop_region TEXT,
                state TEXT,
                query_payload JSONB,
                used_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS tiktok_tokens (
                id SERIAL PRIMARY KEY,
                account_key TEXT,
                account_name TEXT,
                open_id TEXT,
                seller_name TEXT,
                seller_region TEXT,
                access_token TEXT,
                refresh_token TEXT,
                expire_at TIMESTAMP NULL,
                expire_in INTEGER NULL,
                access_token_expire_at TIMESTAMP NULL,
                refresh_token_expire_at TIMESTAMP NULL,
                granted_scopes JSONB,
                shop_id TEXT,
                request_id TEXT,
                message TEXT,
                raw_response JSONB,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS tiktok_shops (
                id TEXT PRIMARY KEY,
                shop_id TEXT NULL,
                code TEXT,
                name TEXT,
                region TEXT,
                seller_type TEXT,
                cipher TEXT,
                shop_cipher TEXT NULL,
                raw_response JSONB,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        $existingTiktokConfig = DB::table('tiktok_config')->where('id', 1)->exists();

        if (! $existingTiktokConfig) {
            $appKey = trim((string) config('tiktok.app_key'));
            $appSecret = trim((string) config('tiktok.app_secret'));

            if ($appKey !== '' && $appSecret !== '') {
                DB::table('tiktok_config')->insert([
                    'id' => 1,
                    'app_key' => $appKey,
                    'app_secret' => $appSecret,
                    'auth_host' => rtrim((string) config('tiktok.auth_host'), '/'),
                    'api_host' => rtrim((string) config('tiktok.api_host'), '/'),
                    'redirect_url' => trim((string) config('tiktok.redirect_url')),
                    'is_active' => DB::raw('true'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach ([
            'tiktok_callbacks' => [
                'account_key TEXT',
                'account_name TEXT',
                'query_payload JSONB',
                'used_at TIMESTAMP NULL',
                'updated_at TIMESTAMP DEFAULT NOW()',
            ],
            'tiktok_tokens' => [
                'account_key TEXT',
                'account_name TEXT',
                'expire_in INTEGER NULL',
                'access_token_expire_at TIMESTAMP NULL',
                'refresh_token_expire_at TIMESTAMP NULL',
                'granted_scopes JSONB',
                'shop_id TEXT',
                'request_id TEXT',
                'message TEXT',
                'raw_response JSONB',
                'is_active BOOLEAN DEFAULT TRUE',
                'updated_at TIMESTAMP DEFAULT NOW()',
            ],
            'tiktok_shops' => [
                'shop_id TEXT',
                'raw_response JSONB',
                'shop_cipher TEXT NULL',
                'created_at TIMESTAMP DEFAULT NOW()',
                'updated_at TIMESTAMP DEFAULT NOW()',
            ],
            'tiktok_products' => [
                'image_url TEXT',
                'sku_id TEXT NULL',
            ],
        ] as $table => $columns) {
            foreach ($columns as $definition) {
                DB::statement('ALTER TABLE '.$table.' ADD COLUMN IF NOT EXISTS '.$definition);
            }
        }
    }

    private function shopeeConfig(): array
    {
        $row = SchemaCache::activeShopeeConfig();

        $partnerId = (int) ($row->partner_id ?? config('shopee.partner_id'));
        $partnerKey = (string) ($row->partner_key ?? config('shopee.partner_key'));

        abort_if($partnerId <= 0 || $partnerKey === '', 422, 'Konfigurasi Shopee belum lengkap.');

        return [
            'partner_id' => $partnerId,
            'partner_key' => $partnerKey,
            'host' => rtrim((string) ($row->host ?? config('shopee.host')), '/'),
            'redirect_url' => (string) ($row->redirect_url ?? config('shopee.redirect_url')),
        ];
    }

    private function generateShopeeSign(int $partnerId, string $partnerKey, string $path, int $timestamp): string
    {
        return hash_hmac('sha256', $partnerId.$path.$timestamp, $partnerKey);
    }

    private function generateShopeeApiSign(int $partnerId, string $partnerKey, string $path, int $timestamp, string $accessToken, int $shopId): string
    {
        return hash_hmac('sha256', $partnerId.$path.$timestamp.$accessToken.$shopId, $partnerKey);
    }

    private function ensureShopeeProductTables(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS shopee_product (
                item_id BIGINT PRIMARY KEY,
                shop_id BIGINT NULL,
                name TEXT NULL,
                description TEXT NULL,
                category_id BIGINT NULL,
                price_min BIGINT DEFAULT 0,
                price_max BIGINT DEFAULT 0,
                price_before_discount BIGINT DEFAULT 0,
                currency TEXT NULL,
                stock INTEGER DEFAULT 0,
                sold INTEGER DEFAULT 0,
                liked_count INTEGER DEFAULT 0,
                rating NUMERIC(8,2) DEFAULT 0,
                historical_sold INTEGER DEFAULT 0,
                status TEXT NULL,
                create_time TIMESTAMP NULL,
                update_time TIMESTAMP NULL,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS shopee_product_model (
                model_id TEXT NOT NULL,
                item_id BIGINT NOT NULL,
                name TEXT NULL,
                price BIGINT DEFAULT 0,
                stock INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW(),
                PRIMARY KEY (model_id, item_id)
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS shopee_product_image (
                id BIGSERIAL PRIMARY KEY,
                item_id BIGINT NOT NULL,
                model_id TEXT NULL,
                image_url TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS stock_master (
                id BIGSERIAL PRIMARY KEY,
                internal_sku TEXT UNIQUE NOT NULL,
                shopee_product_id TEXT NULL,
                shopee_sku TEXT NULL,
                shopee_seller_sku TEXT NULL,
                product_name TEXT NULL,
                variant_name TEXT NULL,
                stock_qty INTEGER DEFAULT 0,
                tiktok_product_id TEXT NULL,
                tiktok_sku TEXT NULL,
                tiktok_seller_sku TEXT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS shopee_sync_logs (
                id BIGSERIAL PRIMARY KEY,
                status TEXT NULL,
                message TEXT NULL,
                product_count INTEGER DEFAULT 0,
                variant_count INTEGER DEFAULT 0,
                synced_at TIMESTAMP DEFAULT NOW(),
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        foreach ([
            'shopee_product' => ['created_at TIMESTAMP DEFAULT NOW()', 'updated_at TIMESTAMP DEFAULT NOW()'],
            'shopee_product_model' => ['created_at TIMESTAMP DEFAULT NOW()'],
            'shopee_product_image' => ['updated_at TIMESTAMP DEFAULT NOW()'],
            'stock_master' => ['created_at TIMESTAMP DEFAULT NOW()', 'updated_at TIMESTAMP DEFAULT NOW()', 'shopee_seller_sku TEXT NULL', 'tiktok_product_id TEXT NULL', 'tiktok_sku TEXT NULL', 'tiktok_seller_sku TEXT NULL'],
        ] as $table => $columns) {
            foreach ($columns as $definition) {
                DB::statement('ALTER TABLE '.$table.' ADD COLUMN IF NOT EXISTS '.$definition);
            }
        }
    }

    private function shopeePrice(mixed $value): int
    {
        $number = $this->toInt($value);

        if (abs($number) > 1000000) {
            return (int) floor($number / 100000);
        }

        return $number;
    }

    private function shopeePriceInfoValue(mixed $priceInfo, string $key, mixed $fallback = 0): mixed
    {
        if (is_array($priceInfo)) {
            if (array_key_exists($key, $priceInfo)) {
                return $priceInfo[$key];
            }

            if (isset($priceInfo[0]) && is_array($priceInfo[0]) && array_key_exists($key, $priceInfo[0])) {
                return $priceInfo[0][$key];
            }
        }

        return $fallback;
    }

    private function shopeeStock(array $item): int
    {
        $stock = data_get($item, 'stock_info.normal_stock');

        if ($stock === null) {
            $stock = data_get($item, 'stock_info.0.normal_stock', $item['stock'] ?? 0);
        }

        return $this->toInt($stock);
    }

    private function shopeeModelStock(array $model): int
    {
        $stock = data_get($model, 'stock_info_v2.summary_info.total_available_stock');

        if ($stock !== null) {
            return $this->toInt($stock);
        }

        return $this->toInt($model['stock'] ?? 0);
    }

    private function toInt(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $normalized = preg_replace('/[^\d.-]/', '', (string) $value);

        return is_numeric($normalized) ? (int) $normalized : 0;
    }

    private function timestampToDate(mixed $value): ?Carbon
    {
        $timestamp = $this->toInt($value);

        return $timestamp > 0 ? Carbon::createFromTimestamp($timestamp) : null;
    }

    private function timestampToDateString(mixed $value): ?string
    {
        return $this->timestampToDate($value)?->toDateTimeString();
    }

    private function sanitizeSkuFragment(string $value): string
    {
        $normalized = strtoupper(trim($value));
        $normalized = preg_replace('/[^A-Z0-9_-]+/', '-', $normalized);
        $normalized = trim((string) $normalized, '-');

        return substr($normalized !== '' ? $normalized : 'X', 0, 30);
    }

    private function resolveAccountFromAction(string $action): ?array
    {
        foreach (self::MARKETPLACE_ACCOUNTS as $key => $account) {
            if (str_ends_with($action, $key)) {
                return ['key' => $key, ...$account];
            }
        }

        return match ($action) {
            'connect-shopee', 'auth-shopee', 'get-token-shopee', 'refresh-token-shopee' => $this->resolveAccount('shopee-agnishopbjm', 'shopee'),
            'connect-tiktok', 'auth-tiktok', 'get-token-tiktok', 'refresh-token-tiktok', 'get-auth-shop-tiktok' => $this->resolveAccount('tiktok-agnishopbjm', 'tiktok'),
            default => null,
        };
    }

    private function resolveAccount(string $key, string $channel): array
    {
        $resolvedKey = array_key_exists($key, self::MARKETPLACE_ACCOUNTS)
            ? $key
            : ($channel === 'tiktok' ? 'tiktok-agnishopbjm' : 'shopee-agnishopbjm');
        $account = self::MARKETPLACE_ACCOUNTS[$resolvedKey];

        abort_if($account['channel'] !== $channel, 422, 'Klasifikasi akun marketplace tidak valid.');

        return ['key' => $resolvedKey, ...$account];
    }
}

final class SchemaCache
{
    public static function activeShopeeConfig(): ?object
    {
        try {
            return DB::table('shopee_config')->whereRaw('is_active = true')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function activeTiktokConfig(): ?object
    {
        try {
            return DB::table('tiktok_config')->whereRaw('is_active = true')->orderByDesc('id')->first()
                ?: DB::table('tiktok_config')->orderByDesc('id')->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
