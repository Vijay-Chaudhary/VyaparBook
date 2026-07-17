<?php
// database/factories/PaymentFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    // Defaults build unrelated rows; tests that need agreement pass business_id
    // and customer_id explicitly (same convention as SaleFactory).
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'uuid' => $this->faker->uuid(),
            'customer_id' => Customer::factory(),
            'payment_date' => $this->faker->date(),
            'amount' => $this->faker->randomFloat(2, 50, 5000),
            'mode' => 'cash',
        ];
    }

    // created_by is not fillable, so mass assignment would drop it — set it here.
    public function configure(): static
    {
        return $this->afterMaking(function (Payment $payment) {
            if ($payment->created_by === null) {
                $payment->created_by = User::factory()->create()->id;
            }
        });
    }
}
