<?php
// database/factories/SubscriptionFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'plan' => 'free',
            'status' => 'trialing',
            'trial_ends_at' => now()->addDays(14),
            'current_period_end' => null,
        ];
    }
}
