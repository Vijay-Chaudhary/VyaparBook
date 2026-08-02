<?php
// tests/Unit/TenantImporterMaterialsTest.php

use App\Import\TenantImporter;
use App\Models\Business;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;

/**
 * Read the imported material back under its own tenant.
 *
 * asTenant, not withoutGlobalScopes(): the importer writes for one business, so
 * binding it keeps the scope live and a row landing in the wrong tenant would
 * still fail here rather than be read back regardless.
 */
$materialFor = fn (Business $b, string $name) => asTenant(
    $b->id,
    fn () => RawMaterial::where('name', $name)->first(),
);

/** Count the movements the importer wrote, under the same tenant, for the same reason. */
$movementsFor = fn (Business $b, callable $filter) => asTenant(
    $b->id,
    fn () => $filter(StockMovement::query())->count(),
);

it('imports a raw material and its opening stock as one in-movement', function () use ($materialFor, $movementsFor) {
    $business = Business::factory()->create();
    $owner = User::factory()->create();

    $report = (new TenantImporter())->importRawMaterials($business->id, [
        ['name' => 'Besan', 'unit' => 'kg', 'reorder_level' => '10', 'opening_stock' => '100.000'],
    ], false, $owner->id);

    expect($report->created)->toBe(1);

    $material = $materialFor($business, 'Besan');
    expect($material)->not->toBeNull();
    expect(asTenant($business->id, fn () => (new StockService())->onHandFor($material)))->toBe('100.000');
    expect($movementsFor($business, fn ($q) => $q
        ->where('raw_material_id', $material->id)->where('kind', 'in')))->toBe(1);
});

it('does not double the opening stock on a plain re-run', function () use ($materialFor, $movementsFor) {
    $business = Business::factory()->create();
    $owner = User::factory()->create();
    $importer = new TenantImporter();
    $rows = fn () => [['name' => 'Besan', 'unit' => 'kg', 'reorder_level' => '10', 'opening_stock' => '100.000']];

    $importer->importRawMaterials($business->id, $rows(), false, $owner->id);
    $report = $importer->importRawMaterials($business->id, $rows(), false, $owner->id);

    expect($report->created)->toBe(0);
    expect($report->updated)->toBe(1);

    $material = $materialFor($business, 'Besan');
    expect(asTenant($business->id, fn () => (new StockService())->onHandFor($material)))->toBe('100.000');
    expect($movementsFor($business, fn ($q) => $q
        ->where('raw_material_id', $material->id)))->toBe(1);
});

it('corrects the single opening movement when re-imported with a new figure', function () use ($materialFor, $movementsFor) {
    $business = Business::factory()->create();
    $owner = User::factory()->create();
    $importer = new TenantImporter();

    $importer->importRawMaterials($business->id, [
        ['name' => 'Besan', 'unit' => 'kg', 'reorder_level' => '10', 'opening_stock' => '100.000'],
    ], false, $owner->id);
    $importer->importRawMaterials($business->id, [
        ['name' => 'Besan', 'unit' => 'kg', 'reorder_level' => '10', 'opening_stock' => '80.000'],
    ], false, $owner->id);

    $material = $materialFor($business, 'Besan');
    expect(asTenant($business->id, fn () => (new StockService())->onHandFor($material)))->toBe('80.000');
    expect($movementsFor($business, fn ($q) => $q
        ->where('raw_material_id', $material->id)))->toBe(1);
});

it('creates no movement for a material without opening stock', function () use ($materialFor, $movementsFor) {
    $business = Business::factory()->create();
    $owner = User::factory()->create();

    (new TenantImporter())->importRawMaterials($business->id, [
        ['name' => 'Salt', 'unit' => 'kg', 'reorder_level' => '', 'opening_stock' => ''],
    ], false, $owner->id);

    $material = $materialFor($business, 'Salt');
    expect(asTenant($business->id, fn () => (new StockService())->onHandFor($material)))->toBe('0.000');
    expect($movementsFor($business, fn ($q) => $q
        ->where('raw_material_id', $material->id)))->toBe(0);
});

it('skips and reports a material with an invalid unit', function () use ($materialFor) {
    $business = Business::factory()->create();
    $owner = User::factory()->create();

    $report = (new TenantImporter())->importRawMaterials($business->id, [
        ['name' => 'Mystery', 'unit' => 'furlong', 'reorder_level' => '', 'opening_stock' => ''],
    ], false, $owner->id);

    expect($report->created)->toBe(0);
    expect($report->skipped)->toBe(1);
    expect($report->errors[0]['message'])->toContain('unit must be one of');
    expect($materialFor($business, 'Mystery'))->toBeNull();
});
