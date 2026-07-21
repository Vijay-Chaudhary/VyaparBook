<?php
// database/factories/SaleLineFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\ProductPack;
use App\Models\Sale;
use App\Models\SaleLine;
use Illuminate\Database\Eloquent\Factories\Factory;

class SaleLineFactory extends Factory
{
    protected $model = SaleLine::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'sale_id' => Sale::factory(),
            'product_pack_id' => ProductPack::factory(),
            'qty' => $this->faker->numberBetween(1, 20),
            'rate' => $this->faker->randomFloat(2, 10, 500),
        ];
    }

    // line_total is not fillable — it is the frozen rate * qty. Compute it from
    // the (fillable) rate and qty already set on the model.
    public function configure(): static
    {
        return $this->afterMaking(function (SaleLine $line) {
            if ($line->line_total === null) {
                $line->line_total = bcmul((string) $line->rate, (string) $line->qty, 2);
            }
        });
    }
}
