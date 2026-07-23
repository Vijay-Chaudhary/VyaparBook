<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Tests\RefreshesTenantDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Platform (Superadmin) console test helpers
|--------------------------------------------------------------------------
|
| Shared across tests/Feature/Admin/*. Defined here (not per file) because Pest
| loads every test file into the same scope — a duplicated function would fatal.
*/

/** A tid-less JWT for a fresh platform admin user. */
function platformAdminToken(): string
{
    $admin = \App\Models\User::factory()->create(['is_platform_admin' => true]);

    return (new \App\Services\TokenService())->issue($admin);
}

/**
 * Seed a tenant (business + owner membership + subscription) and return the
 * business plus an owner-scoped token, so a test can act as the tenant's owner.
 *
 * @return array{0: \App\Models\Business, 1: string}
 */
function seedTenantWithOwner(string $status = 'trialing', string $plan = 'free'): array
{
    $business = \App\Models\Business::factory()->create();
    $user = \App\Models\User::factory()->create();
    $membership = \App\Models\Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);
    \App\Models\Subscription::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'plan' => $plan,
        'status' => $status,
        'trial_ends_at' => $status === 'trialing' ? now()->addDays(14) : now()->subDay(),
        'current_period_end' => in_array($status, ['active', 'read_only'], true) ? now()->addMonth() : null,
    ]);

    return [$business, (new \App\Services\TokenService())->issue($user, $membership)];
}

/** A platform admin plus an admin-scoped token, so tests can assert on the user id. */
function platformAdmin(): array
{
    $admin = \App\Models\User::factory()->create(['is_platform_admin' => true]);

    return [$admin, (new \App\Services\TokenService())->issue($admin)];
}

/*
|--------------------------------------------------------------------------
| Suppliers & purchases test helpers (Phase 2a)
|--------------------------------------------------------------------------
|
| Shared across tests/Unit/Purchase*Test.php. Defined here rather than in one
| of those files so a single-file run (`pest tests/Unit/PurchaseAggregationTest
| .php`) still has them — a test file that isn't selected is never loaded.
*/

/** Run $fn inside a tenant-pinned transaction (RLS session var + app-level scope). */
function pwInTenant(string $businessId, callable $fn): mixed
{
    return \Illuminate\Support\Facades\DB::transaction(function () use ($businessId, $fn) {
        \App\Support\TenantContext::switchTo($businessId);
        app()->bind('tenant.id', fn () => $businessId);

        return $fn();
    });
}

/** A supplier seeded on the migration connection (bypasses RLS, like the other seed helpers). */
function pwSupplier(\App\Models\Business $b, string $name = 'Besan Traders', string $opening = '0.00'): \App\Models\Supplier
{
    return \App\Models\Supplier::on('pgsql_migrate')->create([
        'business_id' => $b->id,
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'name' => $name,
        'opening_balance' => $opening,
    ]);
}

/** A kg-denominated raw material seeded on the migration connection. */
function pwMaterial(\App\Models\Business $b, string $name = 'Besan'): \App\Models\RawMaterial
{
    return \App\Models\RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $b->id,
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'name' => $name,
        'unit' => 'kg',
        'reorder_level' => '0.000',
    ]);
}

/** A pending manual/UPI payment lodged (out-of-band) for a business. */
function pendingPayment(string $businessId, string $plan = 'pro'): \App\Models\SubscriptionPayment
{
    return \App\Models\SubscriptionPayment::on('pgsql_migrate')->create([
        'business_id' => $businessId,
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'plan' => $plan,
        'amount' => '999.00',
        'gst_amount' => '179.82',
        'mode' => 'upi',
        'period_months' => 1,
        'status' => 'pending',
    ]);
}
