<?php
// tests/Feature/Khata/CustomerCrudTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Str;

function customerToken(Business $business, string $role = 'owner'): string
{
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => $role,
    ]);

    return (new TokenService())->issue($user, $membership);
}

it('creates a customer stamped with the caller tenant', function () {
    $business = Business::factory()->create();
    $token = customerToken($business);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customers', [
            'name' => 'Ram Traders',
            'village' => 'Bagru',
            'opening_balance' => '250.00',
        ])
        ->assertStatus(201)
        ->assertJson(['name' => 'Ram Traders', 'opening_balance' => '250.00']);

    $created = Customer::find($response->json('id'));
    expect($created->business_id)->toBe($business->id);
});

it('lets a salesman create a customer but blocks an accountant', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . customerToken($business, 'salesman'))
        ->postJson('/api/v1/customers', ['name' => 'Shyam Stores'])
        ->assertStatus(201);

    $this->withHeader('Authorization', 'Bearer ' . customerToken($business, 'accountant'))
        ->postJson('/api/v1/customers', ['name' => 'Mohan Kirana'])
        ->assertStatus(403);
});

it('replays the same row when the same uuid is posted twice', function () {
    $business = Business::factory()->create();
    $token = customerToken($business);
    $uuid = (string) Str::uuid();

    $first = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customers', ['uuid' => $uuid, 'name' => 'Ram Traders'])
        ->assertStatus(201);

    $second = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customers', ['uuid' => $uuid, 'name' => 'Ram Traders'])
        ->assertStatus(200); // replay, not a new create

    expect($second->json('id'))->toBe($first->json('id'));
    expect(Customer::where('business_id', $business->id)->count())->toBe(1);
});

it('rejects a customer with no name', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . customerToken($business))
        ->postJson('/api/v1/customers', ['village' => 'Bagru'])
        ->assertStatus(422);
});

it('updates a customer and bumps its version', function () {
    $business = Business::factory()->create();
    $token = customerToken($business);
    $customer = Customer::create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(), 'name' => 'Ram',
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/customers/{$customer->id}", ['name' => 'Ram Traders'])
        ->assertOk()
        ->assertJson(['name' => 'Ram Traders']);

    expect(Customer::find($customer->id)->version)->toBe(2);
});

it('returns 404 for a customer in another business', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    $token = customerToken($mine);

    $foreign = Customer::create([
        'business_id' => $theirs->id, 'uuid' => (string) Str::uuid(), 'name' => 'Theirs',
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/customers/{$foreign->id}", ['name' => 'Stolen'])
        ->assertStatus(404);
});
