<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'total' => (float) $this->total,
            'items' => $this->items->map(fn($item) => [
                'uuid' => $item->uuid,
                'product' => new ProductResource($item->product),
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => (float) ($item->quantity * $item->unit_price),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
