<?php
// tests/Feature/Sync/SyncPullStockTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Str;

function stockPullSetup(string $role = 'owner'): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => $role,
    ]);

    return [$business, $user, (new TokenService())->issue($user, $membership)];
}

function seedPullMaterial(Business $business, string $name = 'Besan'): RawMaterial
{
    return RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => $name, 'unit' => 'kg', 'reorder_level' => '10.000',
    ]);
}

function seedPullMovement(RawMaterial $m, User $u, string $qty = '100.000'): StockMovement
{
    $movement = new StockMovement([
        'business_id' => $m->business_id, 'uuid' => (string) Str::uuid(),
        'raw_material_id' => $m->id, 'movement_date' => '2026-07-10', 'kind' => 'in', 'qty' => $qty,
    ]);
    $movement->setConnection('pgsql_migrate');
    $movement->created_by = $u->id;
    $movement->save();

    return $movement;
}

function stockPull(string $token, int $since = 0)
{
    return test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/sync/pull?since={$since}");
}

it('streams the tenant stock and production rows on an initial pull', function () {
    [$business, $user, $token] = stockPullSetup();
    $material = seedPullMaterial($business);
    seedPullMovement($material, $user);

    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव',
    ]);
    $batch = new ProductionBatch([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'product_id' => $product->id, 'batch_date' => '2026-07-15', 'output_kg' => '30.000',
    ]);
    $batch->setConnection('pgsql_migrate');
    $batch->created_by = $user->id;
    $batch->save();

    stockPull($token, 0)
        ->assertOk()
        ->assertJsonCount(1, 'raw_materials')
        ->assertJsonCount(1, 'stock_movements')
        ->assertJsonCount(1, 'production_batches');
});

it('returns only the stock rows changed since the cursor', function () {
    [$business, $user, $token] = stockPullSetup();
    $material = seedPullMaterial($business);
    seedPullMovement($material, $user);

    $cursor = stockPull($token, 0)->json('cursor');

    $newMovement = seedPullMovement($material, $user, '5.000');

    $response = stockPull($token, $cursor)
        ->assertOk()
        ->assertJsonCount(0, 'raw_materials')
        ->assertJsonCount(1, 'stock_movements');

    expect($response->json('stock_movements.0.id'))->toBe($newMovement->id);
});

it('never streams a neighbour stock rows', function () {
    [$mine, $user, $token] = stockPullSetup();
    $neighbour = Business::factory()->create();
    $neighbourUser = User::factory()->create();
    $foreign = seedPullMaterial($neighbour, 'Theirs');
    seedPullMovement($foreign, $neighbourUser);

    stockPull($token, 0)
        ->assertOk()
        ->assertJsonCount(0, 'raw_materials')
        ->assertJsonCount(0, 'stock_movements');
});

it('withholds stock rows from a salesman pull but still streams khata', function () {
    [$business, $user, $token] = stockPullSetup('salesman');
    $material = seedPullMaterial($business);
    seedPullMovement($material, $user);

    // A salesman may create a customer — that must still stream.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customers', ['name' => 'Ram Traders'])->assertStatus(201);

    stockPull($token, 0)
        ->assertOk()
        ->assertJsonCount(0, 'raw_materials')     // withheld by role
        ->assertJsonCount(0, 'stock_movements')   // withheld by role
        ->assertJsonCount(1, 'customers');        // khata still flows
});
