<?php
// database/factories/ProductionBatchFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionBatchFactory extends Factory
{
    protected $model = ProductionBatch::class;

    // Defaults build unrelated rows; tests that need agreement pass business_id
    // and product_id explicitly (same convention as SaleFactory).
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'uuid' => $this->faker->uuid(),
            'product_id' => Product::factory(),
            'batch_date' => $this->faker->date(),
            'output_kg' => '50.000',
        ];
    }

    // created_by is not fillable, so mass assignment would drop it — set it here.
    public function configure(): static
    {
        return $this->afterMaking(function (ProductionBatch $batch) {
            if ($batch->created_by === null) {
                $batch->created_by = User::factory()->create()->id;
            }
        });
    }
}
