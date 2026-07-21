<?php
// database/factories/ProductFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name_hi' => $this->faker->randomElement(['सेव', 'सेंव', 'मिक्स', 'भुजिया']),
            // No unique() — products carry no unique constraint, and faker's
            // unique() pool for word() is small enough to overflow in a full run.
            'name_en' => $this->faker->word(),
            'base_cost_per_kg' => $this->faker->randomFloat(2, 50, 300),
        ];
    }
}
