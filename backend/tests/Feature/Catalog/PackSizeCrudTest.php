<?php
// tests/Feature/Catalog/PackSizeCrudTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\User;
use App\Services\TokenService;

function packOwnerToken(Business $business): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    return (new TokenService())->issue($user, $membership);
}

it('creates a pack size', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . packOwnerToken($business))
        ->postJson('/api/v1/pack-sizes', ['label' => '500g', 'weight_kg' => '0.500'])
        ->assertStatus(201)
        ->assertJson(['label' => '500g', 'in_dropdown' => true]);
});

it('rejects a duplicate label in the same business', function () {
    $business = Business::factory()->create();
    PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);

    $this->withHeader('Authorization', 'Bearer ' . packOwnerToken($business))
        ->postJson('/api/v1/pack-sizes', ['label' => '500g', 'weight_kg' => '0.500'])
        ->assertStatus(422)
        ->assertJsonPath('errors.label.0', 'That pack size already exists. If it is archived, restore it instead.');
});

it('allows the same label in a different business', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    PackSize::on('pgsql_migrate')->create([
        'business_id' => $theirs->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);

    // The unique rule is tenant-scoped by RLS alone — another business holding
    // this label must not block us.
    $this->withHeader('Authorization', 'Bearer ' . packOwnerToken($mine))
        ->postJson('/api/v1/pack-sizes', ['label' => '500g', 'weight_kg' => '0.500'])
        ->assertStatus(201);
});

it('rejects a zero or negative weight', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . packOwnerToken($business))
        ->postJson('/api/v1/pack-sizes', ['label' => '0g', 'weight_kg' => '0'])
        ->assertStatus(422);
});
