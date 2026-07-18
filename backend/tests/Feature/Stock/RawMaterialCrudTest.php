<?php
// tests/Feature/Stock/RawMaterialCrudTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\RawMaterial;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Str;

function materialToken(Business $business, string $role = 'owner'): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => $role,
    ]);

    return (new TokenService())->issue($user, $membership);
}

it('creates a raw material stamped with the caller tenant', function () {
    $business = Business::factory()->create();
    $token = materialToken($business);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/raw-materials', [
            'name' => 'Besan',
            'unit' => 'kg',
            'reorder_level' => '25.000',
        ])
        ->assertStatus(201)
        ->assertJson(['name' => 'Besan', 'unit' => 'kg', 'reorder_level' => '25.000']);

    $created = RawMaterial::on('pgsql_migrate')->find($response->json('id'));
    expect($created->business_id)->toBe($business->id);
});

it('blocks a salesman and an accountant from managing raw materials', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . materialToken($business, 'salesman'))
        ->postJson('/api/v1/raw-materials', ['name' => 'Oil', 'unit' => 'litre'])
        ->assertStatus(403);

    $this->withHeader('Authorization', 'Bearer ' . materialToken($business, 'accountant'))
        ->postJson('/api/v1/raw-materials', ['name' => 'Salt', 'unit' => 'kg'])
        ->assertStatus(403);
});

it('lets an admin manage raw materials', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . materialToken($business, 'admin'))
        ->postJson('/api/v1/raw-materials', ['name' => 'Chilli', 'unit' => 'kg'])
        ->assertStatus(201);
});

it('replays the same row when the same uuid is posted twice', function () {
    $business = Business::factory()->create();
    $token = materialToken($business);
    $uuid = (string) Str::uuid();

    $first = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/raw-materials', ['uuid' => $uuid, 'name' => 'Besan', 'unit' => 'kg'])
        ->assertStatus(201);

    $second = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/raw-materials', ['uuid' => $uuid, 'name' => 'Besan', 'unit' => 'kg'])
        ->assertStatus(200); // replay, not a new create

    expect($second->json('id'))->toBe($first->json('id'));
    expect(RawMaterial::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(1);
});

it('rejects a material with no name or a bad unit', function () {
    $business = Business::factory()->create();
    $token = materialToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/raw-materials', ['unit' => 'kg'])
        ->assertStatus(422);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/raw-materials', ['name' => 'Besan', 'unit' => 'furlong'])
        ->assertStatus(422);
});

it('updates a material and bumps its version', function () {
    $business = Business::factory()->create();
    $token = materialToken($business);
    $material = RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Besan', 'unit' => 'kg',
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/raw-materials/{$material->id}", ['reorder_level' => '50.000'])
        ->assertOk()
        ->assertJson(['reorder_level' => '50.000']);

    expect(RawMaterial::on('pgsql_migrate')->find($material->id)->version)->toBe(2);
});

it('archives and restores a material', function () {
    $business = Business::factory()->create();
    $token = materialToken($business);
    $material = RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Besan', 'unit' => 'kg',
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/raw-materials/{$material->id}")
        ->assertOk();
    expect(RawMaterial::on('pgsql_migrate')->find($material->id)->archived_at)->not->toBeNull();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/raw-materials/{$material->id}/restore")
        ->assertOk();
    expect(RawMaterial::on('pgsql_migrate')->find($material->id)->archived_at)->toBeNull();
});

it('returns 404 for a material in another business', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    $token = materialToken($mine);

    $foreign = RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $theirs->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Theirs', 'unit' => 'kg',
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/raw-materials/{$foreign->id}", ['name' => 'Stolen'])
        ->assertStatus(404);
});
