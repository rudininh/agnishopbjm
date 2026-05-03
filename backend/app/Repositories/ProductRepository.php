<?php

namespace App\Repositories;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProductRepository
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Product::with('category')->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function create(array $data): Product
    {
        return Product::create($data);
    }

    public function find(string $uuid): Product
    {
        return Product::with('category')->where('uuid', $uuid)->firstOrFail();
    }

    public function update(Product $product, array $data): Product
    {
        $product->fill($data);
        $product->save();

        return $product;
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }
}
