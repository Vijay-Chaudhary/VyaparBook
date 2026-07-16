<?php
// database/factories/PackSizeFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\PackSize;
use Illuminate\Database\Eloquent\Factories\Factory;

class PackSizeFactory extends Factory
{
    protected $model = PackSize::class;

    public function definition(): array
    {
        // Drawn from a wide range rather than a handful of realistic sizes:
        // pack_sizes has a unique (business_id, label) index, and faker's
        // unique() over a 5-element list throws OverflowException as soon as a
        // run needs a sixth. Realism is not what a factory owes here — a label
        // that never collides is.
        $grams = $this->faker->unique()->numberBetween(50, 9999);

        return [
            'business_id' => Business::factory(),
            'label' => $grams . 'g',
            'weight_kg' => number_format($grams / 1000, 3, '.', ''),
            'in_dropdown' => true,
        ];
    }
}
