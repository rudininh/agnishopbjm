<?php

namespace App\Repositories;

use App\Models\Cart;
use App\Models\Product;

class CartRepository
{
    public function currentForUser(string $userId): Cart
    {
        return Cart::with(['items.product'])
            ->firstOrCreate(
                ['user_id' => $userId, 'status' => 'active'],
                ['user_id' => $userId, 'status' => 'active']
            );
    }

    public function addItem(string $userId, Product $product, int $quantity): Cart
    {
        $cart = $this->currentForUser($userId);

        $item = $cart->items()->firstOrNew(['product_id' => $product->uuid]);
        $item->quantity = $item->quantity ? $item->quantity + $quantity : $quantity;
        $item->unit_price = $product->price;
        $item->save();

        return $cart->refresh()->load('items.product.category');
    }

    public function updateItem(string $userId, string $itemUuid, int $quantity): Cart
    {
        $cart = $this->currentForUser($userId);
        $item = $cart->items()->where('uuid', $itemUuid)->firstOrFail();

        $item->update(['quantity' => $quantity]);

        return $cart->refresh()->load('items.product.category');
    }

    public function removeItem(string $userId, string $itemUuid): Cart
    {
        $cart = $this->currentForUser($userId);
        $item = $cart->items()->where('uuid', $itemUuid)->firstOrFail();
        $item->delete();

        return $cart->refresh()->load('items.product.category');
    }

    public function clearCart(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->update(['status' => 'completed']);
    }
}
