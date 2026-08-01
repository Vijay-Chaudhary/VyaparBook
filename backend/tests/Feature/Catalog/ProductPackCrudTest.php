<?php
// tests/Feature/Catalog/ProductPackCrudTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\User;
use App\Services\TokenService;

function ppOwnerToken(Business $business): string
{
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    return (new TokenService())->issue($user, $membership);
}

it('fills the cost price from the per-kg base cost when omitted', function () {
    $business = Business::factory()->create();
    $product = Product::create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'base_cost_per_kg' => '120.00',
    ]);
    $pack = PackSize::create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);

    $this->withHeader('Authorization', 'Bearer ' . ppOwnerToken($business))
        ->postJson('/api/v1/product-packs', [
            'product_id' => $product->id,
            'pack_size_id' => $pack->id,
            'default_sell_price' => '80.00',
        ])
        ->assertStatus(201)
        ->assertJson(['default_cost_price' => '60.00']);
});

it('keeps an explicit cost price instead of the suggestion', function () {
    $business = Business::factory()->create();
    $product = Product::create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'base_cost_per_kg' => '120.00',
    ]);
    $pack = PackSize::create([
        'business_id' => $business->id, 'label' => '100g', 'weight_kg' => '0.100',
    ]);

    $this->withHeader('Authorization', 'Bearer ' . ppOwnerToken($business))
        ->postJson('/api/v1/product-packs', [
            'product_id' => $product->id,
            'pack_size_id' => $pack->id,
            'default_sell_price' => '20.00',
            'default_cost_price' => '15.00', // packaging costs more per kg on a small pouch
        ])
        ->assertStatus(201)
        ->assertJson(['default_cost_price' => '15.00']);
});

it('refuses to pair a product with another business pack size', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    $product = Product::create([
        'business_id' => $mine->id, 'name_hi' => 'सेव',
    ]);
    $foreignPack = PackSize::create([
        'business_id' => $theirs->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);

    $this->withHeader('Authorization', 'Bearer ' . ppOwnerToken($mine))
        ->postJson('/api/v1/product-packs', [
            'product_id' => $product->id,
            'pack_size_id' => $foreignPack->id,
            'default_sell_price' => '80.00',
        ])
        ->assertStatus(422);
});
