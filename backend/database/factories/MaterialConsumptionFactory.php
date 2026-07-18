<?php
// database/factories/MaterialConsumptionFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\MaterialConsumption;
use App\Models\ProductionBatch;
use App\Models\RawMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

class MaterialConsumptionFactory extends Factory
{
    protected $model = MaterialConsumption::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'production_batch_id' => ProductionBatch::factory(),
            'raw_material_id' => RawMaterial::factory(),
            'qty' => '10.000', // positive amount consumed
        ];
    }
}
