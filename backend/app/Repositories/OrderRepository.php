<?php

namespace App\Repositories;

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrderRepository
{
    public function listByUser(string $userId, int $perPage = 20): LengthAwarePaginator
    {
        return Order::with('items.product')->where('user_id', $userId)->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function findForUser(string $userId, string $uuid): Order
    {
        return Order::with('items.product.category')
            ->where('user_id', $userId)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    public function create(array $data): Order
    {
        return Order::create($data);
    }

    public function createItem(array $data)
    {
        return \App\Models\OrderItem::create($data);
    }
}
