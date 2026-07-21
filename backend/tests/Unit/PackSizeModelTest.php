<?php
// tests/Unit/PackSizeModelTest.php

use App\Models\Business;
use App\Models\PackSize;

it('stores 100g as exactly 0.100 kg', function () {
    $business = Business::factory()->create();

    $pack = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'label' => '100g',
        'weight_kg' => '0.100',
    ]);

    expect($pack->fresh()->weight_kg)->toBe('0.100');
});

it('defaults in_dropdown to true', function () {
    $business = Business::factory()->create();

    $pack = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'label' => '500g',
        'weight_kg' => '0.500',
    ]);

    expect($pack->fresh()->in_dropdown)->toBeTrue();
});

it('rejects a duplicate label within the same business', function () {
    $business = Business::factory()->create();

    PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'label' => '500g',
        'weight_kg' => '0.500',
    ]);

    expect(fn () => PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'label' => '500g',
        'weight_kg' => '0.500',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('allows the same label in a different business', function () {
    $a = Business::factory()->create();
    $b = Business::factory()->create();

    PackSize::on('pgsql_migrate')->create([
        'business_id' => $a->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $second = PackSize::on('pgsql_migrate')->create([
        'business_id' => $b->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);

    expect($second->id)->toBeString();
});
