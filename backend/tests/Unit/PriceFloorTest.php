<?php
// tests/Unit/PriceFloorTest.php

use App\Models\Business;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Pricing\PriceFloor;

/**
 * THE SHARED CASE TABLE. resources/js/offline/pricing.test.js asserts the same
 * cases against the JS implementation. A case added here must be added there —
 * the two files are one contract, and drift between them is the failure mode
 * this design accepts in exchange for enforcing the floor offline.
 */
function floorPack(?string $cost, ?string $perKg, string $weight = '0.500'): ProductPack
{
    $business = Business::factory()->create();

    $product = Product::create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
    ]);
    $product->base_cost_per_kg = $perKg;
    $product->save();

    $size = PackSize::create([
        'business_id' => $business->id, 'label' => 'P', 'weight_kg' => $weight,
    ]);

    $pack = ProductPack::create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $size->id, 'default_sell_price' => '100.00',
        'default_cost_price' => $cost,
    ]);

    return $pack->load(['product', 'packSize']);
}

it('uses the pack cost price when it is set', function () {
    expect(PriceFloor::for(floorPack('92.00', '180.00')))->toBe('92.00');
});

it('derives the floor from cost-per-kg and pack weight when no cost price is set', function () {
    // 180.00 per kg x 0.500 kg = 90.00
    expect(PriceFloor::for(floorPack(null, '180.00', '0.500')))->toBe('90.00');
});

it('rounds a derived floor UP to the paisa, so it never sits below true cost', function () {
    // 181.00 x 0.333 = 60.273 -> 60.28
    expect(PriceFloor::for(floorPack(null, '181.00', '0.333')))->toBe('60.28');
});

it('does not round up a derived floor that is already exact', function () {
    // 180.00 x 0.333 = 59.940
    expect(PriceFloor::for(floorPack(null, '180.00', '0.333')))->toBe('59.94');
});

it('treats a zero cost price as a real floor of zero, not as missing', function () {
    expect(PriceFloor::for(floorPack('0.00', '180.00')))->toBe('0.00');
});

it('returns null when there is neither a cost price nor a cost per kg', function () {
    expect(PriceFloor::for(floorPack(null, null)))->toBeNull();
});
