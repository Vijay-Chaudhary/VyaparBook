<?php
// database/factories/SaleFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SaleFactory extends Factory
{
    protected $model = Sale::class;

    // Defaults build three unrelated rows (business, customer, user). Tests that
    // need them to agree pass business_id + customer_id explicitly, exactly like
    // ProductPackFactory in the catalog slice.
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'uuid' => $this->faker->uuid(),
            'customer_id' => Customer::factory(),
            'sale_date' => $this->faker->date(),
        ];
    }

    // created_by and total are not fillable, so mass assignment would drop them.
    // Set them here after the model is built but before it saves. A test that
    // needs a specific total sets it on the returned model (or builds real lines).
    public function configure(): static
    {
        return $this->afterMaking(function (Sale $sale) {
            if ($sale->created_by === null) {
                $sale->created_by = User::factory()->create()->id;
            }
            if ($sale->total === null) {
                $sale->total = '0.00';
            }
        });
    }
}
