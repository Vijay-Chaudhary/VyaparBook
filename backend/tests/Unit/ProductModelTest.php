<?php
// tests/Unit/ProductModelTest.php

use App\Models\Business;
use App\Models\Product;

it('generates a uuid primary key and starts at version 1', function () {
    $business = tenantBusiness();

    $product = Product::create([
        'business_id' => $business->id,
        'name_hi' => 'सेव',
        'name_en' => 'Sev',
        'base_cost_per_kg' => '120.00',
    ]);

    expect($product->id)->toBeString();
    expect(strlen($product->id))->toBe(36);
    expect(reread($product)->version)->toBe(1);
});

it('casts money to a 2-decimal string, not a float', function () {
    $business = tenantBusiness();

    $product = Product::create([
        'business_id' => $business->id,
        'name_hi' => 'सेव',
        'base_cost_per_kg' => '120.5',
    ]);

    expect(reread($product)->base_cost_per_kg)->toBe('120.50');
});
