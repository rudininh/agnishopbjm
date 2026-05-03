<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(private CategoryService $service)
    {
    }

    public function index(Request $request)
    {
        return CategoryResource::collection($this->service->paginate($request->query('per_page', 20)));
    }

    public function show(string $category)
    {
        return new CategoryResource($this->service->find($category));
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = $this->service->create($request->validated());

        return response()->json(['data' => new CategoryResource($category)], 201);
    }

    public function update(CategoryRequest $request, string $category): JsonResponse
    {
        $categoryModel = $this->service->update($category, $request->validated());

        return response()->json(['data' => new CategoryResource($categoryModel)]);
    }

    public function destroy(string $category): JsonResponse
    {
        $this->service->delete($category);

        return response()->json([], 204);
    }
}
