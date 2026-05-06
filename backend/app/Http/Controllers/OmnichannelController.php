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

    public function shopeeItems(): JsonResponse
    {
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
                'update_time'
            )
            ->orderBy('name')
            ->get();

        $models = DB::table('shopee_product_model')
            ->select('item_id', 'model_id', 'name', 'price', 'stock', 'updated_at')
            ->orderBy('name')
            ->get()
            ->groupBy('item_id');

        $images = Schema::hasTable('shopee_product_image')
            ? DB::table('shopee_product_image')
                ->select('item_id', DB::raw('MIN(image_url) as image_url'))
                ->whereNotNull('image_url')
                ->groupBy('item_id')
                ->pluck('image_url', 'item_id')
            : collect();

        return response()->json([
            'count' => $products->count(),
            'items' => $products->map(fn ($item, int $index) => [
                'no' => $index + 1,
                'item_id' => (string) $item->item_id,
                'shop_id' => $item->shop_id ? (string) $item->shop_id : null,
                'shop_name' => 'Agni Shop Banjarmasin',
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
                'updated_at' => $item->update_time,
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

    private function isLiveShopeeStatus(?string $status): bool
    {
        $normalized = strtoupper(trim((string) $status));

        return $normalized === '' || in_array($normalized, ['NORMAL', 'LIVE', 'PUBLISHED', 'ACTIVE'], true);
    }

    public function tokenAction(string $action): JsonResponse
    {
        $account = $this->resolveAccountFromAction($action);

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
        $rows = DB::table('tiktok_products')
            ->select('product_id', 'product_name', 'sku_name', 'stock_qty', 'price', 'subtotal', 'updated_at')
            ->orderBy('product_name')
            ->orderBy('sku_name')
            ->get()
            ->groupBy('product_id');

        return response()->json([
            'count' => $rows->count(),
            'items' => $rows->map(function ($group, string $productId) {
                $first = $group->first();

                return [
                    'product_id' => $productId,
                    'product_name' => $first->product_name,
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
                'is_active' => (bool) $token->is_active,
                'created_at' => $token->created_at,
            ])
            ->all();
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

                return [
                    'id' => $row['id'] ?? null,
                    'account_key' => $row['account_key'] ?? 'tiktok-agnishopbjm',
                    'account_name' => $row['account_name'] ?? 'TikTok AgniShopBJM',
                    'shop_id' => $row['shop_id'] ?? $row['seller_id'] ?? $row['shop_cipher'] ?? null,
                    'access_token' => $this->maskToken($row['access_token'] ?? null),
                    'refresh_token' => $this->maskToken($row['refresh_token'] ?? null),
                    'expire_in' => $row['expire_in'] ?? $row['expires_in'] ?? null,
                    'expire_at' => $row['expire_at'] ?? $row['access_token_expire_at'] ?? null,
                    'request_id' => $row['request_id'] ?? null,
                    'error' => $row['error'] ?? null,
                    'message' => $row['message'] ?? null,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'created_at' => $row['created_at'] ?? null,
                ];
            })
            ->all();
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

        return $config['auth_host'].'/openapi/v2/oauth/authorize?'.http_build_query([
            'app_key' => $config['app_key'],
            'state' => $account['key'],
            'redirect_uri' => $config['redirect_url'],
        ]);
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

        DB::table('tiktok_tokens')->insert([
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'open_id' => $data['open_id'] ?? null,
            'seller_name' => $data['seller_name'] ?? null,
            'seller_region' => $data['seller_base_region'] ?? null,
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'expire_at' => $expireAt,
            'expire_in' => $expireAt ? now()->diffInSeconds($expireAt, false) : null,
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
            DB::table('tiktok_shops')->updateOrInsert(
                ['id' => (string) ($shop['id'] ?? '')],
                [
                    'code' => $shop['code'] ?? null,
                    'name' => $shop['name'] ?? null,
                    'region' => $shop['region'] ?? null,
                    'seller_type' => $shop['seller_type'] ?? null,
                    'cipher' => $shop['cipher'] ?? null,
                    'raw_response' => json_encode($shop),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
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

    private function tiktokConfig(): array
    {
        $row = SchemaCache::activeTiktokConfig();

        $appKey = (string) ($row->app_key ?? config('tiktok.app_key'));
        $appSecret = (string) ($row->app_secret ?? config('tiktok.app_secret'));

        abort_if($appKey === '' || $appSecret === '', 422, 'Konfigurasi TikTok belum lengkap.');

        return [
            'app_key' => $appKey,
            'app_secret' => $appSecret,
            'auth_host' => rtrim((string) config('tiktok.auth_host'), '/'),
            'api_host' => rtrim((string) config('tiktok.api_host'), '/'),
            'redirect_url' => (string) ($row->redirect_url ?? config('tiktok.redirect_url')),
        ];
    }

    private function generateTiktokSign(string $path, array $params, string $secret, ?string $body = null): string
    {
        unset($params['sign'], $params['access_token']);
        ksort($params);

        $input = $path;
        foreach ($params as $key => $value) {
            $input .= $key.$value;
        }

        if ($body !== null && $body !== '') {
            $input .= $body;
        }

        $input = $secret.$input.$secret;

        return hash_hmac('sha256', $input, $secret);
    }

    private function ensureTiktokAuthTables(): void
    {
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
                code TEXT,
                name TEXT,
                region TEXT,
                seller_type TEXT,
                cipher TEXT,
                raw_response JSONB,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

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
                'raw_response JSONB',
                'created_at TIMESTAMP DEFAULT NOW()',
                'updated_at TIMESTAMP DEFAULT NOW()',
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

    private function resolveAccountFromAction(string $action): ?array
    {
        foreach (self::MARKETPLACE_ACCOUNTS as $key => $account) {
            if (str_ends_with($action, $key)) {
                return ['key' => $key, ...$account];
            }
        }

        return match ($action) {
            'auth-shopee', 'get-token-shopee', 'refresh-token-shopee' => $this->resolveAccount('shopee-agnishopbjm', 'shopee'),
            'auth-tiktok', 'get-token-tiktok', 'refresh-token-tiktok', 'get-auth-shop-tiktok' => $this->resolveAccount('tiktok-agnishopbjm', 'tiktok'),
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
            return DB::table('tiktok_config')->orderByDesc('id')->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
