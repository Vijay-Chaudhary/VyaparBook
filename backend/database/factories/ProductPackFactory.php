<?php
// database/factories/ProductPackFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductPackFactory extends Factory
{
    protected $model = ProductPack::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'product_id' => Product::factory(),
            'pack_size_id' => PackSize::factory(),
            'default_sell_price' => $this->faker->randomFloat(2, 10, 500),
            'default_cost_price' => $this->faker->randomFloat(2, 5, 400),
        ];
    }
}
