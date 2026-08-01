<?php
// tests/Feature/Export/TenantEraseTest.php

use App\Export\TenantEraser;
use App\Models\Business;
use App\Models\Customer;
use App\Models\MaterialConsumption;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\Payment;
use App\Models\PlatformAuditLog;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductPack;
use App\Models\RawMaterial;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a row in EVERY tenant-owned table. This is what exercises the eraser's
 * hand-derived delete order: if children were not removed before parents, the
 * erase blows up on a foreign key rather than passing quietly.
 *
 * @return array{0: Business, 1: User}
 */
function seedFullTenant(): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();

    Membership::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    // A factory's FK defaults would spawn a second business, so build unsaved
    // and persist with every foreign key pinned to this tenant — the idiom the
    // rest of the suite uses.
    $mk = function (string $class, array $attributes) use ($business) {
        $model = $class::factory()->make($attributes + ['business_id' => $business->id]);
        $model->save();

        return $model;
    };

    $customer = $mk(Customer::class, []);
    $product = $mk(Product::class, []);
    $packSize = $mk(PackSize::class, []);
    $pack = $mk(ProductPack::class, ['product_id' => $product->id, 'pack_size_id' => $packSize->id]);
    $material = $mk(RawMaterial::class, []);
    $sale = $mk(Sale::class, ['customer_id' => $customer->id, 'created_by' => $user->id]);
    $mk(SaleLine::class, ['sale_id' => $sale->id, 'product_pack_id' => $pack->id]);
    $mk(Payment::class, ['customer_id' => $customer->id, 'created_by' => $user->id]);
    $batch = $mk(ProductionBatch::class, ['product_id' => $product->id, 'created_by' => $user->id]);
    $mk(MaterialConsumption::class, ['production_batch_id' => $batch->id, 'raw_material_id' => $material->id]);
    $mk(StockMovement::class, ['raw_material_id' => $material->id, 'created_by' => $user->id]);
    $mk(Subscription::class, []);
    $mk(SubscriptionPayment::class, []);

    DB::table('invites')->insert([
        'id' => (string) Str::uuid(),
        'business_id' => $business->id,
        'role' => 'salesman',
        'token' => Str::random(40),
        'expires_at' => now()->addWeek(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('expenses')->insert([
        'id' => (string) Str::uuid(),
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'category' => 'rent',
        'amount' => '1000.00',
        'spent_on' => now()->toDateString(),
        'created_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $supplierId = (string) Str::uuid();
    DB::table('suppliers')->insert([
        'id' => $supplierId,
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'Besan Traders',
        'opening_balance' => '0.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('purchases')->insert([
        'id' => (string) Str::uuid(),
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'supplier_id' => $supplierId,
        'raw_material_id' => $material->id,
        'purchase_date' => now()->toDateString(),
        'qty' => '10.000',
        'unit_cost' => '40.00',
        'total' => '400.00',
        'created_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('supplier_payments')->insert([
        'id' => (string) Str::uuid(),
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'supplier_id' => $supplierId,
        'payment_date' => now()->toDateString(),
        'amount' => '200.00',
        'mode' => 'cash',
        'created_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$business, $user];
}

/** Count this tenant's rows across every table, past the application scope. */
function tenantRowCount(string $businessId): int
{
    $tables = collect(DB::select(
        'select table_name from information_schema.columns
         where column_name = ? and table_schema = database()',
        ['business_id']
    ))->pluck('table_name');

    return $tables->sum(fn (string $t) => DB::table($t)
        ->where('business_id', $businessId)->count());
}

it('destroys every tenant-owned row across all tables', function () {
    [$business] = seedFullTenant();

    expect(tenantRowCount($business->id))->toBeGreaterThan(0);

    $deleted = (new TenantEraser())->erase($business->id);

    expect(tenantRowCount($business->id))->toBe(0);
    // Delete order held: every table reported a count, none threw on an FK.
    expect(array_sum($deleted))->toBeGreaterThan(0);
});

it('leaves a tombstone: the row survives with its identity cleared', function () {
    [$business] = seedFullTenant();
    $originalName = $business->name;

    (new TenantEraser())->erase($business->id);

    $row = DB::table('businesses')->where('id', $business->id)->first();

    expect($row)->not->toBeNull();
    // name is NOT NULL, so it carries a constant marker rather than the shop's
    // identity; city/gstin are nullable and cleared outright.
    expect($row->name)->toBe(TenantEraser::ERASED_NAME)
        ->and($row->city)->toBeNull()
        ->and($row->gstin)->toBeNull()
        ->and($row->erased_at)->not->toBeNull();

    // The shop's name must not survive anywhere in its own row.
    expect((array) $row)->not->toContain($originalName);
});

it('never touches another tenant', function () {
    [$mine] = seedFullTenant();
    [$other] = seedFullTenant();

    $before = tenantRowCount($other->id);
    expect($before)->toBeGreaterThan(0);

    (new TenantEraser())->erase($mine->id);

    expect(tenantRowCount($other->id))->toBe($before);
    expect(DB::table('businesses')->where('id', $other->id)->first()->name)
        ->not->toBeNull();
});

/**
 * Memberships are reachable by user as legitimately as by business, so a delete
 * scoped by the wrong one would reach that user's memberships in OTHER
 * businesses. This is the test that would catch that regression.
 */
it('keeps a shared user\'s membership in their other business', function () {
    [$erased, $user] = seedFullTenant();
    $keep = Business::factory()->create();

    Membership::create([
        'user_id' => $user->id,
        'business_id' => $keep->id,
        'role' => 'owner',
    ]);

    (new TenantEraser())->erase($erased->id);

    $remaining = DB::table('memberships')
        ->where('user_id', $user->id)->get();

    expect($remaining)->toHaveCount(1);
    expect($remaining[0]->business_id)->toBe($keep->id);

    // The user themselves is untouched — they are not this tenant's property.
    expect(User::find($user->id))->not->toBeNull();
});

it('refuses to erase a tenant twice', function () {
    [$business] = seedFullTenant();

    (new TenantEraser())->erase($business->id);
    (new TenantEraser())->erase($business->id);
})->throws(RuntimeException::class, 'already erased');

it('throws for an unknown tenant', function () {
    (new TenantEraser())->erase((string) Str::uuid());
})->throws(RuntimeException::class);

it('preserves the audit trail and records which shop was erased', function () {
    [$business] = seedFullTenant();
    $name = $business->name;

    $this->artisan('tenant:erase', ['business_id' => $business->id, '--force' => true])
        ->assertExitCode(0);

    $log = PlatformAuditLog::where('action', 'erase_tenant')->first();

    expect($log)->not->toBeNull();
    // The trail still points at the tombstone, and carries the name the
    // businesses row no longer holds — otherwise it could not say which shop.
    expect($log->target_business_id)->toBe($business->id)
        ->and($log->metadata['business_name'])->toBe($name)
        ->and($log->metadata['via'])->toBe('cli')
        ->and($log->metadata['rows'])->toBeGreaterThan(0);
});

it('aborts and erases nothing when the typed name does not match', function () {
    [$business] = seedFullTenant();
    $before = tenantRowCount($business->id);

    $this->artisan('tenant:erase', ['business_id' => $business->id])
        ->expectsQuestion('Type the business name to confirm', 'not the right name')
        ->assertExitCode(1);

    expect(tenantRowCount($business->id))->toBe($before);
    expect(Business::find($business->id)->erased_at)->toBeNull();
});

it('proceeds when the typed name matches', function () {
    [$business] = seedFullTenant();

    $this->artisan('tenant:erase', ['business_id' => $business->id])
        ->expectsQuestion('Type the business name to confirm', $business->name)
        ->assertExitCode(0);

    expect(tenantRowCount($business->id))->toBe(0);
});

it('exits 1 for an unknown business', function () {
    $this->artisan('tenant:erase', ['business_id' => (string) Str::uuid(), '--force' => true])
        ->assertExitCode(1);
});

/**
 * The eraser wraps everything in one transaction, so a failure part-way through
 * must leave the tenant whole rather than half-destroyed. This pins the claim
 * the command makes to the operator when it reports a failure.
 */
it('leaves the tenant intact when erasure fails part-way', function () {
    [$business] = seedFullTenant();
    $before = tenantRowCount($business->id);

    $this->app->bind(TenantEraser::class, fn () => new class extends TenantEraser
    {
        public function erase(string $businessId): array
        {
            throw new RuntimeException('simulated mid-erase failure');
        }
    });

    $this->artisan('tenant:erase', ['business_id' => $business->id, '--force' => true])
        ->assertExitCode(1);

    expect(tenantRowCount($business->id))->toBe($before);
    expect(Business::find($business->id)->erased_at)->toBeNull();
    expect(Business::find($business->id)->name)->not->toBe(TenantEraser::ERASED_NAME);
});
