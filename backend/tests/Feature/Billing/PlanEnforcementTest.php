<?php
// tests/Feature/Billing/PlanEnforcementTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Str;

/** @return array{0: Business, 1: string} business + owner token, with a forced subscription state */
function enfTenant(string $status, string $plan = 'free'): array
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
        'plan' => $plan,
        'status' => $status,
        'trial_ends_at' => $status === 'trialing' ? now()->addDays(14) : now()->subDay(),
    ]);

    return [$business, (new TokenService())->issue($user, $membership)];
}

function enfSeedCustomers(Business $b, int $n, ?string $uuid = null): void
{
    for ($i = 0; $i < $n; $i++) {
        Customer::create([
            'business_id' => $b->id,
            'uuid' => (string) Str::uuid(),
            'name' => "Seed{$i}",
            'opening_balance' => '0.00',
        ]);
    }
    if ($uuid !== null) {
        Customer::create([
            'business_id' => $b->id, 'uuid' => $uuid, 'name' => 'Replayable', 'opening_balance' => '0.00',
        ]);
    }
}

$count = fn (Business $b) => Customer::withoutGlobalScopes()->where('business_id', $b->id)->count();

it('soft-blocks the 51st customer on a lapsed free plan', function () use ($count) {
    [$business, $token] = enfTenant('past_due');
    enfSeedCustomers($business, 50);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customers', ['name' => 'One Too Many'])
        ->assertStatus(402)
        ->assertJsonPath('code', 'plan_limit')
        ->assertJsonPath('resource', 'customers')
        ->assertJsonPath('upgrade', true);

    expect($count($business))->toBe(50);
});

it('soft-blocks inviting a user beyond the free single seat', function () {
    [$business, $token] = enfTenant('past_due');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/businesses/{$business->id}/invite", [])
        ->assertStatus(402)
        ->assertJsonPath('resource', 'users');
});

it('soft-blocks the stock feature on a lapsed free plan', function () {
    [, $token] = enfTenant('past_due');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/stock')
        ->assertStatus(402)
        ->assertJsonPath('resource', 'stock_production');
});

it('never blocks an idempotent replay even on a maxed plan', function () {
    [$business, $token] = enfTenant('past_due');
    $replayUuid = (string) Str::uuid();
    enfSeedCustomers($business, 50, $replayUuid);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customers', ['uuid' => $replayUuid, 'name' => 'Replayable'])
        ->assertOk();
});

it('allows the 51st customer and stock during the trial', function () use ($count) {
    [$business, $token] = enfTenant('trialing');
    enfSeedCustomers($business, 50);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customers', ['name' => 'Fifty-First'])
        ->assertCreated();

    expect($count($business))->toBe(51);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/stock')
        ->assertOk();
});
