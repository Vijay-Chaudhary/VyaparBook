<?php
// tests/Feature/Catalog/CatalogCrudTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\Product;
use App\Models\User;
use App\Services\TokenService;

function ownerToken(Business $business): string
{
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    return (new TokenService())->issue($user, $membership);
}

it('creates a product stamped with the caller tenant', function () {
    $business = Business::factory()->create();
    $token = ownerToken($business);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/products', [
            'name_hi' => 'सेव',
            'name_en' => 'Sev',
            'base_cost_per_kg' => '120.00',
        ])
        ->assertStatus(201)
        ->assertJson(['name_hi' => 'सेव', 'name_en' => 'Sev']);

    $created = Product::find($response->json('id'));
    expect($created->business_id)->toBe($business->id);
});

it('updates a product and bumps its version', function () {
    $business = Business::factory()->create();
    $token = ownerToken($business);
    $product = Product::create([
        'business_id' => $business->id, 'name_hi' => 'सेव',
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}", ['name_en' => 'Sev Special'])
        ->assertOk()
        ->assertJson(['name_en' => 'Sev Special']);

    expect(Product::find($product->id)->version)->toBe(2);
});

it('rejects a product with no hindi name', function () {
    $business = Business::factory()->create();
    $token = ownerToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/products', ['name_en' => 'Sev'])
        ->assertStatus(422);
});

it('returns 404 for a product in another business', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    $token = ownerToken($mine);

    $foreign = Product::create([
        'business_id' => $theirs->id, 'name_hi' => 'हल्दी',
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$foreign->id}", ['name_en' => 'Haldi'])
        ->assertStatus(404);
});
