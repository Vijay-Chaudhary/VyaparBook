<?php
// tests/Feature/Admin/RejectPaymentTest.php

use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Str;

// pendingPayment() and platformAdmin() are defined in VerifyPaymentTest.php,
// which Pest loads into the same scope.

it('rejects a pending payment and leaves the subscription untouched', function () {
    [$business] = seedTenantWithOwner('trialing', 'free');
    $payment = pendingPayment($business->id);
    [, $token] = platformAdmin();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/payments/{$payment->id}/reject", ['reason' => 'amount mismatch'])
        ->assertOk()
        ->assertJsonPath('payment.status', 'rejected');

    $stored = SubscriptionPayment::on('pgsql_platform')->find($payment->id);
    expect($stored->status)->toBe('rejected')
        ->and($stored->verified_at)->toBeNull()
        ->and($stored->verified_by)->toBeNull();

    // No activation happened: the tenant is still trialing.
    $sub = Subscription::on('pgsql_platform')->where('business_id', $business->id)->first();
    expect($sub->status)->toBe('trialing');
});

it('records an audit entry carrying the rejection reason', function () {
    [$business] = seedTenantWithOwner('trialing', 'free');
    $payment = pendingPayment($business->id);
    [$admin, $token] = platformAdmin();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/payments/{$payment->id}/reject", ['reason' => 'duplicate'])
        ->assertOk();

    $logs = PlatformAuditLog::on('pgsql_migrate')
        ->where('action', 'reject_payment')
        ->where('target_business_id', $business->id)
        ->get();

    expect($logs)->toHaveCount(1);
    expect($logs[0]->admin_user_id)->toBe($admin->id)
        ->and($logs[0]->metadata['payment_id'])->toBe($payment->id)
        ->and($logs[0]->metadata['reason'])->toBe('duplicate');
});

it('is idempotent: a second reject is a no-op with no second audit entry', function () {
    [$business] = seedTenantWithOwner('trialing', 'free');
    $payment = pendingPayment($business->id);
    [, $token] = platformAdmin();

    foreach (range(1, 2) as $_) {
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/admin/tenants/{$business->id}/payments/{$payment->id}/reject")
            ->assertOk()
            ->assertJsonPath('payment.status', 'rejected');
    }

    expect(PlatformAuditLog::on('pgsql_migrate')->where('action', 'reject_payment')->count())->toBe(1);
});

it('refuses to reject an already-verified payment (422)', function () {
    [$business] = seedTenantWithOwner('trialing', 'free');
    $payment = pendingPayment($business->id);
    [, $token] = platformAdmin();

    // Verify first, then attempt to reject: the activated plan must not be undone.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/payments/{$payment->id}/verify")
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/payments/{$payment->id}/reject")
        ->assertStatus(422);

    // Still verified, subscription still active.
    expect(SubscriptionPayment::on('pgsql_platform')->find($payment->id)->status)->toBe('verified');
    expect(Subscription::on('pgsql_platform')->where('business_id', $business->id)->first()->status)->toBe('active');
    expect(PlatformAuditLog::on('pgsql_migrate')->where('action', 'reject_payment')->count())->toBe(0);
});

it('404s for an unknown payment id', function () {
    [$business] = seedTenantWithOwner('trialing', 'free');
    [, $token] = platformAdmin();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/payments/".Str::uuid()."/reject")
        ->assertStatus(404);
});

it('cannot reject one tenant\'s payment under another tenant\'s id (RLS-confined)', function () {
    [$tenantA] = seedTenantWithOwner('trialing', 'free');
    [$tenantB] = seedTenantWithOwner('trialing', 'free');
    $paymentB = pendingPayment($tenantB->id);
    [, $token] = platformAdmin();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$tenantA->id}/payments/{$paymentB->id}/reject")
        ->assertStatus(404);

    expect(SubscriptionPayment::on('pgsql_platform')->find($paymentB->id)->status)->toBe('pending');
});

it('is gated by the platform guard', function () {
    [$business, $ownerToken] = seedTenantWithOwner('trialing', 'free');
    $payment = pendingPayment($business->id);

    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/payments/{$payment->id}/reject")
        ->assertStatus(403);
});
