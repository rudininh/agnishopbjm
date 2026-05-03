<?php

namespace App\Services;

use App\Models\Category;
use App\Repositories\CategoryRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CategoryService
{
    public function __construct(private CategoryRepository $categories)
    {
    }

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->categories->paginate($perPage);
    }

    public function create(array $data): Category
    {
        return $this->categories->create($data);
    }

    public function find(string $uuid): Category
    {
        return $this->categories->find($uuid);
    }

    public function update(string $uuid, array $data): Category
    {
        $category = $this->categories->find($uuid);

        return $this->categories->update($category, $data);
    }

    public function delete(string $uuid): void
    {
        $category = $this->categories->find($uuid);

        $this->categories->delete($category);
    }
}
