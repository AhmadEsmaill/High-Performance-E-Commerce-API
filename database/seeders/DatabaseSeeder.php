<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::factory()->create([
            'name'  => 'Test User',
            'email' => 'test@example.com',
        ]);

        $products = [
            ['name' => 'Laptop Pro 16"',      'category' => 'electronics', 'price' => 1299.99, 'stock_quantity' => 50],
            ['name' => 'Wireless Headphones', 'category' => 'electronics', 'price' => 199.99,  'stock_quantity' => 3],
            ['name' => 'Mechanical Keyboard', 'category' => 'electronics', 'price' => 149.99,  'stock_quantity' => 30],
            ['name' => 'USB-C Hub',           'category' => 'electronics', 'price' => 49.99,   'stock_quantity' => 100],
            ['name' => 'Office Chair',        'category' => 'furniture',   'price' => 299.99,  'stock_quantity' => 15],
            ['name' => 'Standing Desk',       'category' => 'furniture',   'price' => 599.99,  'stock_quantity' => 8],
            ['name' => 'Monitor 27" 4K',      'category' => 'electronics', 'price' => 449.99,  'stock_quantity' => 20],
            ['name' => 'Webcam HD',           'category' => 'electronics', 'price' => 89.99,   'stock_quantity' => 2],
        ];

        foreach ($products as $data) {
            Product::create(array_merge($data, [
                'description' => "High-quality {$data['name']} for professionals.",
                'is_active'   => true,
            ]));
        }
    }
}
