<?php
// tests/Feature/Billing/ProvisioningTest.php

use App\Models\Subscription;
use App\Models\User;
use App\Services\TokenService;

it('provisions exactly one 14-day trial subscription on business creation', function () {
    $user = User::factory()->create();
    $token = (new TokenService())->issue($user);

    $businessId = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/businesses', ['name' => 'Trial Shop', 'city' => 'Jaipur'])
        ->assertCreated()
        ->json('business.id');

    $subs = Subscription::on('pgsql_migrate')->where('business_id', $businessId)->get();

    expect($subs)->toHaveCount(1);
    expect($subs->first()->status)->toBe('trialing');
    expect($subs->first()->plan)->toBe('free');
    expect($subs->first()->trial_ends_at->timestamp)
        ->toEqualWithDelta(now()->addDays(14)->timestamp, 60);
});
