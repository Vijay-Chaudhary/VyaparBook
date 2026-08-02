<?php
// tests/Unit/RawMaterialModelTest.php

use App\Models\Business;
use App\Models\RawMaterial;
use Illuminate\Support\Str;

it('generates a uuid primary key and stamps a positive sync_seq', function () {
    $business = tenantBusiness();

    $material = RawMaterial::create([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'Besan',
        'unit' => 'kg',
        'reorder_level' => '25.000',
    ]);

    expect($material->id)->toBeString();
    expect(strlen($material->id))->toBe(36);
    expect(reread($material)->version)->toBe(1);
    expect(reread($material)->sync_seq)->toBeInt()->toBeGreaterThan(0);
});

it('casts reorder_level to a 3-decimal string', function () {
    $business = tenantBusiness();

    $material = RawMaterial::create([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'Oil',
        'unit' => 'litre',
        'reorder_level' => '5.5',
    ]);

    expect(reread($material)->reorder_level)->toBe('5.500');
});

it('rejects a duplicate uuid within the same business', function () {
    $business = tenantBusiness();
    $uuid = (string) Str::uuid();

    RawMaterial::create([
        'business_id' => $business->id, 'uuid' => $uuid, 'name' => 'Salt', 'unit' => 'kg',
    ]);

    expect(fn () => RawMaterial::create([
        'business_id' => $business->id, 'uuid' => $uuid, 'name' => 'Salt Again', 'unit' => 'kg',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
