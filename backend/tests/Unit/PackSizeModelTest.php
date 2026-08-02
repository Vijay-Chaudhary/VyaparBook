<?php
// tests/Unit/PackSizeModelTest.php

use App\Models\Business;
use App\Models\PackSize;

it('stores 100g as exactly 0.100 kg', function () {
    $business = tenantBusiness();

    $pack = PackSize::create([
        'business_id' => $business->id,
        'label' => '100g',
        'weight_kg' => '0.100',
    ]);

    expect(reread($pack)->weight_kg)->toBe('0.100');
});

it('defaults in_dropdown to true', function () {
    $business = tenantBusiness();

    $pack = PackSize::create([
        'business_id' => $business->id,
        'label' => '500g',
        'weight_kg' => '0.500',
    ]);

    expect(reread($pack)->in_dropdown)->toBeTrue();
});

it('rejects a duplicate label within the same business', function () {
    $business = tenantBusiness();

    PackSize::create([
        'business_id' => $business->id,
        'label' => '500g',
        'weight_kg' => '0.500',
    ]);

    expect(fn () => PackSize::create([
        'business_id' => $business->id,
        'label' => '500g',
        'weight_kg' => '0.500',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('allows the same label in a different business', function () {
    $a = Business::factory()->create();
    $b = Business::factory()->create();

    // Each write runs as its own shop, so the uniqueness this asserts is the
    // per-business one and not an artefact of a single bound tenant.
    asTenant($a->id, fn () => PackSize::create([
        'business_id' => $a->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]));
    $second = asTenant($b->id, fn () => PackSize::create([
        'business_id' => $b->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]));

    expect($second->id)->toBeString();
});
