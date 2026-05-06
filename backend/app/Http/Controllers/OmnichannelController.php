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

    public function dashboard(): JsonResponse
    {
        $this->normalizeActiveMarketplaceTokens();

        return response()->json([
            'summary' => [
                'stock_master' => $this->tableCount('stock_master'),
                'shopee_products' => $this->tableCount('shopee_product'),
                'shopee_variants' => $this->tableCount('shopee_product_model'),
                'tiktok_products' => Schema::hasTable('tiktok_products')
                    ? DB::table('tiktok_products')->distinct('product_id')->count('product_id')
                    : 0,
                'tiktok_skus' => $this->tableCount('tiktok_products'),
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

        $images = DB::table('shopee_product_image')
            ->select('item_id', DB::raw('MIN(image_url) as image_url'))
            ->whereNotNull('image_url')
            ->groupBy('item_id')
            ->pluck('image_url', 'item_id');

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
                'image_url' => $images[$item->item_id] ?? null,
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
                    'updated_at' => $model->updated_at,
                ])->values(),
            ])->values(),
        ]);
    }

    private function syncShopeeProductsToDatabase(): array
    {
        $this->normalizeActiveMarketplaceTokens();

        if (! Schema::hasTable('shopee_tokens')) {
            return [
                'status' => 'error',
                'message' => 'Tabel token Shopee belum tersedia.',
                'accounts' => [],
            ];
        }

        $tokens = DB::table('shopee_tokens')
            ->whereRaw('is_active = true')
            ->whereNotNull('shop_id')
            ->whereNotNull('access_token')
            ->orderBy('account_name')
            ->get();

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
                    $models = $this->fetchShopeeModelList($config, $shopId, $accessToken, (int) ($baseItem['item_id'] ?? 0));
                    $variantCount += max(1, count($models));
                    $this->storeShopeeProductPayload($baseItem, $models, $shopId);
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

        return data_get($response, 'response.model', []);
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

    private function storeShopeeProductPayload(array $item, array $models, int $shopId): void
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

            if (! DB::table('shopee_product_image')->where('item_id', $itemId)->where('model_id', $modelId)->where('image_url', $url)->exists()) {
                DB::table('shopee_product_image')->insert([
                    'item_id' => $itemId,
                    'model_id' => $modelId,
                    'image_url' => $url,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function isLiveShopeeStatus(?string $status): bool
    {
        $normalized = strtoupper(trim((string) $status));

        return $normalized === '' || in_array($normalized, ['NORMAL', 'LIVE', 'PUBLISHED', 'ACTIVE'], true);
    }

    public function tokenAction(string $action): JsonResponse
    {
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
            ->select('product_id', 'product_name', 'image_url', 'sku_name', 'stock_qty', 'price', 'subtotal', 'updated_at')
            ->orderBy('product_name')
            ->orderBy('sku_name')
            ->get()
            ->groupBy('product_id');

        $lastSyncAt = Schema::hasTable('tiktok_products')
            ? DB::table('tiktok_products')->max('updated_at')
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
                        'sku_name' => $sku->sku_name,
                        'stock_qty' => (int) ($sku->stock_qty ?? 0),
                        'price' => (int) ($sku->price ?? 0),
                        'subtotal' => (int) ($sku->subtotal ?? 0),
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
            $accessToken = $this->latestTiktokAccessToken();

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
                $searchParams = [
                    'app_key' => $config['app_key'],
                    'timestamp' => $timestamp,
                    'shop_cipher' => $shop->cipher,
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
                    ->withHeaders(['x-tts-access-token' => $accessToken])
                    ->withBody($searchBodyString, 'application/json')
                    ->post($searchUrl.'?'.http_build_query($searchParams, '', '&', PHP_QUERY_RFC3986));

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
            'access_token' => $accessToken,
            'timestamp' => $timestamp,
            'app_key' => $config['app_key'],
            'shop_cipher' => $shopCipher,
            'shop_id' => $shopId,
            'return_under_review_version' => 'false',
            'return_draft_version' => 'false',
            'locale' => 'en',
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
        $imageUrl = $this->extractTiktokImageUrl($data);
        $skus = data_get($data, 'skus', []);

        if (! is_array($skus) || $skus === []) {
            $skus = [[
                'id' => $productId.'-default',
                'sku_name' => 'Default',
                'stock' => data_get($data, 'stock', 0),
                'price' => ['sale_price' => data_get($data, 'price', 0)],
            ]];
        }

        foreach ($skus as $sku) {
            $skuName = (string) ($sku['sku_name'] ?? $sku['name'] ?? 'Default');
            $price = (int) data_get($sku, 'price.sale_price', data_get($sku, 'price', 0));
            $stock = (int) data_get($sku, 'inventory.0.quantity', data_get($sku, 'stock', 0));

            DB::table('tiktok_products')->updateOrInsert(
                ['product_id' => $productId, 'sku_name' => $skuName],
                [
                    'product_name' => $productName,
                    'image_url' => $imageUrl,
                    'stock_qty' => $stock,
                    'price' => $price,
                    'subtotal' => $price * $stock,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    private function extractTiktokImageUrl(array $data): ?string
    {
        $mainImages = data_get($data, 'main_images', []);

        if (is_array($mainImages)) {
            foreach ($mainImages as $image) {
                $urls = data_get($image, 'urls', []);
                if (is_array($urls) && ! empty($urls[0])) {
                    return (string) $urls[0];
                }

                $thumbUrls = data_get($image, 'thumb_urls', []);
                if (is_array($thumbUrls) && ! empty($thumbUrls[0])) {
                    return (string) $thumbUrls[0];
                }
            }
        }

        $skus = data_get($data, 'skus', []);

        if (is_array($skus)) {
            foreach ($skus as $sku) {
                $skuImage = data_get($sku, 'sales_attributes.0.sku_img', []);
                $urls = data_get($skuImage, 'urls', []);
                if (is_array($urls) && ! empty($urls[0])) {
                    return (string) $urls[0];
                }

                $thumbUrls = data_get($skuImage, 'thumb_urls', []);
                if (is_array($thumbUrls) && ! empty($thumbUrls[0])) {
                    return (string) $thumbUrls[0];
                }
            }
        }

        return null;
    }

    private function latestTiktokAccessToken(): string
    {
        return (string) (DB::table('tiktok_tokens')->whereRaw('is_active = true')->orderByDesc('created_at')->value('access_token') ?? DB::table('tiktok_tokens')->orderByDesc('created_at')->value('access_token') ?? '');
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
                stock_qty INTEGER DEFAULT 0,
                price BIGINT DEFAULT 0,
                subtotal BIGINT DEFAULT 0,
                updated_at TIMESTAMP DEFAULT NOW(),
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");

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

    public function stockMaster(): JsonResponse
    {
        $summary = DB::selectOne("
            SELECT
                COUNT(*) FILTER (
                    WHERE EXISTS (
                        SELECT 1 FROM tiktok_products tp
                        WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
                          AND LOWER(TRIM(tp.sku_name)) = LOWER(TRIM(sm.variant_name))
                    )
                ) AS total_match,
                COUNT(*) FILTER (
                    WHERE EXISTS (
                        SELECT 1 FROM tiktok_products tp
                        WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM tiktok_products tp
                        WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
                          AND LOWER(TRIM(tp.sku_name)) = LOWER(TRIM(sm.variant_name))
                    )
                ) AS total_variant_missing,
                COUNT(*) FILTER (
                    WHERE NOT EXISTS (
                        SELECT 1 FROM tiktok_products tp
                        WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
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
                    ->on('tp.sku_name', '=', 'sm.variant_name');
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

        if ($token && $this->tokenDateIsFuture($token->expire_at ?? null)) {
            $result = $this->refreshShopeeToken($account);

            if (($result['status'] ?? '') === 'ok') {
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

        if ($token && $this->tokenDateIsFuture($token->refresh_token_expire_at ?? null)) {
            $result = $this->refreshTiktokToken($account);

            if (($result['status'] ?? '') === 'ok') {
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
            'status' => $ok ? 'ok' : 'error',
            'action' => 'refresh-token-'.$account['key'],
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'message' => $ok ? 'Refresh token TikTok berhasil. Token baru sudah disimpan.' : ($data['message'] ?? 'TikTok mengembalikan error.'),
            ...$data,
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

    private function getTiktokAuthorizedShops(array $account): array
    {
        $this->ensureTiktokAuthTables();

        $token = DB::table('tiktok_tokens')
            ->where('account_key', $account['key'])
            ->whereNotNull('access_token')
            ->latest('created_at')
            ->first();

        if (! $token) {
            return [
                'status' => 'error',
                'action' => 'get-auth-shop-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Belum ada access token TikTok. Jalankan AUTH dan GET TOKEN dulu.',
            ];
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

        try {
            return Carbon::parse($value)->isFuture();
        } catch (\Throwable) {
            return false;
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
        $appKey = trim((string) ($row->app_key ?? config('tiktok.app_key')));
        $appSecret = trim((string) ($row->app_secret ?? config('tiktok.app_secret')));
        $redirectUrl = trim((string) ($row->redirect_url ?? config('tiktok.redirect_url')));
        $authHost = trim((string) config('tiktok.auth_host'));
        $apiHost = trim((string) config('tiktok.api_host'));

        if ($appKey === '' || $appSecret === '') {
            abort(422, 'Konfigurasi TikTok belum lengkap. Isi `tiktok_config` atau `TIKTOK_APP_KEY` / `TIKTOK_APP_SECRET`.');
        }

        return [
            'app_key' => $appKey,
            'app_secret' => $appSecret,
            'auth_host' => rtrim($authHost, '/'),
            'api_host' => rtrim($apiHost, '/'),
            'redirect_url' => $redirectUrl,
        ];
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

        $tiktokConfigValues = [
            'app_key' => env('TIKTOK_APP_KEY', '6i1cagd9f0p83'),
            'app_secret' => env('TIKTOK_APP_SECRET', '310f006ea5810ad4bf1591f31acd048fbee7977a'),
            'auth_host' => env('TIKTOK_AUTH_HOST', 'https://auth.tiktok-shops.com'),
            'api_host' => env('TIKTOK_API_HOST', 'https://open-api.tiktokglobalshop.com'),
            'redirect_url' => env('TIKTOK_REDIRECT_URL', env('APP_URL', 'http://localhost:8000').'/api/tiktok/callback'),
            'is_active' => DB::raw('true'),
            'updated_at' => now(),
        ];

        $existingTiktokConfig = DB::table('tiktok_config')->where('id', 1)->exists();

        if ($existingTiktokConfig) {
            DB::table('tiktok_config')
                ->where('id', 1)
                ->update($tiktokConfigValues);
        } else {
            DB::table('tiktok_config')->insert([
                'id' => 1,
                ...$tiktokConfigValues,
                'created_at' => now(),
            ]);
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
                product_name TEXT NULL,
                variant_name TEXT NULL,
                stock_qty INTEGER DEFAULT 0,
                tiktok_product_id TEXT NULL,
                tiktok_sku TEXT NULL,
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
            'stock_master' => ['created_at TIMESTAMP DEFAULT NOW()', 'updated_at TIMESTAMP DEFAULT NOW()', 'tiktok_product_id TEXT NULL', 'tiktok_sku TEXT NULL'],
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
