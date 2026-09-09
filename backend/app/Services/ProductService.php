<?php

namespace App\Services;

use App\Models\Product;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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

        $variants = $data['variants'] ?? [];
        unset($data['variants']);

        return DB::transaction(function () use ($data, $variants): Product {
            $product = $this->products->create($data);
            $this->storeVariants($product, $variants);

            return $product->load(['category', 'variants']);
        });
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

        $variants = $data['variants'] ?? null;
        unset($data['variants']);

        return DB::transaction(function () use ($product, $data, $variants): Product {
            $updated = $this->products->update($product, $data);

            if ($variants !== null) {
                $updated->variants()->delete();
                $this->storeVariants($updated, $variants);
            }

            return $updated->load(['category', 'variants']);
        });
    }

    public function delete(string $uuid): void
    {
        $product = $this->products->find($uuid);
        $this->products->delete($product);
    }

    private function storeVariants(Product $product, array $variants): void
    {
        foreach (array_values($variants) as $position => $variant) {
            $product->variants()->create([
                'variant_name' => $variant['variant_name'],
                'sku' => $variant['sku'],
                'price' => $variant['price'],
                'stock' => $variant['stock'],
                'image_url' => $variant['image_url'] ?? null,
                'position' => $position,
            ]);
        }
    }
}
