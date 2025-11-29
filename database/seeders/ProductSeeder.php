<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Product;

class ProductSeeder extends Seeder
{
    public function run()
    {
        Product::create([
            'name' => 'Flash Sale Item',
            'total_stock' => 100,
            'available_stock' => 100,
            'price_cents' => 1000,
        ]);
    }
}
