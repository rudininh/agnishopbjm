<?php

namespace App\Services;

use App\Models\Product;
use InvalidArgumentException;

class MarketplaceProductPayloadBuilder
{
    public function forShopee(Product $product, array $context): array
    {
        $categoryId = $context['category_id'] ?? null;
        $weight = $context['weight'] ?? null;

        if ($categoryId === null || $weight === null) {
            throw new InvalidArgumentException('Kategori dan berat Shopee wajib diisi.');
        }

        $product->loadMissing('variants');

        return [
            'item_name' => (string) $product->name,
            'description' => (string) ($product->description ?? ''),
            'category_id' => is_numeric($categoryId) ? (int) $categoryId : $categoryId,
            'weight' => (float) $weight,
            'images' => $product->variants
                ->pluck('image_url')
                ->filter(fn ($image): bool => trim((string) $image) !== '')
                ->values()
                ->all(),
            'models' => $product->variants->map(fn ($variant): array => [
                'model_name' => (string) $variant->variant_name,
                'model_sku' => (string) $variant->sku,
                'price' => (float) $variant->price,
                'stock' => (int) $variant->stock,
                'image_url' => (string) $variant->image_url,
            ])->values()->all(),
        ];
    }

    public function forTiktok(Product $product, array $context): array
    {
        $categoryId = $context['category_id'] ?? null;
        $warehouseId = trim((string) ($context['warehouse_id'] ?? ''));

        if ($categoryId === null || $warehouseId === '') {
            throw new InvalidArgumentException('Kategori dan warehouse TikTok wajib diisi.');
        }

        $product->loadMissing('variants');

        return [
            'product_name' => (string) $product->name,
            'description' => (string) ($product->description ?? ''),
            'category_id' => (string) $categoryId,
            'warehouse_id' => $warehouseId,
            'main_images' => $product->variants->map(fn ($variant): array => [
                'url' => (string) $variant->image_url,
            ])->values()->all(),
            'skus' => $product->variants->map(fn ($variant): array => [
                'seller_sku' => (string) $variant->sku,
                'variant_name' => (string) $variant->variant_name,
                'price' => (float) $variant->price,
                'stock' => (int) $variant->stock,
                'image_url' => (string) $variant->image_url,
                'warehouse_id' => $warehouseId,
            ])->values()->all(),
        ];
    }
}
