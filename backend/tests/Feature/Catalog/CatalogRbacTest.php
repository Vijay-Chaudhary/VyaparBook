<?php
// tests/Feature/Catalog/CatalogRbacTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;

function tokenForRole(Business $business, string $role): string
{
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => $role,
    ]);

    return (new TokenService())->issue($user, $membership);
}

it('lets an owner create a product', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . tokenForRole($business, 'owner'))
        ->postJson('/api/v1/products', ['name_hi' => 'सेव'])
        ->assertStatus(201);
});

it('lets an admin create a product', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . tokenForRole($business, 'admin'))
        ->postJson('/api/v1/products', ['name_hi' => 'सेव'])
        ->assertStatus(201);
});

it('blocks a salesman from creating a product', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . tokenForRole($business, 'salesman'))
        ->postJson('/api/v1/products', ['name_hi' => 'सेव'])
        ->assertStatus(403);
});

it('blocks an accountant from creating a product', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . tokenForRole($business, 'accountant'))
        ->postJson('/api/v1/products', ['name_hi' => 'सेव'])
        ->assertStatus(403);
});
