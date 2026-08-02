<?php
// tests/Feature/Billing/BillingRbacTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TokenService;

/** Billing is owner-only (PRD §7) — stricter than stock/production; admin is excluded too. */
function rbacBillingToken(string $role): string
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => $role,
    ]);
    Subscription::create([
        'business_id' => $business->id,
        'plan' => 'free',
        'status' => 'trialing',
        'trial_ends_at' => now()->addDays(14),
    ]);

    return (new TokenService())->issue($user, $membership);
}

it('lets only the owner reach the billing endpoints', function () {
    $token = rbacBillingToken('owner');
    $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

    $auth()->getJson('/api/v1/billing')->assertOk();
    $auth()->postJson('/api/v1/billing/payments', [
        'plan' => 'pro', 'amount' => '499.00', 'mode' => 'upi', 'period_months' => 1,
    ])->assertCreated();
});

it('forbids admin, salesman and accountant from every billing endpoint', function () {
    foreach (['admin', 'salesman', 'accountant'] as $role) {
        $token = rbacBillingToken($role);
        $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

        $auth()->getJson('/api/v1/billing')->assertStatus(403);
        $auth()->postJson('/api/v1/billing/payments', [
            'plan' => 'pro', 'amount' => '499.00', 'mode' => 'upi', 'period_months' => 1,
        ])->assertStatus(403);
    }
});
