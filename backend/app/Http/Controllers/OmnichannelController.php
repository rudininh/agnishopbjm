<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
}
