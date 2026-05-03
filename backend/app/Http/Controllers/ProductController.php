<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private ProductService $service)
    {
    }

    public function index(Request $request)
    {
        return ProductResource::collection($this->service->paginate($request->query('per_page', 20)));
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = $this->service->create($request->validated());

        return response()->json(['data' => new ProductResource($product)], 201);
    }

    public function show(string $product)
    {
        return new ProductResource($this->service->find($product));
    }

    public function update(ProductRequest $request, string $product): JsonResponse
    {
        $updated = $this->service->update($product, $request->validated());

        return response()->json(['data' => new ProductResource($updated)]);
    }

    public function destroy(string $product): JsonResponse
    {
        $this->service->delete($product);

        return response()->json([], 204);
    }
}
