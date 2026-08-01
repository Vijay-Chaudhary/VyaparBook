<?php
// tests/Feature/Catalog/CatalogTemplateTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\Product;
use App\Models\User;
use App\Services\TokenService;

function seedToken(Business $business, string $role = 'owner'): string
{
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => $role,
    ]);

    return (new TokenService())->issue($user, $membership);
}

it('seeds the namkeen template for the caller business', function () {
    $business = Business::factory()->create();
    $token = seedToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/catalog/seed', ['template' => 'namkeen'])
        ->assertStatus(201);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonCount(3, 'products')
        ->assertJsonCount(8, 'pack_sizes');
});

it('leaves seeded rows freely editable by the tenant', function () {
    $business = Business::factory()->create();
    $token = seedToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/catalog/seed', ['template' => 'namkeen']);

    $product = Product::where('business_id', $business->id)->first();

    // A seeded row is an ordinary tenant row — PRD §6's "every tenant edits freely".
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}", ['name_en' => 'My Own Name'])
        ->assertOk()
        ->assertJson(['name_en' => 'My Own Name']);
});

it('refuses to seed a catalog that already has products', function () {
    $business = Business::factory()->create();
    $token = seedToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/catalog/seed', ['template' => 'namkeen'])
        ->assertStatus(201);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/catalog/seed', ['template' => 'sweets'])
        ->assertStatus(409);
});

it('accepts blank as a no-op', function () {
    $business = Business::factory()->create();
    $token = seedToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/catalog/seed', ['template' => 'blank'])
        ->assertStatus(201);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertJsonCount(0, 'products');
});

it('rejects an unknown template name', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . seedToken($business))
        ->postJson('/api/v1/catalog/seed', ['template' => 'nonexistent'])
        ->assertStatus(422);
});

it('blocks a salesman from seeding', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . seedToken($business, 'salesman'))
        ->postJson('/api/v1/catalog/seed', ['template' => 'namkeen'])
        ->assertStatus(403);
});

it('seeds only the caller business, never a neighbour', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . seedToken($mine))
        ->postJson('/api/v1/catalog/seed', ['template' => 'namkeen'])
        ->assertStatus(201);

    expect(Product::where('business_id', $theirs->id)->count())->toBe(0);
});
