<?php
// tests/Feature/Admin/TenantDetailTest.php

use App\Models\SubscriptionPayment;
use Illuminate\Support\Str;

it('returns a single tenant with billing, members and payments', function () {
    [$business] = seedTenantWithOwner('active', 'pro');
    SubscriptionPayment::create([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'plan' => 'pro',
        'amount' => '999.00',
        'gst_amount' => '179.82',
        'mode' => 'upi',
        'period_months' => 1,
        'status' => 'verified',
    ]);

    $token = platformAdminToken();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/admin/tenants/{$business->id}")
        ->assertOk()
        ->assertJsonPath('id', $business->id)
        ->assertJsonPath('subscription.status', 'active')
        ->assertJsonPath('subscription.plan', 'pro')
        ->assertJsonCount(1, 'members')
        ->assertJsonPath('members.0.role', 'owner')
        ->assertJsonCount(1, 'payments')
        ->assertJsonPath('payments.0.status', 'verified');
});

it('reports a null subscription for a tenant that has none', function () {
    $business = \App\Models\Business::factory()->create();

    $token = platformAdminToken();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/admin/tenants/{$business->id}")
        ->assertOk()
        ->assertJsonPath('subscription', null)
        ->assertJsonCount(0, 'members');
});

it('404s for an unknown tenant id', function () {
    $token = platformAdminToken();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/tenants/'.Str::uuid())
        ->assertStatus(404);
});

it('is gated by the platform guard like the rest of the console', function () {
    [$business, $ownerToken] = seedTenantWithOwner();

    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->getJson("/api/v1/admin/tenants/{$business->id}")
        ->assertStatus(403);
});
