<?php

namespace App\Http\Controllers;

use App\Http\Requests\CartItemRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;

class CartController extends Controller
{
    public function __construct(private CartService $service)
    {
    }

    public function show(): CartResource
    {
        return new CartResource($this->service->getCurrentCart());
    }

    public function addItem(CartItemRequest $request): JsonResponse
    {
        $cart = $this->service->addItem($request->validated());

        return response()->json(['data' => new CartResource($cart)], 201);
    }

    public function updateItem(string $item, UpdateCartItemRequest $request): JsonResponse
    {
        $cart = $this->service->updateItem($item, $request->validated());

        return response()->json(['data' => new CartResource($cart)]);
    }

    public function removeItem(string $item): JsonResponse
    {
        $cart = $this->service->removeItem($item);

        return response()->json(['data' => new CartResource($cart)]);
    }
}
