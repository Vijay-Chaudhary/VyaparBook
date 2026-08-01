<?php
// tests/Feature/Billing/ReadOnlyGateTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TokenService;

/** @return array{0: Business, 1: string} business + owner token in the given subscription status */
function gateTenant(string $status): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);
    Subscription::create([
        'business_id' => $business->id,
        'plan' => 'pro',
        'status' => $status,
        'trial_ends_at' => now()->subDays(30),
        'current_period_end' => now()->addMonth(),
    ]);

    return [$business, (new TokenService())->issue($user, $membership)];
}

it('pauses domain writes but allows reads for a read_only tenant', function () {
    [, $token] = gateTenant('read_only');
    $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

    $auth()->getJson('/api/v1/khata')->assertOk();

    $auth()->postJson('/api/v1/customers', ['name' => 'Blocked'])
        ->assertStatus(402)
        ->assertJsonPath('code', 'read_only');
});

it('exempts billing view and payment from the read_only gate', function () {
    [, $token] = gateTenant('read_only');
    $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

    $auth()->getJson('/api/v1/billing')->assertOk();

    $auth()->postJson('/api/v1/billing/payments', [
        'plan' => 'pro', 'amount' => '499.00', 'mode' => 'upi', 'period_months' => 1,
    ])->assertCreated();
});

it('leaves a normal tenant\'s writes untouched', function () {
    [, $token] = gateTenant('active');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customers', ['name' => 'Allowed'])
        ->assertCreated();
});
