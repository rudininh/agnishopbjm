<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function stockMasterProducts(): JsonResponse
    {
        abort_unless(Schema::hasTable('stock_master'), 422, 'Tabel stock_master belum tersedia.');

        $items = DB::table('stock_master as sm')
            ->leftJoin('shopee_product_model as spm', function ($join) {
                $join->on('spm.model_id', '=', 'sm.shopee_sku');
            })
            ->leftJoin('shopee_product as sp', function ($join) {
                $join->on(DB::raw('sp.item_id::TEXT'), '=', 'sm.shopee_product_id');
            })
            ->leftJoin('tiktok_products as tp', function ($join) {
                $join->on('tp.product_id', '=', 'sm.tiktok_product_id')
                    ->on('tp.sku_id', '=', 'sm.tiktok_sku')
                    ->whereRaw('COALESCE(tp.is_active, true) = true');
            })
            ->leftJoin(DB::raw('(SELECT item_id, model_id, MIN(image_url) as image_url FROM shopee_product_image WHERE model_id IS NOT NULL GROUP BY item_id, model_id) as spmi'), function ($join) {
                $join->on(DB::raw('spmi.item_id::TEXT'), '=', 'sm.shopee_product_id')
                    ->on('spmi.model_id', '=', 'sm.shopee_sku');
            })
            ->leftJoin(DB::raw('(SELECT item_id, MIN(image_url) as image_url FROM shopee_product_image WHERE model_id IS NULL GROUP BY item_id) as spi'), function ($join) {
                $join->on(DB::raw('spi.item_id::TEXT'), '=', 'sm.shopee_product_id');
            })
            ->whereRaw('COALESCE(sm.is_hidden_from_mapping, false) = false')
            ->select(
                'sm.id',
                'sm.internal_sku',
                'sm.product_name',
                'sm.variant_name',
                'sm.stock_qty',
                'sm.updated_at',
                'sm.shopee_product_id',
                'sm.shopee_sku',
                'sm.shopee_seller_sku',
                'sm.tiktok_product_id',
                'sm.tiktok_sku',
                'sm.tiktok_seller_sku',
                'sp.name as shopee_product_name',
                'spm.name as shopee_variant_name',
                'spm.model_sku as shopee_model_sku',
                'spm.price as shopee_price',
                'tp.price as tiktok_price',
                'tp.stock_qty as tiktok_stock',
                'tp.image_url as tiktok_image_url',
                'spmi.image_url as shopee_model_image_url',
                'spi.image_url as shopee_product_image_url'
            )
            ->orderBy('sm.product_name')
            ->orderBy('sm.variant_name')
            ->get()
            ->map(fn ($row): array => [
                'stock_master_id' => (int) $row->id,
                'sku' => $this->firstFilledString([
                    $row->internal_sku,
                    $row->shopee_seller_sku,
                    $row->shopee_model_sku,
                    $row->tiktok_seller_sku,
                ]),
                'product_name' => trim((string) ($row->product_name ?: $row->shopee_product_name ?: 'Produk Tanpa Nama')),
                'variant_name' => trim((string) ($row->variant_name ?: $row->shopee_variant_name ?: 'Default')),
                'stock' => (int) ($row->stock_qty ?? 0),
                'price' => $this->stockMasterPrice($row),
                'image_url' => $this->firstFilledString([
                    $row->shopee_model_image_url,
                    $row->tiktok_image_url,
                    $row->shopee_product_image_url,
                ]),
                'shopee_stock' => (int) ($row->stock_qty ?? 0),
                'tiktok_stock' => (int) ($row->tiktok_stock ?? 0),
                'shopee_product_id' => $row->shopee_product_id,
                'shopee_sku' => $row->shopee_sku,
                'tiktok_product_id' => $row->tiktok_product_id,
                'tiktok_sku' => $row->tiktok_sku,
                'updated_at' => $row->updated_at,
            ])
            ->values();

        return response()->json([
            'data' => $items,
            'summary' => [
                'total' => $items->count(),
                'available' => $items->where('stock', '>', 0)->count(),
            ],
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('stock_master'), 422, 'Tabel stock_master belum tersedia.');

        $data = $request->validate([
            'customer_name' => 'nullable|string|max:120',
            'cashier_name' => 'nullable|string|max:120',
            'payment_method' => 'required|string|in:cash,qris',
            'cash_received' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.stock_master_id' => 'required|integer|exists:stock_master,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        $order = DB::transaction(function () use ($data) {
            $deductStock = (bool) config('pos.deduct_stock', false);
            $userId = request()->user()?->getAuthIdentifier()
                ?? User::query()->value('uuid')
                ?? User::query()->create([
                    'name' => 'Kasir Offline',
                    'email' => 'kasir.offline@agnishop.local',
                    'password' => 'password',
                ])->uuid;

            $order = Order::query()->create([
                'user_id' => (string) $userId,
                'status' => 'completed',
                'total' => 0,
                'shipping_address' => json_encode([
                    'type' => 'offline_pos',
                    'customer_name' => $data['customer_name'] ?? 'Pelanggan Offline',
                    'cashier_name' => $data['cashier_name'] ?? 'Kasir',
                ]),
                'payment_method' => $data['payment_method'],
            ]);

            $total = 0;

            foreach ($data['items'] as $item) {
                $stock = DB::table('stock_master')
                    ->where('id', (int) $item['stock_master_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $stock->stock_qty < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => "Stok {$stock->product_name} - {$stock->variant_name} tidak cukup. Sisa stok: {$stock->stock_qty}.",
                    ]);
                }

                $unitPrice = $this->stockMasterPrice($stock);
                if ($unitPrice <= 0 && isset($item['unit_price'])) {
                    $unitPrice = (float) $item['unit_price'];
                }

                $product = $this->stockMasterReceiptProduct($stock, $unitPrice, (int) $stock->stock_qty);
                $total += $unitPrice * $item['quantity'];

                $order->items()->create([
                    'product_id' => $product->uuid,
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                ]);

                if ($deductStock) {
                    DB::table('stock_master')
                        ->where('id', (int) $stock->id)
                        ->update([
                            'stock_qty' => (int) $stock->stock_qty - (int) $item['quantity'],
                            'updated_at' => now(),
                        ]);

                    $product->update([
                        'stock' => (int) $stock->stock_qty - (int) $item['quantity'],
                        'price' => $unitPrice,
                    ]);
                } else {
                    $product->update([
                        'stock' => (int) $stock->stock_qty,
                        'price' => $unitPrice,
                    ]);
                }
            }

            $cashReceived = (float) ($data['cash_received'] ?? 0);
            if ($data['payment_method'] === 'cash' && $cashReceived < $total) {
                throw ValidationException::withMessages([
                    'cash_received' => 'Uang diterima belum cukup untuk pembayaran tunai.',
                ]);
            }

            $order->update(['total' => $total]);

            return $order->load('items.product');
        });

        $cashReceived = (float) ($data['cash_received'] ?? $order->total);

        return response()->json([
            'data' => new OrderResource($order),
            'receipt' => [
                'order_number' => $order->order_number,
                'customer_name' => $data['customer_name'] ?? 'Pelanggan Offline',
                'cashier_name' => $data['cashier_name'] ?? 'Kasir',
                'payment_method' => $data['payment_method'],
                'cash_received' => $cashReceived,
                'change' => max(0, $cashReceived - (float) $order->total),
                'created_at' => $order->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    private function stockMasterReceiptProduct(object $stock, float $unitPrice, int $stockQty): Product
    {
        $sourceSku = $this->firstFilledString([
            $stock->internal_sku ?? null,
            $stock->shopee_seller_sku ?? null,
            $stock->tiktok_seller_sku ?? null,
            'SM-'.$stock->id,
        ]);
        $sku = mb_substr('POS-SM-'.$stock->id, 0, 255);
        $name = trim((string) ($stock->product_name ?? 'Produk'));
        $variant = trim((string) ($stock->variant_name ?? 'Default'));

        $category = Category::query()->firstOrCreate(
            ['name' => 'POS Offline'],
            ['description' => 'Produk bayangan untuk nota POS berbasis stock master.']
        );

        return Product::query()->updateOrCreate(
            ['sku' => $sku],
            [
                'name' => $variant !== '' && strtolower($variant) !== 'default' ? $name.' - '.$variant : $name,
                'description' => trim('Stock Master ID: '.$stock->id.' | Product: '.$name.' | Variant: '.$variant.' | SKU: '.$sourceSku),
                'price' => $unitPrice,
                'stock' => $stockQty,
                'category_id' => $category->uuid,
            ]
        );
    }

    private function stockMasterPrice(object $stock): float
    {
        foreach (['shopee_price', 'tiktok_price', 'price'] as $key) {
            if (isset($stock->{$key}) && (float) $stock->{$key} > 0) {
                return (float) $stock->{$key};
            }
        }

        if (! empty($stock->shopee_sku)) {
            $price = DB::table('shopee_product_model')
                ->where('model_id', (string) $stock->shopee_sku)
                ->value('price');
            if ((float) $price > 0) {
                return (float) $price;
            }
        }

        if (! empty($stock->tiktok_product_id) && ! empty($stock->tiktok_sku)) {
            $price = DB::table('tiktok_products')
                ->where('product_id', (string) $stock->tiktok_product_id)
                ->where('sku_id', (string) $stock->tiktok_sku)
                ->whereRaw('COALESCE(is_active, true) = true')
                ->value('price');
            if ((float) $price > 0) {
                return (float) $price;
            }
        }

        return 0;
    }

    private function firstFilledString(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }
}
