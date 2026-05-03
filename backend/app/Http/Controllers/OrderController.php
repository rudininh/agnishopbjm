<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private OrderService $service)
    {
    }

    public function index(Request $request)
    {
        return OrderResource::collection($this->service->list($request->user(), $request->query('per_page', 20)));
    }

    public function show(string $order)
    {
        return new OrderResource($this->service->find(request()->user(), $order));
    }

    public function checkout(CheckoutRequest $request): JsonResponse
    {
        $order = $this->service->checkout($request->user(), $request->validated());

        return response()->json(['data' => new OrderResource($order)], 201);
    }
}
