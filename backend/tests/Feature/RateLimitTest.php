<?php
// tests/Feature/RateLimitTest.php

use Illuminate\Support\Str;

// seedTenantWithOwner() / platformAdmin() are shared helpers in tests/Pest.php.

/**
 * Acting identity switches between tenants within a test. jwt-auth caches the
 * parsed token on its manager AND the resolved user on the guard, so both must
 * be reset or the second request silently reuses the first identity.
 */
function act(string $token, string $method, string $uri, array $body = [])
{
    app('tymon.jwt')->unsetToken();
    app('auth')->forgetGuards();

    return test()->withHeader('Authorization', "Bearer {$token}")->json($method, $uri, $body);
}

$newCustomer = fn () => ['name' => 'Ramesh '.Str::random(6)];

it('throttles a tenant that exceeds its write budget', function () use ($newCustomer) {
    config(['ratelimits.tenant_write' => 2]);
    [, $token] = seedTenantWithOwner('active', 'pro');

    act($token, 'POST', '/api/v1/customers', $newCustomer())->assertCreated();
    act($token, 'POST', '/api/v1/customers', $newCustomer())->assertCreated();

    act($token, 'POST', '/api/v1/customers', $newCustomer())->assertStatus(429);
});

/**
 * The entire point of keying per tenant: one busy shop must not be able to
 * spend another shop's budget. If this fails, the limiter is collapsing every
 * tenant into a single bucket and is worse than having none.
 */
it('never lets one tenant consume another tenant\'s budget', function () use ($newCustomer) {
    config(['ratelimits.tenant_write' => 2]);

    [, $noisy] = seedTenantWithOwner('active', 'pro');
    [, $quiet] = seedTenantWithOwner('active', 'pro');

    // Noisy tenant burns its whole allowance and then some.
    act($noisy, 'POST', '/api/v1/customers', $newCustomer())->assertCreated();
    act($noisy, 'POST', '/api/v1/customers', $newCustomer())->assertCreated();
    act($noisy, 'POST', '/api/v1/customers', $newCustomer())->assertStatus(429);

    // The quiet shop next door is unaffected.
    act($quiet, 'POST', '/api/v1/customers', $newCustomer())->assertCreated();
    act($quiet, 'POST', '/api/v1/customers', $newCustomer())->assertCreated();
});

/**
 * Separate buckets, so someone refreshing the khata list cannot block the sale
 * they are trying to record.
 */
it('keeps read and write budgets separate', function () use ($newCustomer) {
    config(['ratelimits.tenant_read' => 2, 'ratelimits.tenant_write' => 5]);
    [, $token] = seedTenantWithOwner('active', 'pro');

    act($token, 'GET', '/api/v1/khata')->assertOk();
    act($token, 'GET', '/api/v1/khata')->assertOk();
    act($token, 'GET', '/api/v1/khata')->assertStatus(429);

    // Reads are exhausted; writing still works.
    act($token, 'POST', '/api/v1/customers', $newCustomer())->assertCreated();
});

it('gives sync its own bucket, separate from interactive work', function () use ($newCustomer) {
    config(['ratelimits.sync' => 1, 'ratelimits.tenant_write' => 10]);
    [, $token] = seedTenantWithOwner('active', 'pro');

    act($token, 'GET', '/api/v1/sync/pull')->assertOk();
    act($token, 'GET', '/api/v1/sync/pull')->assertStatus(429);

    // Sync is exhausted; ordinary writes are untouched.
    act($token, 'POST', '/api/v1/customers', $newCustomer())->assertCreated();
});

it('throttles login attempts per email', function () {
    config(['ratelimits.login' => 2]);

    $attempt = fn () => test()->postJson('/api/v1/auth/login', [
        'email' => 'victim@example.com',
        'password' => 'wrong-password',
    ]);

    $attempt()->assertStatus(401);
    $attempt()->assertStatus(401);
    $attempt()->assertStatus(429);
});

it('does not let one email\'s failed logins lock out another', function () {
    config(['ratelimits.login' => 2]);

    $attempt = fn (string $email) => test()->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => 'wrong-password',
    ]);

    $attempt('attacker-target@example.com')->assertStatus(401);
    $attempt('attacker-target@example.com')->assertStatus(401);
    $attempt('attacker-target@example.com')->assertStatus(429);

    // A different account is unaffected.
    $attempt('someone-else@example.com')->assertStatus(401);
});

it('throttles the platform console per admin', function () {
    config(['ratelimits.platform' => 2]);
    [, $token] = platformAdmin();

    act($token, 'GET', '/api/v1/admin/tenants')->assertOk();
    act($token, 'GET', '/api/v1/admin/tenants')->assertOk();
    act($token, 'GET', '/api/v1/admin/tenants')->assertStatus(429);
});

it('tells a throttled caller when to retry', function () use ($newCustomer) {
    config(['ratelimits.tenant_write' => 1]);
    [, $token] = seedTenantWithOwner('active', 'pro');

    act($token, 'POST', '/api/v1/customers', $newCustomer())->assertCreated();

    $response = act($token, 'POST', '/api/v1/customers', $newCustomer())->assertStatus(429);

    // A client on a flaky rural connection needs to know this is temporary and
    // when to come back, not just that it failed.
    expect($response->headers->get('Retry-After'))->not->toBeNull();
});

/**
 * The discriminating test: two staff in the SAME shop share one budget.
 *
 * The cross-tenant test above passes even if the limiter keys on user id
 * (each tenant there has a different owner), so it cannot tell per-tenant from
 * per-user. This one can — a shop's six staff on six phones are one unit of
 * load, and keying per user would hand them six budgets.
 */
it('shares one budget across the staff of a single shop', function () use ($newCustomer) {
    config(['ratelimits.tenant_write' => 2]);

    [$business, $ownerToken] = seedTenantWithOwner('active', 'pro');

    $salesman = \App\Models\User::factory()->create();
    $membership = \App\Models\Membership::create([
        'user_id' => $salesman->id,
        'business_id' => $business->id,
        'role' => 'salesman',
    ]);
    $salesmanToken = (new \App\Services\TokenService())->issue($salesman, $membership);

    // The owner spends the shop's whole write allowance.
    act($ownerToken, 'POST', '/api/v1/customers', $newCustomer())->assertCreated();
    act($ownerToken, 'POST', '/api/v1/customers', $newCustomer())->assertCreated();

    // A different user in the same shop is throttled too — same bucket.
    act($salesmanToken, 'POST', '/api/v1/customers', $newCustomer())->assertStatus(429);
});
