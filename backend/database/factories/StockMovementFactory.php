<?php
// database/factories/StockMovementFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    // Defaults build unrelated rows; tests that need agreement pass business_id
    // and raw_material_id explicitly (same convention as PaymentFactory).
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'uuid' => $this->faker->uuid(),
            'raw_material_id' => RawMaterial::factory(),
            'movement_date' => $this->faker->date(),
            'kind' => 'in',
            'qty' => '100.000', // signed effect: an `in` raises stock
            'note' => null,
        ];
    }

    // created_by is not fillable, so mass assignment would drop it — set it here.
    public function configure(): static
    {
        return $this->afterMaking(function (StockMovement $movement) {
            if ($movement->created_by === null) {
                $movement->created_by = User::factory()->create()->id;
            }
        });
    }
}
