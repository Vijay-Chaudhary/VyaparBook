<?php
// database/factories/SubscriptionPaymentFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionPaymentFactory extends Factory
{
    protected $model = SubscriptionPayment::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'uuid' => $this->faker->uuid(),
            'plan' => 'pro',
            'amount' => '499.00',
            'gst_amount' => '89.82',
            'mode' => 'upi',
            'reference' => null,
            'period_months' => 1,
            'status' => 'pending',
        ];
    }
}
