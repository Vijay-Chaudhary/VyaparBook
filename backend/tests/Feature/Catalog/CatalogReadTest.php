<?php
// tests/Feature/Catalog/CatalogReadTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use App\Services\TokenService;

function readToken(Business $business, string $role = 'owner'): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => $role,
    ]);

    return (new TokenService())->issue($user, $membership);
}

function seedOneProductPack(Business $business, array $packAttrs = []): array
{
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
        'base_cost_per_kg' => '120.00',
    ]);
    $packSize = PackSize::on('pgsql_migrate')->create(array_merge([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ], $packAttrs));
    $productPack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'product_id' => $product->id,
        'pack_size_id' => $packSize->id,
        'default_sell_price' => '80.00',
        'default_cost_price' => '60.00',
    ]);

    return [$product, $packSize, $productPack];
}

it('returns products with their packs and prices nested', function () {
    $business = Business::factory()->create();
    seedOneProductPack($business);

    $this->withHeader('Authorization', 'Bearer ' . readToken($business))
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonPath('products.0.name_hi', 'सेव')
        ->assertJsonPath('products.0.packs.0.label', '500g')
        ->assertJsonPath('products.0.packs.0.default_sell_price', '80.00')
        ->assertJsonPath('pack_sizes.0.label', '500g');
});

it('lets a salesman read the catalog', function () {
    $business = Business::factory()->create();
    seedOneProductPack($business);

    $this->withHeader('Authorization', 'Bearer ' . readToken($business, 'salesman'))
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonPath('products.0.name_hi', 'सेव');
});

it('includes pack sizes that are hidden from the dropdown', function () {
    $business = Business::factory()->create();
    seedOneProductPack($business, ['in_dropdown' => false]);

    $this->withHeader('Authorization', 'Bearer ' . readToken($business))
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonPath('pack_sizes.0.in_dropdown', false)
        ->assertJsonCount(1, 'pack_sizes');
});

it('never returns another business catalog', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    seedOneProductPack($theirs);

    $this->withHeader('Authorization', 'Bearer ' . readToken($mine))
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonCount(0, 'products')
        ->assertJsonCount(0, 'pack_sizes');
});
