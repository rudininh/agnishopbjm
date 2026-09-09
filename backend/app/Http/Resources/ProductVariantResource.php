<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'variant_name' => $this->variant_name,
            'sku' => $this->sku,
            'price' => (float) $this->price,
            'stock' => $this->stock,
            'image_url' => $this->image_url,
            'position' => $this->position,
        ];
    }
}
