<?php
// tests/Feature/Admin/VerifyPaymentTest.php

use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Str;

// pendingPayment() and platformAdmin() are shared helpers defined in tests/Pest.php.

it('verifies a pending payment: activates the plan and stamps the admin', function () {
    [$business] = seedTenantWithOwner('trialing', 'free');
    $payment = pendingPayment($business->id);
    [$admin, $token] = platformAdmin();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/payments/{$payment->id}/verify")
        ->assertOk()
        ->assertJsonPath('subscription.status', 'active')
        ->assertJsonPath('subscription.plan', 'pro')
        ->assertJsonPath('payment.status', 'verified')
        ->assertJsonPath('payment.verified_by', $admin->id);

    // Persisted state (read past RLS on the bypass connection).
    $stored = SubscriptionPayment::on('mysql_platform')->find($payment->id);
    expect($stored->status)->toBe('verified')
        ->and($stored->verified_by)->toBe($admin->id);

    $sub = Subscription::on('mysql_platform')->where('business_id', $business->id)->first();
    expect($sub->status)->toBe('active')->and($sub->plan)->toBe('pro');
});

it('writes a single audit-trail entry naming the admin, action and target', function () {
    [$business] = seedTenantWithOwner('trialing', 'free');
    $payment = pendingPayment($business->id);
    [$admin, $token] = platformAdmin();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/payments/{$payment->id}/verify")
        ->assertOk();

    $logs = PlatformAuditLog::where('action', 'verify_payment')
        ->where('target_business_id', $business->id)
        ->get();

    expect($logs)->toHaveCount(1);
    expect($logs[0]->admin_user_id)->toBe($admin->id)
        ->and($logs[0]->metadata['payment_id'])->toBe($payment->id)
        ->and($logs[0]->metadata['plan'])->toBe('pro');
});

it('is idempotent: a second verify is a no-op with no second audit entry', function () {
    [$business] = seedTenantWithOwner('trialing', 'free');
    $payment = pendingPayment($business->id);
    [, $token] = platformAdmin();

    $first = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/payments/{$payment->id}/verify")
        ->assertOk()
        ->json('subscription.current_period_end');

    $second = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/payments/{$payment->id}/verify")
        ->assertOk()
        ->json('subscription.current_period_end');

    // Period did not stack a second month, and only one trail entry exists.
    expect($second)->toBe($first);
    expect(PlatformAuditLog::where('action', 'verify_payment')->count())->toBe(1);
});

it('refuses to verify a rejected payment (422)', function () {
    [$business] = seedTenantWithOwner('trialing', 'free');
    $payment = pendingPayment($business->id);
    $payment->forceFill(['status' => 'rejected'])->save();
    [, $token] = platformAdmin();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/payments/{$payment->id}/verify")
        ->assertStatus(422);

    expect(PlatformAuditLog::count())->toBe(0);
});

it('404s for an unknown payment id', function () {
    [$business] = seedTenantWithOwner('trialing', 'free');
    [, $token] = platformAdmin();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/payments/".Str::uuid()."/verify")
        ->assertStatus(404);
});

it('cannot verify one tenant\'s payment under another tenant\'s id (RLS-confined)', function () {
    [$tenantA] = seedTenantWithOwner('trialing', 'free');
    [$tenantB] = seedTenantWithOwner('trialing', 'free');
    $paymentB = pendingPayment($tenantB->id);
    [, $token] = platformAdmin();

    // Address B's payment under A's tenant id: RLS pins to A, so it is invisible.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$tenantA->id}/payments/{$paymentB->id}/verify")
        ->assertStatus(404);

    // B's payment and subscription are untouched.
    expect(SubscriptionPayment::on('mysql_platform')->find($paymentB->id)->status)->toBe('pending');
    expect(Subscription::on('mysql_platform')->where('business_id', $tenantB->id)->first()->status)->toBe('trialing');
});

it('is gated by the platform guard', function () {
    [$business, $ownerToken] = seedTenantWithOwner('trialing', 'free');
    $payment = pendingPayment($business->id);

    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/payments/{$payment->id}/verify")
        ->assertStatus(403);
});
