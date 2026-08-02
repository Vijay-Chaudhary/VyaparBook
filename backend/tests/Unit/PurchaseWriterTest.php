<?php
// tests/Unit/PurchaseWriterTest.php
//
// pwInTenant/pwSupplier/pwMaterial come from tests/Pest.php, so this file and
// PurchaseAggregationTest.php can each be run on their own.

use App\Models\Business;
use App\Models\Purchase;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\PurchaseWriter;
use App\Services\StockService;
use Illuminate\Support\Str;

it('records a purchase with a linked costed stock-in, and is idempotent', function () {
    $a = tenantBusiness();
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
        return [reread($p), (new StockService())->onHandFor(RawMaterial::find($m->id))];
    });

    expect($purchase->total)->toBe('400.00');   // 10 × 40
    expect($onHand)->toBe('10.000');            // one costed stock-in
    expect(Purchase::where('business_id', $a->id)->count())->toBe(1);
    expect(StockMovement::where('purchase_id', $purchase->id)->where('kind', 'in')->count())->toBe(1);
});

it('reverses the stock-in when a purchase is removed', function () {
    $a = tenantBusiness();
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
    expect(Purchase::whereNotNull('archived_at')->count())->toBe(1);   // purchase archived
    expect(StockMovement::whereNotNull('purchase_id')->count())->toBe(0); // movement gone
});
