<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'name' => 'Kaos Pria Polos',
                'sku' => 'FASH-001',
                'description' => 'Kaos katun warna netral, nyaman untuk aktivitas sehari-hari.',
                'price' => 85000,
                'stock' => 120,
                'category' => 'Fashion',
            ],
            [
                'name' => 'Wireless Earbuds',
                'sku' => 'EL-002',
                'description' => 'Earbuds nirkabel dengan kualitas suara jernih dan batre tahan lama.',
                'price' => 299000,
                'stock' => 50,
                'category' => 'Elektronik',
            ],
            [
                'name' => 'Lipstik Matte',
                'sku' => 'BE-003',
                'description' => 'Lipstik matte tahan lama dengan hasil akhir lembut.',
                'price' => 65000,
                'stock' => 80,
                'category' => 'Kecantikan',
            ],
            [
                'name' => 'Set Panci Stainless Steel',
                'sku' => 'HD-004',
                'description' => 'Set panci lengkap untuk dapur modern.',
                'price' => 450000,
                'stock' => 30,
                'category' => 'Rumah & Dapur',
            ],
        ];

        foreach ($products as $product) {
            $category = Category::where('name', $product['category'])->first();

            Product::create([
                'name' => $product['name'],
                'sku' => $product['sku'],
                'description' => $product['description'],
                'price' => $product['price'],
                'stock' => $product['stock'],
                'category_id' => $category->uuid,
            ]);
        }
    }
}
