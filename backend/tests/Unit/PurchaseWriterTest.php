<?php
// tests/Unit/PurchaseWriterTest.php
//
// Uses the global inTenant() helper defined in DashboardReportServiceTest.php
// (Pest loads every Unit test file into one scope).

use App\Models\Business;
use App\Models\Purchase;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseWriter;
use App\Services\StockService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local tenant-pin helper (uniquely named so single-file runs don't need the dashboard test's inTenant). */
function pwInTenant(string $businessId, callable $fn): mixed
{
    return DB::transaction(function () use ($businessId, $fn) {
        TenantContext::switchTo($businessId);
        app()->bind('tenant.id', fn () => $businessId);

        return $fn();
    });
}

function pwSupplier(Business $b, string $name = 'Besan Traders', string $opening = '0.00'): Supplier
{
    return Supplier::on('pgsql_migrate')->create([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(), 'name' => $name, 'opening_balance' => $opening,
    ]);
}

function pwMaterial(Business $b, string $name = 'Besan'): RawMaterial
{
    return RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'name' => $name, 'unit' => 'kg', 'reorder_level' => '0.000',
    ]);
}

it('records a purchase with a linked costed stock-in, and is idempotent', function () {
    $a = Business::factory()->create();
    $u = User::factory()->create();
    $s = pwSupplier($a);
    $m = pwMaterial($a);
    $uuid = (string) Str::uuid();

    [$purchase, $onHand] = pwInTenant($a->id, function () use ($s, $m, $u, $uuid) {
        app()->bind('tenant.user_id', fn () => $u->id);
        $writer = new PurchaseWriter();

        $line = ['uuid' => $uuid, 'supplier_id' => $s->id, 'raw_material_id' => $m->id,
            'purchase_date' => '2026-07-10', 'qty' => '10', 'unit_cost' => '40', 'note' => null];

        [$p, $created] = $writer->record($line);
        expect($created)->toBeTrue();

        [, $created2] = $writer->record($line);   // replay same uuid
        expect($created2)->toBeFalse();

        // Read on-hand on the DEFAULT connection (same transaction the writer used),
        // so the just-written movement is visible — mirrors the controller flow.
        return [$p->fresh(), (new StockService())->onHandFor(RawMaterial::find($m->id))];
    });

    expect($purchase->total)->toBe('400.00');   // 10 × 40
    expect($onHand)->toBe('10.000');            // one costed stock-in
    expect(Purchase::on('pgsql_migrate')->where('business_id', $a->id)->count())->toBe(1);
    expect(StockMovement::on('pgsql_migrate')->where('purchase_id', $purchase->id)->where('kind', 'in')->count())->toBe(1);
});

it('reverses the stock-in when a purchase is removed', function () {
    $a = Business::factory()->create();
    $u = User::factory()->create();
    $s = pwSupplier($a);
    $m = pwMaterial($a);

    $onHandAfter = pwInTenant($a->id, function () use ($s, $m, $u) {
        app()->bind('tenant.user_id', fn () => $u->id);
        $writer = new PurchaseWriter();
        [$p] = $writer->record(['uuid' => (string) Str::uuid(), 'supplier_id' => $s->id, 'raw_material_id' => $m->id,
            'purchase_date' => '2026-07-10', 'qty' => '10', 'unit_cost' => '40', 'note' => null]);
        $writer->remove($p);

        return (new StockService())->onHandFor(RawMaterial::find($m->id));
    });

    expect($onHandAfter)->toBe('0.000');   // stock-in removed → on-hand restored
    expect(Purchase::on('pgsql_migrate')->whereNotNull('archived_at')->count())->toBe(1);   // purchase archived
    expect(StockMovement::on('pgsql_migrate')->whereNotNull('purchase_id')->count())->toBe(0); // movement gone
});
