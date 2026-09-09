<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:50|unique:products,sku,' . $this->route('product') . ',uuid',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'category_id' => 'required|uuid|exists:categories,uuid',
            'variants' => 'sometimes|array|min:1',
            'variants.*.variant_name' => 'required_with:variants|string|max:255',
            'variants.*.sku' => 'required_with:variants|string|max:100|distinct|unique:product_variants,sku',
            'variants.*.price' => 'required_with:variants|numeric|min:0',
            'variants.*.stock' => 'required_with:variants|integer|min:0',
            'variants.*.image_url' => 'nullable|url|max:2048',
        ];
    }
}
