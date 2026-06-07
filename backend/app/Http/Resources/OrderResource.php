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
            'items' => $this->items->map(function ($item) {
                $receiptMeta = $this->receiptProductMeta((string) ($item->product?->description ?? ''));

                return [
                    'uuid' => $item->uuid,
                    'product' => new ProductResource($item->product),
                    'receipt_product_name' => $receiptMeta['product_name'] ?? null,
                    'receipt_variant_name' => $receiptMeta['variant_name'] ?? null,
                    'receipt_sku' => $receiptMeta['sku'] ?? null,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) ($item->quantity * $item->unit_price),
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function receiptProductMeta(string $description): array
    {
        $parts = array_map('trim', explode('|', $description));
        $meta = [];

        foreach ($parts as $part) {
            if (str_contains($part, ':') === false) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(':', $part, 2));
            $meta[strtolower($key)] = $value;
        }

        return [
            'product_name' => $meta['product'] ?? null,
            'variant_name' => $meta['variant'] ?? null,
            'sku' => $meta['sku'] ?? null,
        ];
    }
}
