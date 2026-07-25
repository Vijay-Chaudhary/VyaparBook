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

/**
 * An owner plus the business they own, ready for actingAs().
 *
 * @return array{0: \App\Models\User, 1: \App\Models\Business}
 */
function pwOwner(): array
{
    $business = \App\Models\Business::factory()->create();
    $user = \App\Models\User::factory()->create();
    \App\Models\Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    return [$user, $business];
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

/*
|--------------------------------------------------------------------------
| Production costing test helpers (Phase 2b)
|--------------------------------------------------------------------------
|
| Shared across the CogsService and dashboard tests. In tests/Pest.php, not in
| a test file, so any one of them can be run on its own.
*/

/** A product with one pack of $weightKg, carrying the owner's estimate. */
function cogsProduct(\App\Models\Business $b, string $name, string $weightKg, ?string $estimate): array
{
    $product = \App\Models\Product::on('pgsql_migrate')->create([
        'business_id' => $b->id, 'name_hi' => $name, 'name_en' => $name,
    ]);
    // Pack sizes are shared across a tenant's products (unique on business+label),
    // so reuse rather than mint a duplicate when two products share a weight.
    $size = \App\Models\PackSize::on('pgsql_migrate')->firstOrCreate(
        ['business_id' => $b->id, 'label' => $weightKg . 'kg'],
        ['weight_kg' => $weightKg],
    );
    $pack = \App\Models\ProductPack::on('pgsql_migrate')->create([
        'business_id' => $b->id, 'product_id' => $product->id, 'pack_size_id' => $size->id,
        'default_sell_price' => '100.00', 'default_cost_price' => $estimate,
    ]);

    return [$product, $pack];
}

/** A completed batch: $outputKg produced from [materialId => qty consumed]. */
function cogsBatch(\App\Models\Business $b, \App\Models\User $u, \App\Models\Product $p, string $outputKg, array $consumed): void
{
    $batch = new \App\Models\ProductionBatch([
        'business_id' => $b->id, 'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'product_id' => $p->id, 'batch_date' => '2026-07-04', 'output_kg' => $outputKg,
    ]);
    $batch->setConnection('pgsql_migrate');
    $batch->created_by = $u->id;
    $batch->save();

    foreach ($consumed as $materialId => $qty) {
        $mc = new \App\Models\MaterialConsumption([
            'business_id' => $b->id, 'production_batch_id' => $batch->id,
            'raw_material_id' => $materialId, 'qty' => $qty,
        ]);
        $mc->setConnection('pgsql_migrate');
        $mc->save();
    }
}

/** Buy $qty kg of $material at $rate, so Phase 2a can price it. */
function cogsBuy(\App\Models\Business $b, \App\Models\User $u, \App\Models\RawMaterial $m, string $qty, string $rate): void
{
    $sup = pwSupplier($b, 'Supplier ' . \Illuminate\Support\Str::random(5));
    pwInTenant($b->id, function () use ($sup, $m, $qty, $rate, $u) {
        app()->bind('tenant.user_id', fn () => $u->id);
        (new \App\Services\PurchaseWriter())->record([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'supplier_id' => $sup->id, 'raw_material_id' => $m->id,
            'purchase_date' => '2026-07-01', 'qty' => $qty, 'unit_cost' => $rate, 'note' => null,
        ]);
    });
}

/*
|--------------------------------------------------------------------------
| Sales seeding helpers (customer, sale, sale line)
|--------------------------------------------------------------------------
|
| Shared by the dashboard unit tests and the dashboard feature tests.
*/

function dashCustomer(\App\Models\Business $b, string $name, string $opening = '0.00', ?string $village = null): \App\Models\Customer
{
    return \App\Models\Customer::on('pgsql_migrate')->create([
        'business_id' => $b->id,
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'name' => $name,
        'village' => $village,
        'opening_balance' => $opening,
    ]);
}

function dashSale(\App\Models\Customer $c, \App\Models\User $u, string $total, string $date): \App\Models\Sale
{
    $s = new \App\Models\Sale([
        'business_id' => $c->business_id, 'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'customer_id' => $c->id, 'sale_date' => $date,
    ]);
    $s->setConnection('pgsql_migrate');
    $s->created_by = $u->id;
    $s->total = $total;
    $s->save();

    return $s;
}

function saleLine(App\Models\Sale $s, App\Models\ProductPack $pack, int $qty, string $rate): void
{
    $line = new App\Models\SaleLine([
        'business_id' => $s->business_id, 'sale_id' => $s->id,
        'product_pack_id' => $pack->id, 'qty' => $qty, 'rate' => $rate,
    ]);
    $line->setConnection('pgsql_migrate');
    $line->line_total = bcmul($rate, (string) $qty, 2);
    $line->save();
}
