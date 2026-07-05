<?php
// database/factories/MembershipFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'business_id' => Business::factory(),
            'role' => 'owner',
        ];
    }
}
