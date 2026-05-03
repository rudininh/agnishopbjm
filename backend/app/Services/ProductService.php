<?php

namespace App\Services;

use App\Models\Product;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ProductService
{
    public function __construct(
        private ProductRepository $products,
        private CategoryRepository $categories,
    ) {
    }

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->products->paginate($perPage);
    }

    public function create(array $data): Product
    {
        $this->categories->find($data['category_id']);

        return $this->products->create($data);
    }

    public function find(string $uuid): Product
    {
        return $this->products->find($uuid);
    }

    public function update(string $uuid, array $data): Product
    {
        $product = $this->products->find($uuid);

        if (isset($data['category_id'])) {
            $this->categories->find($data['category_id']);
        }

        return $this->products->update($product, $data);
    }

    public function delete(string $uuid): void
    {
        $product = $this->products->find($uuid);
        $this->products->delete($product);
    }
}
