<?php
// database/factories/RawMaterialFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\RawMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

class RawMaterialFactory extends Factory
{
    protected $model = RawMaterial::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'uuid' => $this->faker->uuid(),
            'name' => $this->faker->randomElement(['Besan', 'Oil', 'Salt', 'Chilli', 'Turmeric']),
            'unit' => 'kg',
            'reorder_level' => '10.000',
        ];
    }
}
