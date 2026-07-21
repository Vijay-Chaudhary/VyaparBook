<?php
// database/factories/CustomerFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'uuid' => $this->faker->uuid(),
            'name' => $this->faker->name(),
            'village' => $this->faker->randomElement(['Rampur', 'Bagru', 'Chomu', 'Sanganer', null]),
            'phone' => $this->faker->numerify('9#########'),
            // A customer starts square by default; tests that care set it explicitly.
            'opening_balance' => '0.00',
        ];
    }
}
