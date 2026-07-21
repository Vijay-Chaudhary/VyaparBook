<?php
// tests/Feature/Billing/BillingReadTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Str;

/** @return array{0: Business, 1: string} business + owner bearer token */
function billingTenant(string $status = 'trialing'): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);
    Subscription::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'plan' => 'free',
        'status' => $status,
        'trial_ends_at' => now()->addDays(14),
    ]);

    return [$business, (new TokenService())->issue($user, $membership)];
}

function seedCustomer(Business $b): void
{
    Customer::on('pgsql_migrate')->create([
        'business_id' => $b->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'C' . Str::random(4),
        'opening_balance' => '0.00',
    ]);
}

it('returns the trial billing summary to the owner', function () {
    [$business, $token] = billingTenant();
    seedCustomer($business);
    seedCustomer($business);
    seedCustomer($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/billing')
        ->assertOk()
        ->assertJsonPath('status', 'trialing')
        ->assertJsonPath('plan', 'pro')
        ->assertJsonPath('trial_days_left', 14)
        ->assertJsonPath('limits.max_customers', null)
        ->assertJsonPath('usage.customers', 3)
        ->assertJsonPath('over_limit.customers', false);
});

it('never shows a neighbour\'s payments', function () {
    [$business, $token] = billingTenant();

    $neighbour = Business::factory()->create();
    SubscriptionPayment::on('pgsql_migrate')->create([
        'business_id' => $neighbour->id,
        'uuid' => (string) Str::uuid(),
        'plan' => 'pro', 'amount' => '499.00', 'gst_amount' => '89.82',
        'mode' => 'upi', 'period_months' => 1, 'status' => 'pending',
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/billing')
        ->assertOk()
        ->assertJsonPath('payments', []);
});
