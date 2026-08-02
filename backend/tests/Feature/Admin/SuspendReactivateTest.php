<?php
// tests/Feature/Admin/SuspendReactivateTest.php

use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use Illuminate\Support\Str;
use App\Support\Tenancy;

// platformAdmin() / seedTenantWithOwner() are shared helpers in tests/Pest.php.

it('suspends a tenant into read_only and audits the transition', function () {
    [$business] = seedTenantWithOwner('active', 'pro');
    [$admin, $token] = platformAdmin();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/suspend", ['reason' => 'chargeback'])
        ->assertOk()
        ->assertJsonPath('subscription.status', 'read_only');

    expect(Tenancy::withoutTenant(fn () => Subscription::on('mysql_platform')->where('business_id', $business->id)->first())->status)->toBe('read_only');

    $logs = PlatformAuditLog::where('action', 'suspend_tenant')->get();
    expect($logs)->toHaveCount(1);
    expect($logs[0]->admin_user_id)->toBe($admin->id)
        ->and($logs[0]->metadata['from'])->toBe('active')
        ->and($logs[0]->metadata['to'])->toBe('read_only')
        ->and($logs[0]->metadata['reason'])->toBe('chargeback');
});

it('pauses the tenant\'s domain writes once suspended, and restores them on reactivate', function () {
    [$business, $ownerToken] = seedTenantWithOwner('active', 'pro');
    [, $adminToken] = platformAdmin();

    // The acting identity switches (owner ⇄ admin) within one test. jwt-auth
    // caches the parsed token on its manager AND the resolved user on the guard,
    // and the admin route never re-parses (it has no tenant.context), so both must
    // be reset on each switch or the second request reuses the first identity.
    $act = function (string $token, string $method, string $uri, array $body = []) {
        $this->app['tymon.jwt']->unsetToken();
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")->json($method, $uri, $body);
    };
    $create = fn () => $act($ownerToken, 'POST', '/api/v1/customers', ['name' => 'Ramesh '.Str::random(5)]);

    // Active: the owner can write.
    $create()->assertCreated();

    // Suspend via the console → the owner's next write is soft-blocked (402).
    $act($adminToken, 'POST', "/api/v1/admin/tenants/{$business->id}/suspend")->assertOk();
    $create()->assertStatus(402)->assertJsonPath('code', 'read_only');

    // Reactivate → the write flows again.
    $act($adminToken, 'POST', "/api/v1/admin/tenants/{$business->id}/reactivate")
        ->assertOk()
        ->assertJsonPath('subscription.status', 'active');
    $create()->assertCreated();
});

it('is idempotent: a second suspend is a no-op with no second audit entry', function () {
    [$business] = seedTenantWithOwner('active', 'pro');
    [, $token] = platformAdmin();

    foreach (range(1, 2) as $_) {
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/admin/tenants/{$business->id}/suspend")
            ->assertOk()
            ->assertJsonPath('subscription.status', 'read_only');
    }

    expect(PlatformAuditLog::where('action', 'suspend_tenant')->count())->toBe(1);
});

it('reactivates to the status the dates imply, never a blind active', function (string $seedStatus, string $expected) {
    [$business] = seedTenantWithOwner($seedStatus, $seedStatus === 'active' ? 'pro' : 'free');
    [, $token] = platformAdmin();

    // Suspend first so reactivate has something to lift.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/suspend")->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/reactivate")
        ->assertOk()
        ->assertJsonPath('subscription.status', $expected);
})->with([
    'open paid period → active' => ['active', 'active'],
    'live trial → trialing' => ['trialing', 'trialing'],
    'everything lapsed → past_due' => ['past_due', 'past_due'],
]);

it('reactivate is a no-op on a tenant that is not suspended', function () {
    [$business] = seedTenantWithOwner('active', 'pro');
    [, $token] = platformAdmin();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/reactivate")
        ->assertOk()
        ->assertJsonPath('subscription.status', 'active');

    expect(PlatformAuditLog::where('action', 'reactivate_tenant')->count())->toBe(0);
});

it('404s when suspending an unknown tenant', function () {
    [, $token] = platformAdmin();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/tenants/'.Str::uuid().'/suspend')
        ->assertStatus(404);
});

it('gates suspend and reactivate behind the platform guard', function () {
    [$business, $ownerToken] = seedTenantWithOwner('active', 'pro');

    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/suspend")->assertStatus(403);
    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson("/api/v1/admin/tenants/{$business->id}/reactivate")->assertStatus(403);
});
