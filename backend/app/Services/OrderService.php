<?php

namespace App\Services;

use App\DTOs\CheckoutData;
use App\Exceptions\DomainException;
use App\Models\Order;
use App\Repositories\CartRepository;
use App\Repositories\OrderRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private CartRepository $carts,
        private OrderRepository $orders,
    ) {
    }

    public function list(Authenticatable $user, int $perPage)
    {
        return $this->orders->listByUser((string) $user->getAuthIdentifier(), $perPage);
    }

    public function find(Authenticatable $user, string $uuid): Order
    {
        return $this->orders->findForUser((string) $user->getAuthIdentifier(), $uuid);
    }

    public function checkout(Authenticatable $user, array $data): Order
    {
        $checkout = CheckoutData::fromArray($data);

        return DB::transaction(function () use ($user, $checkout) {
            $cart = $this->carts->currentForUser((string) $user->getAuthIdentifier());
            $items = $cart->items()->with('product')->get();

            if ($items->isEmpty()) {
                throw DomainException::emptyCart();
            }

            $order = $this->orders->create([
                'user_id' => (string) $user->getAuthIdentifier(),
                'status' => 'pending',
                'total' => 0,
                'shipping_address' => $checkout->shippingAddress,
                'payment_method' => $checkout->paymentMethod,
            ]);

            $total = 0;

            foreach ($items as $item) {
                $product = $item->product;
                if ($product->stock < $item->quantity) {
                    throw DomainException::insufficientStock($product->name);
                }

                $unitPrice = $product->price;
                $total += $unitPrice * $item->quantity;

                $this->orders->createItem([ 
                    'order_id' => $order->uuid,
                    'product_id' => $product->uuid,
                    'quantity' => $item->quantity,
                    'unit_price' => $unitPrice,
                ]);

                $product->decrement('stock', $item->quantity);
            }

            $order->update(['total' => $total, 'status' => 'completed']);
            $this->carts->clearCart($cart);

            return $order->load('items.product');
        });
    }
}
