<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => 'nullable|string|max:120',
            'cashier_name' => 'nullable|string|max:120',
            'payment_method' => 'required|string|in:cash,qris,debit,transfer',
            'cash_received' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|string|exists:products,uuid',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $order = DB::transaction(function () use ($data) {
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
                $product = Product::query()
                    ->where('uuid', $item['product_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($product->stock < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => "Stok {$product->name} tidak cukup. Sisa stok: {$product->stock}.",
                    ]);
                }

                $unitPrice = (float) $product->price;
                $total += $unitPrice * $item['quantity'];

                $order->items()->create([
                    'product_id' => $product->uuid,
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                ]);

                $product->decrement('stock', $item['quantity']);
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
}
