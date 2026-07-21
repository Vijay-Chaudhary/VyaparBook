<?php
// tests/Unit/ProductPackModelTest.php

use App\Models\Business;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;

it('relates a product to a pack size with its own price', function () {
    $business = Business::factory()->create();
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'base_cost_per_kg' => '120.00',
    ]);
    $pack = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);

    $productPack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'product_id' => $product->id,
        'pack_size_id' => $pack->id,
        'default_sell_price' => '80.00',
        'default_cost_price' => '60.00',
    ]);

    $fresh = ProductPack::on('pgsql_migrate')->with(['product', 'packSize'])->find($productPack->id);

    expect($fresh->product->name_hi)->toBe('सेव');
    expect($fresh->packSize->label)->toBe('500g');
    expect($fresh->default_sell_price)->toBe('80.00');
});

it('rejects the same product/pack pairing twice', function () {
    $business = Business::factory()->create();
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव',
    ]);
    $pack = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);

    $attrs = [
        'business_id' => $business->id,
        'product_id' => $product->id,
        'pack_size_id' => $pack->id,
        'default_sell_price' => '80.00',
    ];

    ProductPack::on('pgsql_migrate')->create($attrs);

    expect(fn () => ProductPack::on('pgsql_migrate')->create($attrs))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
