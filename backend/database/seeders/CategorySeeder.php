<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['name' => 'Fashion', 'description' => 'Pakaian, aksesoris, dan produk fashion.'],
            ['name' => 'Elektronik', 'description' => 'Gadget, aksesori, dan perangkat elektronik.'],
            ['name' => 'Kecantikan', 'description' => 'Produk kecantikan dan perawatan diri.'],
            ['name' => 'Rumah & Dapur', 'description' => 'Peralatan rumah tangga dan kebutuhan dapur.'],
        ])->each(fn($data) => Category::create($data));
    }
}
