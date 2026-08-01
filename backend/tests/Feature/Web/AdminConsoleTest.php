<?php
// tests/Feature/Web/AdminConsoleTest.php

use App\Models\Business;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;

/*
 * The Blade platform (Superadmin) console (docs/frontend-plan.md §7 Phase 7):
 * session-authorised, gated on the live is_platform_admin flag, cross-tenant.
 *
 * The server-rendered twin of /api/v1/admin/* — these tests confirm the web
 * surface reaches the same tenants, drives the same shared service seams
 * (PlatformTenantContext / SubscriptionService), and writes the same audit trail.
 */

/** A signed-in platform admin. */
function platformAdminUser(): User
{
    return User::factory()->create(['is_platform_admin' => true]);
}

describe('access', function () {
    it('sends a guest to login', function () {
        $this->get('/admin/console')->assertRedirect(route('login'));
    });

    it('forbids a signed-in non-admin', function () {
        $this->actingAs(User::factory()->create(['is_platform_admin' => false]))
            ->get('/admin/console')
            ->assertForbidden();
    });

    it('honours the current flag — a revoked admin is refused', function () {
        $user = platformAdminUser();
        $this->actingAs($user)->get('/admin/console')->assertOk();

        // Flag pulled (forceFill: is_platform_admin is guarded, so update() would
        // silently ignore it). The middleware reads the current value, not a cached
        // decision, so the next request is 403.
        $user->forceFill(['is_platform_admin' => false])->save();
        $this->actingAs($user)->get('/admin/console')->assertForbidden();
    });
});

describe('directory', function () {
    it('lists tenants across the platform with their billing state', function () {
        [$a] = seedTenantWithOwner('active', 'pro');
        [$b] = seedTenantWithOwner('trialing', 'free');

        $this->actingAs(platformAdminUser())
            ->get('/admin/console')
            ->assertOk()
            ->assertSee($a->name)
            ->assertSee($b->name);
    });

    it('filters by a case-insensitive name search', function () {
        seedTenantWithOwner();
        $named = Business::factory()->create(['name' => 'Sharma Distributors']);

        $this->actingAs(platformAdminUser())
            ->get('/admin/console?q=sharma')
            ->assertOk()
            ->assertSee('Sharma Distributors');
    });
});

describe('drill-down', function () {
    it('shows the tenant, its members and its payments', function () {
        [$business] = seedTenantWithOwner();
        pendingPayment($business->id);

        $this->actingAs(platformAdminUser())
            ->get(route('admin.console.show', $business->id))
            ->assertOk()
            ->assertSee($business->name)
            ->assertSee(__('admin.members'))
            ->assertSee(__('admin.payments'));
    });

    it('404s an unknown tenant', function () {
        $this->actingAs(platformAdminUser())
            ->get(route('admin.console.show', '00000000-0000-0000-0000-000000000000'))
            ->assertNotFound();
    });
});

describe('suspend / reactivate', function () {
    it('suspends a tenant into read_only and audits it', function () {
        [$business] = seedTenantWithOwner('trialing');
        $admin = platformAdminUser();

        $this->actingAs($admin)
            ->post(route('admin.console.suspend', $business->id), ['reason' => 'chargeback'])
            ->assertRedirect(route('admin.console.show', $business->id))
            ->assertSessionHas('console_status', 'suspended');

        expect(Subscription::where('business_id', $business->id)->value('status'))
            ->toBe('read_only');

        $log = PlatformAuditLog::where('action', 'suspend_tenant')->where('target_business_id', $business->id)->first();
        expect($log)->not->toBeNull();
        expect($log->admin_user_id)->toBe($admin->id);
    });

    it('reactivates a read_only tenant', function () {
        [$business] = seedTenantWithOwner('read_only');

        $this->actingAs(platformAdminUser())
            ->post(route('admin.console.reactivate', $business->id))
            ->assertRedirect(route('admin.console.show', $business->id))
            ->assertSessionHas('console_status', 'reactivated');

        // read_only with an open period → active (naturalStatus).
        expect(Subscription::where('business_id', $business->id)->value('status'))
            ->toBe('active');
    });
});

describe('payments', function () {
    it('verifies a pending payment and activates the plan', function () {
        [$business] = seedTenantWithOwner('past_due');
        $payment = pendingPayment($business->id);

        $this->actingAs(platformAdminUser())
            ->post(route('admin.console.payment.verify', [$business->id, $payment->id]))
            ->assertRedirect(route('admin.console.show', $business->id))
            ->assertSessionHas('console_status', 'payment_verified');

        expect(SubscriptionPayment::where('id', $payment->id)->value('status'))
            ->toBe('verified');
        expect(Subscription::where('business_id', $business->id)->value('status'))
            ->toBe('active');

        expect(PlatformAuditLog::where('action', 'verify_payment')->where('target_business_id', $business->id)->exists())
            ->toBeTrue();
    });

    it('rejects a pending payment and audits it', function () {
        [$business] = seedTenantWithOwner();
        $payment = pendingPayment($business->id);

        $this->actingAs(platformAdminUser())
            ->post(route('admin.console.payment.reject', [$business->id, $payment->id]), ['reason' => 'not received'])
            ->assertRedirect(route('admin.console.show', $business->id))
            ->assertSessionHas('console_status', 'payment_rejected');

        expect(SubscriptionPayment::where('id', $payment->id)->value('status'))
            ->toBe('rejected');
        expect(PlatformAuditLog::where('action', 'reject_payment')->where('target_business_id', $business->id)->exists())
            ->toBeTrue();
    });

    it('refuses to verify an already-rejected payment', function () {
        [$business] = seedTenantWithOwner();
        $payment = pendingPayment($business->id);
        SubscriptionPayment::where('id', $payment->id)->update(['status' => 'rejected']);

        $this->actingAs(platformAdminUser())
            ->post(route('admin.console.payment.verify', [$business->id, $payment->id]))
            ->assertSessionHas('console_error', 'verify_rejected');

        expect(SubscriptionPayment::where('id', $payment->id)->value('status'))
            ->toBe('rejected');
    });
});

describe('impersonation (view as tenant)', function () {
    it('launches into /app, stashing the read-only token in the session, and audits it', function () {
        [$business] = seedTenantWithOwner(); // creates an owner membership

        $this->actingAs(platformAdminUser())
            ->post(route('admin.console.impersonate', $business->id), ['role' => 'owner', 'reason' => 'support'])
            // Drops the operator straight into the app rendered as the tenant.
            ->assertRedirect(route('app'))
            ->assertSessionHas('impersonation');

        $impersonation = session('impersonation');
        expect($impersonation['role'])->toBe('owner');
        expect($impersonation['tenant_id'])->toBe($business->id);
        expect($impersonation['token'])->toBeString()->not->toBeEmpty();
        expect($impersonation['expires_at'])->toBeString(); // 30-min window

        expect(PlatformAuditLog::where('action', 'impersonate_tenant')->where('target_business_id', $business->id)->exists())
            ->toBeTrue();
    });

    it('hands the impersonation token to the SPA via the session→JWT bridge', function () {
        [$business] = seedTenantWithOwner();

        $admin = platformAdminUser();
        $this->actingAs($admin)
            ->post(route('admin.console.impersonate', $business->id), ['role' => 'owner']);

        // The same session now drives /auth/token: it returns the impersonation
        // token (not one for the operator's own memberships) with the context the
        // app needs to render the read-only banner.
        $this->actingAs($admin)
            ->getJson('/auth/token')
            ->assertOk()
            ->assertJsonPath('tenant_id', $business->id)
            ->assertJsonPath('role', 'owner')
            ->assertJsonPath('impersonation.tenant_name', $business->name)
            ->assertJsonPath('impersonation.exit_url', route('admin.impersonation.exit'))
            ->assertJson(fn ($json) => $json->where('token', session('impersonation')['token'])->etc());
    });

    it('ends the session on exit and returns to the tenant drill-down', function () {
        [$business] = seedTenantWithOwner();
        $admin = platformAdminUser();

        $this->actingAs($admin)->post(route('admin.console.impersonate', $business->id), ['role' => 'owner']);

        $this->actingAs($admin)
            ->post(route('admin.impersonation.exit'))
            ->assertRedirect(route('admin.console.show', $business->id))
            ->assertSessionMissing('impersonation');
    });

    it('refuses a role no member holds, launching nothing', function () {
        [$business] = seedTenantWithOwner(); // only an owner exists

        $this->actingAs(platformAdminUser())
            ->post(route('admin.console.impersonate', $business->id), ['role' => 'salesman'])
            ->assertRedirect(route('admin.console.show', $business->id))
            ->assertSessionHas('console_error', 'role_absent')
            ->assertSessionMissing('impersonation');

        expect(PlatformAuditLog::where('action', 'impersonate_tenant')->where('target_business_id', $business->id)->exists())
            ->toBeFalse();
    });
});
