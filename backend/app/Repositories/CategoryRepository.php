<?php

namespace App\Repositories;

use App\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CategoryRepository
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Category::orderBy('name')->paginate($perPage);
    }

    public function create(array $data): Category
    {
        return Category::create($data);
    }

    public function find(string $uuid): Category
    {
        return Category::where('uuid', $uuid)->firstOrFail();
    }

    public function update(Category $category, array $data): Category
    {
        $category->fill($data);
        $category->save();

        return $category;
    }

    public function delete(Category $category): void
    {
        $category->delete();
    }
}
