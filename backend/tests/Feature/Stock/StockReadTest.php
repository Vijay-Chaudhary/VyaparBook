<?php
// tests/Feature/Stock/StockReadTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Str;

function stockReadToken(Business $business, string $role = 'owner'): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => $role,
    ]);

    return (new TokenService())->issue($user, $membership);
}

function stockReadMaterial(Business $business, string $reorder = '10.000'): RawMaterial
{
    return RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Besan', 'unit' => 'kg', 'reorder_level' => $reorder,
    ]);
}

function stockReadMovement(RawMaterial $m, User $u, string $kind, string $qty, string $date = '2026-07-10'): void
{
    $movement = new StockMovement([
        'business_id' => $m->business_id, 'uuid' => (string) Str::uuid(),
        'raw_material_id' => $m->id, 'movement_date' => $date, 'kind' => $kind, 'qty' => $qty,
    ]);
    $movement->setConnection('pgsql_migrate');
    $movement->created_by = $u->id;
    $movement->save();
}

it('lists materials with on hand and a below-reorder flag', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $token = stockReadToken($business);

    $besan = stockReadMaterial($business, '10.000');
    stockReadMovement($besan, $user, 'in', '100.000');
    stockReadMovement($besan, $user, 'out', '-95.000'); // on hand 5 < 10

    $oil = stockReadMaterial($business, '10.000');
    $oil->name = 'Oil';
    $oil->save();
    stockReadMovement($oil, $user, 'in', '50.000'); // on hand 50 >= 10

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/stock')
        ->assertOk();

    $materials = collect($response->json('materials'))->keyBy('name');
    expect($materials['Besan']['on_hand'])->toBe('5.000');
    expect($materials['Besan']['below_reorder'])->toBeTrue();
    expect($materials['Oil']['on_hand'])->toBe('50.000');
    expect($materials['Oil']['below_reorder'])->toBeFalse();
});

it('shows a material ledger with a running on hand ending at on hand', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $token = stockReadToken($business);
    $material = stockReadMaterial($business);

    stockReadMovement($material, $user, 'in', '100.000', '2026-07-10');
    stockReadMovement($material, $user, 'out', '-40.000', '2026-07-12');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/stock/{$material->id}")
        ->assertOk()
        ->assertJson(['on_hand' => '60.000']);

    $running = collect($response->json('ledger'))->pluck('running_on_hand')->all();
    expect($running)->toBe(['100.000', '60.000']);
});

it('blocks a salesman and an accountant from reading stock', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . stockReadToken($business, 'salesman'))
        ->getJson('/api/v1/stock')->assertStatus(403);

    $this->withHeader('Authorization', 'Bearer ' . stockReadToken($business, 'accountant'))
        ->getJson('/api/v1/stock')->assertStatus(403);
});

it('returns 404 showing a material in another business', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    $token = stockReadToken($mine);
    $foreign = stockReadMaterial($theirs);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/stock/{$foreign->id}")
        ->assertStatus(404);
});
