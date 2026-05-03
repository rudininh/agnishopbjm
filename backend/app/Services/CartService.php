<?php

namespace App\Services;

use App\Models\Cart;
use App\Repositories\CartRepository;
use App\Repositories\ProductRepository;

class CartService
{
    public function __construct(
        private CartRepository $carts,
        private ProductRepository $products,
    ) {
    }

    public function getCurrentCart(): Cart
    {
        return $this->carts->currentForUser((string) auth()->id())->load('items.product.category');
    }

    public function addItem(array $data): Cart
    {
        $product = $this->products->find($data['product_id']);
        $quantity = max(1, $data['quantity']);

        return $this->carts->addItem((string) auth()->id(), $product, $quantity);
    }

    public function updateItem(string $itemUuid, array $data): Cart
    {
        $quantity = max(1, $data['quantity']);

        return $this->carts->updateItem((string) auth()->id(), $itemUuid, $quantity);
    }

    public function removeItem(string $itemUuid): Cart
    {
        return $this->carts->removeItem((string) auth()->id(), $itemUuid);
    }
}
