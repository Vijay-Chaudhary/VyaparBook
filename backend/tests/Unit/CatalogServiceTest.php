<?php
// tests/Unit/CatalogServiceTest.php

use App\Models\PackSize;
use App\Models\Product;
use App\Services\CatalogService;

function makeProduct(?string $costPerKg): Product
{
    $product = new Product();
    $product->base_cost_per_kg = $costPerKg;

    return $product;
}

function makePack(string $weightKg): PackSize
{
    $pack = new PackSize();
    $pack->weight_kg = $weightKg;

    return $pack;
}

it('suggests cost proportional to pack weight', function () {
    $suggested = (new CatalogService())->suggestedCostPrice(
        makeProduct('120.00'),
        makePack('0.500')
    );

    expect($suggested)->toBe('60.00');
});

it('handles a 100g pack without float drift', function () {
    $suggested = (new CatalogService())->suggestedCostPrice(
        makeProduct('120.00'),
        makePack('0.100')
    );

    expect($suggested)->toBe('12.00');
});

it('truncates rather than rounds a fractional paisa', function () {
    // 133.33 × 0.100 = 13.333 → 13.33. This is a suggestion the tenant can
    // overwrite, so truncation is acceptable and — unlike rounding up — can
    // never overstate cost.
    $suggested = (new CatalogService())->suggestedCostPrice(
        makeProduct('133.33'),
        makePack('0.100')
    );

    expect($suggested)->toBe('13.33');
});

it('returns null when the product has no base cost', function () {
    $suggested = (new CatalogService())->suggestedCostPrice(
        makeProduct(null),
        makePack('0.500')
    );

    expect($suggested)->toBeNull();
});
