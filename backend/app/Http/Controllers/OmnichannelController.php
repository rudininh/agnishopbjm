<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class OmnichannelController extends Controller
{
    public function dashboard(): JsonResponse
    {
        return response()->json([
            'summary' => [
                'stock_master' => DB::table('stock_master')->count(),
                'shopee_products' => DB::table('shopee_product')->count(),
                'shopee_variants' => DB::table('shopee_product_model')->count(),
                'tiktok_products' => DB::table('tiktok_products')->distinct('product_id')->count('product_id'),
                'tiktok_skus' => DB::table('tiktok_products')->count(),
                'sku_mappings' => DB::table('sku_mapping')->count(),
                'shopee_tokens' => DB::table('shopee_tokens')->count(),
                'tiktok_tokens' => DB::table('tiktok_tokens')->count(),
            ],
            'tokens' => [
                'shopee' => DB::table('shopee_tokens')->latest('created_at')->first(),
                'tiktok' => DB::table('tiktok_tokens')->latest('created_at')->first(),
            ],
        ]);
    }

    public function shopeeItems(): JsonResponse
    {
        $products = DB::table('shopee_product')
            ->select('item_id', 'name', 'stock', 'price_min', 'price_max', 'status', 'update_time')
            ->orderBy('name')
            ->get();

        $models = DB::table('shopee_product_model')
            ->select('item_id', 'model_id', 'name', 'price', 'stock', 'updated_at')
            ->orderBy('name')
            ->get()
            ->groupBy('item_id');

        return response()->json([
            'count' => $products->count(),
            'items' => $products->map(fn ($item, int $index) => [
                'no' => $index + 1,
                'item_id' => (string) $item->item_id,
                'nama' => $item->name,
                'sku' => (string) $item->item_id,
                'stok' => (int) ($item->stock ?? 0),
                'harga' => $this->formatRupiah((int) ($item->price_min ?? $item->price_max ?? 0)),
                'status' => $item->status,
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

    public function tokenAction(string $action): JsonResponse
    {
        if ($action === 'auth-shopee') {
            return response()->json([
                'status' => 'redirect',
                'action' => $action,
                'message' => 'Membuka halaman authorization Shopee.',
                'redirect_url' => $this->buildShopeeAuthUrl(),
            ]);
        }

        if ($action === 'get-token-shopee') {
            $callback = DB::table('shopee_callbacks')
                ->whereNull('used_at')
                ->latest('created_at')
                ->first();

            if (! $callback) {
                return response()->json([
                    'status' => 'error',
                    'action' => $action,
                    'message' => 'Belum ada callback Shopee yang bisa ditukar menjadi token. Klik AUTH SHOPEE dulu.',
                ], 422);
            }

            return response()->json($this->exchangeShopeeToken($callback));
        }

        $labels = [
            'auth-shopee' => 'AUTH SHOPEE',
            'auth-tiktok' => 'AUTH TIKTOK',
            'get-token-shopee' => 'GET TOKEN SHOPEE',
            'get-token-tiktok' => 'GET TOKEN TIKTOK',
            'refresh-token-shopee' => 'REFRESH TOKEN SHOPEE',
            'refresh-token-tiktok' => 'REFRESH TOKEN TIKTOK',
            'get-auth-shop-tiktok' => 'GET AUTH SHOP TIKTOK',
        ];

        return response()->json([
            'status' => 'ok',
            'action' => $action,
            'message' => ($labels[$action] ?? strtoupper($action)).' diproses. Hubungkan logic OAuth/token Go lama di endpoint ini.',
        ]);
    }

    public function shopeeCallback(Request $request): Response
    {
        $code = $request->query('code');

        if (! $code) {
            return response('Callback Shopee tidak membawa code.', 422);
        }

        $callbackId = DB::table('shopee_callbacks')->insertGetId([
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

        return response(
            '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>'.$title.'</title></head><body style="font-family:Arial,sans-serif;padding:32px;line-height:1.5"><h1>'.$title.'</h1><p>'.$message.'</p><pre style="background:#f3f4f6;padding:16px;border-radius:8px;white-space:pre-wrap">'.e(json_encode($result, JSON_PRETTY_PRINT)).'</pre><p><a href="/dashboard">Kembali ke Dashboard</a></p></body></html>',
            $ok ? 200 : 422
        )->header('Content-Type', 'text/html');
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

    private function buildShopeeAuthUrl(): string
    {
        $config = $this->shopeeConfig();
        $path = '/api/v2/shop/auth_partner';
        $timestamp = time();
        $sign = $this->generateShopeeSign($config['partner_id'], $config['partner_key'], $path, $timestamp);

        return $config['host'].$path.'?'.http_build_query([
            'partner_id' => $config['partner_id'],
            'timestamp' => $timestamp,
            'sign' => $sign,
            'redirect' => $config['redirect_url'],
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
            'action' => 'get-token-shopee',
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
                ->update(['is_active' => DB::raw('false'), 'updated_at' => now()]);
        }

        DB::table('shopee_tokens')->insert([
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

    private function resolveShopeeExpireAt(?int $expireIn): ?Carbon
    {
        if (! $expireIn) {
            return null;
        }

        return $expireIn > time()
            ? Carbon::createFromTimestamp($expireIn)
            : now()->addSeconds($expireIn);
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
}
