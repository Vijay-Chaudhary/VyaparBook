<?php
// tests/Unit/StockServiceTest.php

use App\Models\Business;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Support\Str;

function stockMaterial(Business $business, ?string $reorder = '10.000'): RawMaterial
{
    return RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'Besan',
        'unit' => 'kg',
        'reorder_level' => $reorder,
    ]);
}

function stockMovement(RawMaterial $m, User $u, string $kind, string $qty, string $date = '2026-07-10'): StockMovement
{
    $movement = new StockMovement([
        'business_id' => $m->business_id,
        'uuid' => (string) Str::uuid(),
        'raw_material_id' => $m->id,
        'movement_date' => $date,
        'kind' => $kind,
        'qty' => $qty, // caller passes the already-signed effect
    ]);
    $movement->setConnection('pgsql_migrate');
    $movement->created_by = $u->id;
    $movement->save();

    return $movement;
}

it('reports zero on hand for a material with no movements', function () {
    $business = Business::factory()->create();
    $material = stockMaterial($business);

    expect((new StockService())->onHandFor($material))->toBe('0.000');
});

it('sums signed movements exactly for on hand', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $material = stockMaterial($business);

    stockMovement($material, $user, 'in', '100.000');
    stockMovement($material, $user, 'out', '-30.500');
    stockMovement($material, $user, 'adjust', '2.250');

    // 100 - 30.5 + 2.25 = 71.75
    expect((new StockService())->onHandFor($material))->toBe('71.750');
});

it('allows on hand to go negative when over-consumed', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $material = stockMaterial($business);

    stockMovement($material, $user, 'in', '10.000');
    stockMovement($material, $user, 'out', '-25.000');

    expect((new StockService())->onHandFor($material))->toBe('-15.000');
});

it('flags below reorder only once on hand drops under the level', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $material = stockMaterial($business, '10.000');
    $service = new StockService();

    stockMovement($material, $user, 'in', '10.000');
    expect($service->belowReorder($material->fresh()))->toBeFalse(); // exactly at level

    stockMovement($material, $user, 'out', '-0.001');
    expect($service->belowReorder($material->fresh()))->toBeTrue(); // now under
});

it('never flags below reorder when no reorder level is set', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $material = stockMaterial($business, null);

    stockMovement($material, $user, 'out', '-500.000'); // deeply negative

    expect((new StockService())->belowReorder($material))->toBeFalse();
});

it('builds a ledger whose final running on hand equals on hand', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $material = stockMaterial($business);
    $service = new StockService();

    stockMovement($material, $user, 'in', '100.000', '2026-07-10');
    stockMovement($material, $user, 'out', '-40.000', '2026-07-12');
    stockMovement($material, $user, 'in', '15.000', '2026-07-15');

    $ledger = $service->ledgerFor($material);

    expect($ledger)->toHaveCount(3);
    expect($ledger->pluck('running_on_hand')->all())->toBe(['100.000', '60.000', '75.000']);
    expect($ledger->last()['running_on_hand'])->toBe($service->onHandFor($material));
});
