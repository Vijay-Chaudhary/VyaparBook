<?php
// tests/Feature/Admin/ImpersonationTest.php

use App\Models\Customer;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Support\Str;

// platformAdmin() / seedTenantWithOwner() are shared helpers in tests/Pest.php.

/**
 * The acting identity switches (admin → impersonation token) within a test.
 * jwt-auth caches the parsed token on its manager AND the resolved user on the
 * guard, so both must be reset on each switch or the second request silently
 * reuses the first identity. Same reason as SuspendReactivateTest's $act.
 */
function actAs(string $token, string $method, string $uri, array $body = [])
{
    // app() (not test()->app, which is protected outside a bound closure)
    // resolves the same container instance the test case holds.
    app('tymon.jwt')->unsetToken();
    app('auth')->forgetGuards();

    return test()->withHeader('Authorization', "Bearer {$token}")->json($method, $uri, $body);
}

/** Mint an impersonation token for a business as a platform admin. */
function impersonate(string $adminToken, string $businessId, array $body = []): string
{
    return actAs($adminToken, 'POST', "/api/v1/admin/tenants/{$businessId}/impersonate", $body)
        ->assertOk()
        ->json('token');
}

it('mints a read-only token that sees the tenant, and audits the mint', function () {
    [$business, $ownerToken] = seedTenantWithOwner('active', 'pro');
    [$admin, $adminToken] = platformAdmin();

    // The owner puts a customer in the books.
    actAs($ownerToken, 'POST', '/api/v1/customers', ['name' => 'Ramesh Verma'])->assertCreated();

    $response = actAs($adminToken, 'POST', "/api/v1/admin/tenants/{$business->id}/impersonate", ['reason' => 'khata mismatch #412'])
        ->assertOk()
        ->assertJsonPath('read_only', true)
        ->assertJsonPath('role', 'owner')
        ->assertJsonPath('tenant.id', $business->id);

    // The admin now sees exactly what the owner sees. /khata is the "who owes me"
    // read every role shares — there is no GET /customers.
    actAs($response->json('token'), 'GET', '/api/v1/khata')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Ramesh Verma']);

    $logs = PlatformAuditLog::on('pgsql_migrate')->where('action', 'impersonate_tenant')->get();
    expect($logs)->toHaveCount(1);
    expect($logs[0]->admin_user_id)->toBe($admin->id)
        ->and($logs[0]->target_business_id)->toBe($business->id)
        ->and($logs[0]->metadata['role'])->toBe('owner')
        ->and($logs[0]->metadata['reason'])->toBe('khata mismatch #412');
});

it('refuses every write through an impersonation token', function (string $method, string $uri, array $body) {
    [$business] = seedTenantWithOwner('active', 'pro');
    [, $adminToken] = platformAdmin();

    $token = impersonate($adminToken, $business->id);

    actAs($token, $method, $uri, $body)
        ->assertStatus(403)
        ->assertJsonPath('code', 'impersonation_read_only');

    // Nothing landed in the tenant's books.
    expect(Customer::on('pgsql_platform')->where('business_id', $business->id)->count())->toBe(0);
})->with([
    'create a customer' => ['POST', '/api/v1/customers', ['name' => 'Ghost']],
    'record a sale' => ['POST', '/api/v1/sales', ['customer_id' => 1, 'lines' => []]],
    'record a payment' => ['POST', '/api/v1/payments', ['amount' => '100.00']],
    'push offline changes' => ['POST', '/api/v1/sync/push', ['changes' => []]],
]);

it('cannot re-enter the platform console with an impersonation token', function () {
    [$business] = seedTenantWithOwner('active', 'pro');
    [, $adminToken] = platformAdmin();

    $token = impersonate($adminToken, $business->id);

    // The holder IS a platform admin — the flag check passes; the imp claim is
    // what stops it. Otherwise a "read-only" token would carry every console
    // mutation, including suspending the very tenant it was scoped to.
    actAs($token, 'GET', '/api/v1/admin/tenants')->assertStatus(403);
    actAs($token, 'POST', "/api/v1/admin/tenants/{$business->id}/suspend")->assertStatus(403);
});

it('dies the moment the admin flag is revoked, mid-session', function () {
    [$business] = seedTenantWithOwner('active', 'pro');
    [$admin, $adminToken] = platformAdmin();

    $token = impersonate($adminToken, $business->id);
    actAs($token, 'GET', '/api/v1/khata')->assertOk();

    // Revoke the flag on the live row — the token itself is still unexpired.
    User::on('pgsql_migrate')->where('id', $admin->id)->update(['is_platform_admin' => false]);

    actAs($token, 'GET', '/api/v1/khata')->assertStatus(403);
});

it('confines an impersonation token to the tenant it was minted for', function () {
    [$mine] = seedTenantWithOwner('active', 'pro');
    [$other, $otherOwnerToken] = seedTenantWithOwner('active', 'pro');
    [, $adminToken] = platformAdmin();

    actAs($otherOwnerToken, 'POST', '/api/v1/customers', ['name' => 'Neighbour Tenant'])->assertCreated();

    $token = impersonate($adminToken, $mine->id);

    // Scoped to $mine, so the neighbour's row is invisible under RLS.
    actAs($token, 'GET', '/api/v1/khata')
        ->assertOk()
        ->assertJsonMissing(['name' => 'Neighbour Tenant']);
});

it('can reproduce a narrower role\'s view on request', function () {
    [$business] = seedTenantWithOwner('active', 'pro');
    [, $adminToken] = platformAdmin();

    $salesman = User::factory()->create();
    \App\Models\Membership::on('pgsql_migrate')->create([
        'user_id' => $salesman->id,
        'business_id' => $business->id,
        'role' => 'salesman',
    ]);

    $token = impersonate($adminToken, $business->id, ['role' => 'salesman']);

    actAs($token, 'GET', '/api/v1/whoami')
        ->assertOk()
        ->assertJsonPath('role', 'salesman');

    // Stock is owner/admin-only (PRD §7) — impersonating a salesman must not
    // widen it, or the reproduced view would be a fiction no real user sees.
    actAs($token, 'GET', '/api/v1/stock')->assertStatus(403);
});

it('rejects a role the tenant has nobody holding', function () {
    [$business] = seedTenantWithOwner('active', 'pro'); // owner only
    [, $adminToken] = platformAdmin();

    actAs($adminToken, 'POST', "/api/v1/admin/tenants/{$business->id}/impersonate", ['role' => 'accountant'])
        ->assertStatus(422);

    expect(PlatformAuditLog::on('pgsql_migrate')->where('action', 'impersonate_tenant')->count())->toBe(0);
});

it('404s on an unknown tenant', function () {
    [, $adminToken] = platformAdmin();

    actAs($adminToken, 'POST', '/api/v1/admin/tenants/'.Str::uuid().'/impersonate')->assertStatus(404);
});

it('gates the impersonate endpoint behind the platform guard', function () {
    [$business, $ownerToken] = seedTenantWithOwner('active', 'pro');

    actAs($ownerToken, 'POST', "/api/v1/admin/tenants/{$business->id}/impersonate")->assertStatus(403);
});

it('leaves ordinary token TTL untouched after minting a short-lived one', function () {
    [$business] = seedTenantWithOwner('active', 'pro');
    [, $adminToken] = platformAdmin();

    $ttlBefore = app('tymon.jwt')->factory()->getTTL();
    impersonate($adminToken, $business->id);

    expect(app('tymon.jwt')->factory()->getTTL())->toBe($ttlBefore);
});
