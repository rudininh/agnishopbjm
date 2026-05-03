<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray($request): array
    {
        $items = CartItemResource::collection($this->whenLoaded('items'));
        $total = $this->items->sum(fn($item) => $item->unit_price * $item->quantity);

        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'items' => $items,
            'total' => (float) $total,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
