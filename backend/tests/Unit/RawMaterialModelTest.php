<?php
// tests/Unit/RawMaterialModelTest.php

use App\Models\Business;
use App\Models\RawMaterial;
use Illuminate\Support\Str;

it('generates a uuid primary key and stamps a positive sync_seq', function () {
    $business = Business::factory()->create();

    $material = RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'Besan',
        'unit' => 'kg',
        'reorder_level' => '25.000',
    ]);

    expect($material->id)->toBeString();
    expect(strlen($material->id))->toBe(36);
    expect($material->fresh()->version)->toBe(1);
    expect($material->fresh()->sync_seq)->toBeInt()->toBeGreaterThan(0);
});

it('casts reorder_level to a 3-decimal string', function () {
    $business = Business::factory()->create();

    $material = RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'Oil',
        'unit' => 'litre',
        'reorder_level' => '5.5',
    ]);

    expect($material->fresh()->reorder_level)->toBe('5.500');
});

it('rejects a duplicate uuid within the same business', function () {
    $business = Business::factory()->create();
    $uuid = (string) Str::uuid();

    RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => $uuid, 'name' => 'Salt', 'unit' => 'kg',
    ]);

    expect(fn () => RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => $uuid, 'name' => 'Salt Again', 'unit' => 'kg',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
