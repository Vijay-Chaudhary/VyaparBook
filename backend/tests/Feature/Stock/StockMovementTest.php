<?php
// tests/Feature/Stock/StockMovementTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use App\Services\TokenService;
use Illuminate\Support\Str;

function movementToken(Business $business, string $role = 'owner'): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => $role,
    ]);

    return (new TokenService())->issue($user, $membership);
}

function movementMaterial(Business $business): RawMaterial
{
    return RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Besan', 'unit' => 'kg', 'reorder_level' => '10.000',
    ]);
}

it('records an in movement that raises on hand', function () {
    $business = Business::factory()->create();
    $token = movementToken($business);
    $material = movementMaterial($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(),
            'raw_material_id' => $material->id,
            'movement_date' => '2026-07-10',
            'kind' => 'in',
            'qty' => '100.000',
        ])
        ->assertStatus(201)
        ->assertJson(['kind' => 'in', 'qty' => '100.000']); // stored positive

    expect((new StockService())->onHandFor($material))->toBe('100.000');
});

it('stores an out movement as a negative that lowers on hand', function () {
    $business = Business::factory()->create();
    $token = movementToken($business);
    $material = movementMaterial($business);

    // seed some stock directly (created_by is not fillable — set it explicitly)
    $seed = new StockMovement([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'raw_material_id' => $material->id, 'movement_date' => '2026-07-09',
        'kind' => 'in', 'qty' => '100.000',
    ]);
    $seed->setConnection('pgsql_migrate');
    $seed->created_by = User::factory()->create()->id;
    $seed->save();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(),
            'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11',
            'kind' => 'out',
            'qty' => '30.000', // positive magnitude in
        ])
        ->assertStatus(201);

    expect($response->json('qty'))->toBe('-30.000'); // sign derived from kind
    expect((new StockService())->onHandFor($material))->toBe('70.000');
});

it('applies a signed adjust delta as given', function () {
    $business = Business::factory()->create();
    $token = movementToken($business);
    $material = movementMaterial($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(),
            'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11',
            'kind' => 'adjust',
            'qty' => '-2.500', // a recount correction, negative
        ])
        ->assertStatus(201)
        ->assertJson(['qty' => '-2.500']);

    expect((new StockService())->onHandFor($material))->toBe('-2.500');
});

it('allows on hand to go negative through over-consumption', function () {
    $business = Business::factory()->create();
    $token = movementToken($business);
    $material = movementMaterial($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(), 'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11', 'kind' => 'out', 'qty' => '5.000',
        ])
        ->assertStatus(201);

    expect((new StockService())->onHandFor($material))->toBe('-5.000');
});

it('replays the same movement on a repeated uuid', function () {
    $business = Business::factory()->create();
    $token = movementToken($business);
    $material = movementMaterial($business);
    $uuid = (string) Str::uuid();

    $first = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => $uuid, 'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11', 'kind' => 'in', 'qty' => '40.000',
        ])->assertStatus(201);

    $second = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => $uuid, 'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11', 'kind' => 'in', 'qty' => '40.000',
        ])->assertStatus(200); // replay

    expect($second->json('id'))->toBe($first->json('id'));
    expect((new StockService())->onHandFor($material))->toBe('40.000'); // not doubled
});

it('rejects an out movement with a non-positive qty', function () {
    $business = Business::factory()->create();
    $token = movementToken($business);
    $material = movementMaterial($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(), 'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11', 'kind' => 'out', 'qty' => '-5.000',
        ])
        ->assertStatus(422);
});

it('rejects a zero qty for any kind', function () {
    $business = Business::factory()->create();
    $token = movementToken($business);
    $material = movementMaterial($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(), 'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11', 'kind' => 'adjust', 'qty' => '0',
        ])
        ->assertStatus(422);
});

it('returns 404 recording a movement for another business material', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    $token = movementToken($mine);
    $foreign = movementMaterial($theirs);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(), 'raw_material_id' => $foreign->id,
            'movement_date' => '2026-07-11', 'kind' => 'in', 'qty' => '10.000',
        ])
        ->assertStatus(404);
});

it('blocks a salesman from recording a movement', function () {
    $business = Business::factory()->create();
    $material = movementMaterial($business);

    $this->withHeader('Authorization', 'Bearer ' . movementToken($business, 'salesman'))
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(), 'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11', 'kind' => 'in', 'qty' => '10.000',
        ])
        ->assertStatus(403);
});
