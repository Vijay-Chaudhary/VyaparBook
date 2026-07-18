<?php
// tests/Feature/Billing/BillingPaymentTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Str;

/** @return array{0: Business, 1: string} business + bearer token for the given role */
function payTenant(string $role = 'owner'): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => $role,
    ]);
    Subscription::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'plan' => 'free',
        'status' => 'trialing',
        'trial_ends_at' => now()->addDays(14),
    ]);

    return [$business, (new TokenService())->issue($user, $membership)];
}

it('records a pending payment with GST and leaves the subscription untouched', function () {
    [$business, $token] = payTenant('owner');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/payments', [
            'plan' => 'pro', 'amount' => '499.00', 'mode' => 'upi',
            'reference' => 'UPI123', 'period_months' => 1,
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('gst_amount', '89.82');

    expect(Subscription::on('pgsql_migrate')->where('business_id', $business->id)->first()->status)
        ->toBe('trialing');
});

it('is idempotent by uuid', function () {
    [$business, $token] = payTenant('owner');
    $body = ['uuid' => (string) Str::uuid(), 'plan' => 'pro', 'amount' => '499.00', 'mode' => 'upi', 'period_months' => 1];

    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/billing/payments', $body)->assertCreated();
    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/billing/payments', $body)->assertOk();

    expect(SubscriptionPayment::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(1);
});

it('forbids non-owners from recording a payment', function () {
    foreach (['admin', 'salesman', 'accountant'] as $role) {
        [, $token] = payTenant($role);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/billing/payments', [
                'plan' => 'pro', 'amount' => '499.00', 'mode' => 'upi', 'period_months' => 1,
            ])
            ->assertStatus(403);
    }
});
