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
