<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class MobileProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $this->ensureMobileProductColumns();

            $search = trim((string) $request->input('search', ''));
            $marketplace = (string) $request->input('marketplace', 'all');
            $perPage = max(1, min(100, (int) $request->input('per_page', 20)));

            $products = $this->buildProductQuery($search, $marketplace)->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $products->items(),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                ],
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat produk: '.$exception->getMessage(),
            ], 500);
        }
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $this->ensureMobileProductColumns();

            $productId = trim((string) $request->input('product_id', ''));
            $marketplace = (string) $request->input('marketplace', 'shopee');

            abort_if($productId === '', 422, 'Product ID wajib diisi.');

            $product = $marketplace === 'tiktok'
                ? $this->getTiktokProductDetail($productId)
                : $this->getShopeeProductDetail($productId);

            return response()->json([
                'success' => true,
                'data' => $product,
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail produk: '.$exception->getMessage(),
            ], $exception->getCode() >= 400 && $exception->getCode() < 600 ? $exception->getCode() : 500);
        }
    }

    public function updateStockPrice(Request $request): JsonResponse
    {
        try {
            $this->ensureMobileProductColumns();

            $validator = Validator::make($request->all(), [
                'marketplace' => 'required|in:shopee,tiktok',
                'product_id' => 'required|string',
                'variant_id' => 'required|string',
                'stock' => 'required|integer|min:0',
                'price' => 'required|numeric|min:0',
                'cost_price' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak valid',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $marketplace = (string) $request->input('marketplace');
            $productId = (string) $request->input('product_id');
            $variantId = (string) $request->input('variant_id');
            $stock = (int) $request->input('stock');
            $price = (float) $request->input('price');
            $costPrice = $request->filled('cost_price') ? (float) $request->input('cost_price') : null;

            $result = $marketplace === 'tiktok'
                ? $this->updateTiktokVariant($productId, $variantId, $stock, $price, $costPrice)
                : $this->updateShopeeVariant($productId, $variantId, $stock, $price, $costPrice);

            return response()->json([
                'success' => true,
                'message' => 'Stok dan harga berhasil diupdate',
                'data' => $result,
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal update stok dan harga: '.$exception->getMessage(),
            ], 500);
        }
    }

    public function updateCostPrice(Request $request): JsonResponse
    {
        try {
            $this->ensureMobileProductColumns();

            $validator = Validator::make($request->all(), [
                'marketplace' => 'required|in:shopee,tiktok',
                'product_id' => 'required|string',
                'variant_id' => 'required|string',
                'cost_price' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak valid',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $marketplace = (string) $request->input('marketplace');
            $productId = (string) $request->input('product_id');
            $variantId = (string) $request->input('variant_id');
            $costPrice = (float) $request->input('cost_price');

            $updated = $marketplace === 'tiktok'
                ? $this->tiktokVariantQuery($productId, $variantId)->update(['cost_price' => $costPrice, 'updated_at' => now()])
                : DB::table('shopee_product_model')
                    ->where('item_id', $productId)
                    ->where('model_id', $variantId)
                    ->update(['cost_price' => $costPrice, 'updated_at' => now()]);

            abort_if($updated < 1, 404, 'Varian tidak ditemukan.');

            return response()->json([
                'success' => true,
                'message' => 'Harga modal berhasil diupdate',
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal update harga modal: '.$exception->getMessage(),
            ], $exception->getCode() >= 400 && $exception->getCode() < 600 ? $exception->getCode() : 500);
        }
    }

    public function profitCalculation(Request $request): JsonResponse
    {
        try {
            $this->ensureMobileProductColumns();

            $marketplace = (string) $request->input('marketplace', 'shopee');
            $productId = (string) $request->input('product_id');
            $variantId = (string) $request->input('variant_id');

            $variant = $marketplace === 'tiktok'
                ? $this->tiktokVariantQuery($productId, $variantId)->first()
                : DB::table('shopee_product_model')
                    ->where('item_id', $productId)
                    ->where('model_id', $variantId)
                    ->first();

            abort_if(! $variant, 404, 'Varian tidak ditemukan.');

            $price = (float) ($variant->price ?? 0);
            $costPrice = (float) ($variant->cost_price ?? 0);
            $grossProfit = $price - $costPrice;

            return response()->json([
                'success' => true,
                'data' => [
                    'price' => $price,
                    'cost_price' => $costPrice,
                    'gross_profit' => $grossProfit,
                    'gross_profit_margin' => $price > 0 ? round(($grossProfit / $price) * 100, 2) : 0,
                ],
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghitung profit: '.$exception->getMessage(),
            ], $exception->getCode() >= 400 && $exception->getCode() < 600 ? $exception->getCode() : 500);
        }
    }

    private function buildProductQuery(string $search, string $marketplace)
    {
        $shopeeImages = DB::table('shopee_product_image')
            ->select('item_id', DB::raw('MIN(image_url) as image_url'))
            ->whereNull('model_id')
            ->groupBy('item_id');

        $shopeeQuery = DB::table('shopee_product as sp')
            ->leftJoinSub($shopeeImages, 'spi', 'spi.item_id', '=', 'sp.item_id')
            ->select([
                DB::raw("'shopee' as marketplace"),
                DB::raw('CAST(sp.item_id AS TEXT) as product_id'),
                DB::raw('CAST(sp.item_id AS TEXT) as shopee_product_id'),
                DB::raw('NULL as tiktok_product_id'),
                'sp.name as product_name',
                'sp.price_min as shopee_price',
                'sp.stock as shopee_stock',
                'spi.image_url as shopee_image',
                DB::raw('NULL as tiktok_price'),
                DB::raw('NULL as tiktok_stock'),
                DB::raw('NULL as tiktok_image'),
                'sp.updated_at',
            ])
            ->whereRaw('COALESCE(sp.is_active, true) = true')
            ->where(function ($query) {
                $query->whereNull('sp.status')->orWhere('sp.status', '!=', 'DELETED');
            });

        if ($search !== '') {
            $shopeeQuery->where(function ($query) use ($search) {
                $query->where('sp.name', 'like', "%{$search}%")
                    ->orWhereExists(function ($subQuery) use ($search) {
                        $subQuery->select(DB::raw(1))
                            ->from('shopee_product_model as spm')
                            ->whereColumn('spm.item_id', 'sp.item_id')
                            ->where(function ($modelQuery) use ($search) {
                                $modelQuery->where('spm.name', 'like', "%{$search}%")
                                    ->orWhere('spm.model_sku', 'like', "%{$search}%");
                            });
                    });
            });
        }

        $tiktokQuery = DB::table('tiktok_products as tp')
            ->select([
                DB::raw("'tiktok' as marketplace"),
                'tp.product_id',
                DB::raw('NULL as shopee_product_id'),
                'tp.product_id as tiktok_product_id',
                'tp.product_name',
                DB::raw('NULL as shopee_price'),
                DB::raw('NULL as shopee_stock'),
                DB::raw('NULL as shopee_image'),
                DB::raw('MIN(tp.price) as tiktok_price'),
                DB::raw('SUM(tp.stock_qty) as tiktok_stock'),
                DB::raw('MIN(tp.image_url) as tiktok_image'),
                DB::raw('MAX(tp.updated_at) as updated_at'),
            ])
            ->whereRaw('COALESCE(tp.is_active, true) = true')
            ->groupBy('tp.product_id', 'tp.product_name');

        if ($search !== '') {
            $tiktokQuery->where(function ($query) use ($search) {
                $query->where('tp.product_name', 'like', "%{$search}%")
                    ->orWhere('tp.sku_name', 'like', "%{$search}%")
                    ->orWhere('tp.seller_sku', 'like', "%{$search}%");
            });
        }

        if ($marketplace === 'shopee') {
            return $shopeeQuery->orderByDesc('sp.updated_at');
        }

        if ($marketplace === 'tiktok') {
            return $tiktokQuery->orderByDesc(DB::raw('MAX(tp.updated_at)'));
        }

        $unionQuery = $shopeeQuery->unionAll($tiktokQuery);

        return DB::query()
            ->fromSub($unionQuery, 'marketplace_products')
            ->orderByDesc('updated_at');
    }

    private function getShopeeProductDetail(string $productId): array
    {
        $product = DB::table('shopee_product')->where('item_id', $productId)->first();

        abort_if(! $product, 404, 'Produk tidak ditemukan.');

        $variants = DB::table('shopee_product_model as spm')
            ->leftJoin('shopee_product_image as spi', function ($join) {
                $join->on('spi.item_id', '=', 'spm.item_id')
                    ->on('spi.model_id', '=', 'spm.model_id');
            })
            ->where('spm.item_id', $productId)
            ->select([
                'spm.model_id as variant_id',
                'spm.name as variant_name',
                'spm.model_sku as seller_sku',
                'spm.price',
                'spm.cost_price',
                'spm.stock',
                DB::raw('MIN(spi.image_url) as image_url'),
            ])
            ->groupBy('spm.model_id', 'spm.item_id', 'spm.name', 'spm.model_sku', 'spm.price', 'spm.cost_price', 'spm.stock')
            ->orderBy('spm.name')
            ->get();

        $mainImage = DB::table('shopee_product_image')
            ->where('item_id', $productId)
            ->whereNull('model_id')
            ->value('image_url');

        return [
            'product_id' => (string) $product->item_id,
            'product_name' => $product->name,
            'marketplace' => 'shopee',
            'main_image' => $mainImage,
            'variants' => $variants->toArray(),
        ];
    }

    private function getTiktokProductDetail(string $productId): array
    {
        $product = DB::table('tiktok_products')
            ->where('product_id', $productId)
            ->orderByDesc('updated_at')
            ->first();

        abort_if(! $product, 404, 'Produk tidak ditemukan.');

        $variants = DB::table('tiktok_products')
            ->where('product_id', $productId)
            ->select([
                DB::raw("COALESCE(NULLIF(sku_id, ''), CAST(id AS TEXT)) as variant_id"),
                'sku_name as variant_name',
                'seller_sku',
                'price',
                'cost_price',
                'stock_qty as stock',
                'image_url',
            ])
            ->orderBy('sku_name')
            ->get();

        return [
            'product_id' => (string) $product->product_id,
            'product_name' => $product->product_name,
            'marketplace' => 'tiktok',
            'main_image' => $product->image_url,
            'variants' => $variants->toArray(),
        ];
    }

    private function updateShopeeVariant(string $productId, string $variantId, int $stock, float $price, ?float $costPrice): array
    {
        $data = [
            'price' => $price,
            'stock' => $stock,
            'updated_at' => now(),
        ];

        if ($costPrice !== null) {
            $data['cost_price'] = $costPrice;
        }

        $updated = DB::table('shopee_product_model')
            ->where('item_id', $productId)
            ->where('model_id', $variantId)
            ->update($data);

        abort_if($updated < 1, 404, 'Varian tidak ditemukan.');

        $totalStock = DB::table('shopee_product_model')
            ->where('item_id', $productId)
            ->sum('stock');

        DB::table('shopee_product')
            ->where('item_id', $productId)
            ->update(['stock' => $totalStock, 'updated_at' => now()]);

        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'stock' => $stock,
            'price' => $price,
            'cost_price' => $costPrice,
        ];
    }

    private function updateTiktokVariant(string $productId, string $variantId, int $stock, float $price, ?float $costPrice): array
    {
        $data = [
            'price' => $price,
            'stock_qty' => $stock,
            'subtotal' => $price * $stock,
            'updated_at' => now(),
        ];

        if ($costPrice !== null) {
            $data['cost_price'] = $costPrice;
        }

        $updated = $this->tiktokVariantQuery($productId, $variantId)->update($data);

        abort_if($updated < 1, 404, 'Varian tidak ditemukan.');

        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'stock' => $stock,
            'price' => $price,
            'cost_price' => $costPrice,
        ];
    }

    private function tiktokVariantQuery(string $productId, string $variantId)
    {
        return DB::table('tiktok_products')
            ->where('product_id', $productId)
            ->where(function ($query) use ($variantId) {
                $query->where('sku_id', $variantId);

                if (is_numeric($variantId)) {
                    $query->orWhere('id', (int) $variantId);
                }
            });
    }

    private function ensureMobileProductColumns(): void
    {
        if (Schema::hasTable('shopee_product_model') && ! Schema::hasColumn('shopee_product_model', 'cost_price')) {
            DB::statement('ALTER TABLE shopee_product_model ADD COLUMN cost_price NUMERIC(12,2) NULL');
        }

        if (Schema::hasTable('tiktok_products') && ! Schema::hasColumn('tiktok_products', 'cost_price')) {
            DB::statement('ALTER TABLE tiktok_products ADD COLUMN cost_price NUMERIC(12,2) NULL');
        }
    }
}
