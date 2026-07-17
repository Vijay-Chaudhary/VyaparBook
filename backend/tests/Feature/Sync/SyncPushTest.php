<?php
// tests/Feature/Sync/SyncPushTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Str;

function syncSetup(string $role = 'owner'): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => $role,
    ]);
    $token = (new TokenService())->issue($user, $membership);

    $customer = Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Ram Traders', 'opening_balance' => '0.00',
    ]);

    return [$business, $token, $customer];
}

function paymentMutation(string $tenantId, Customer $customer, ?string $uuid = null): array
{
    return [
        'type' => 'payment',
        'tenant_id' => $tenantId,
        'uuid' => $uuid ?? (string) Str::uuid(),
        'payload' => [
            'customer_id' => $customer->id,
            'payment_date' => '2026-07-17',
            'amount' => '200.00',
            'mode' => 'cash',
        ],
    ];
}

function push(string $token, array $mutations)
{
    return test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/sync/push', ['mutations' => $mutations]);
}

it('applies a fresh mutation and returns the server id', function () {
    [$business, $token, $customer] = syncSetup();

    $response = push($token, [paymentMutation($business->id, $customer)])
        ->assertOk();

    expect($response->json('results.0.status'))->toBe('applied');
    expect($response->json('results.0.id'))->not->toBeNull();
    expect(Payment::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(1);
});

it('reports a replayed mutation as duplicate and creates nothing', function () {
    [$business, $token, $customer] = syncSetup();
    $mutation = paymentMutation($business->id, $customer);

    push($token, [$mutation])->assertOk();
    $second = push($token, [$mutation])->assertOk();

    expect($second->json('results.0.status'))->toBe('duplicate');
    expect(Payment::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(1);
});

it('rejects a mutation whose tenant_id is not the session tenant and writes nothing', function () {
    [$business, $token, $customer] = syncSetup();
    $otherTenant = (string) Str::uuid();

    $response = push($token, [paymentMutation($otherTenant, $customer)])->assertOk();

    expect($response->json('results.0.status'))->toBe('rejected');
    expect($response->json('results.0.reason'))->toBe('tenant_mismatch');
    expect(Payment::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(0);
});

it('applies allowed items and rejects forbidden ones in the same batch', function () {
    // An accountant may push payments but not sales (PRD §7). The batch mixes both;
    // the payment applies, the sale is rejected forbidden — one bad item is not fatal.
    [$business, $token, $customer] = syncSetup('accountant');

    $saleMutation = [
        'type' => 'sale',
        'tenant_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'payload' => ['customer_id' => $customer->id, 'sale_date' => '2026-07-17', 'lines' => [['product_pack_id' => (string) Str::uuid(), 'qty' => 1]]],
    ];

    $response = push($token, [paymentMutation($business->id, $customer), $saleMutation])->assertOk();

    $byUuid = collect($response->json('results'))->keyBy('uuid');
    expect($byUuid->count())->toBe(2);
    $statuses = collect($response->json('results'))->pluck('status')->sort()->values()->all();
    expect($statuses)->toBe(['applied', 'rejected']);
});

it('rejects a mutation referencing a customer the caller cannot see', function () {
    [$business, $token, $customer] = syncSetup();
    $theirs = Business::factory()->create();
    $foreign = Customer::on('pgsql_migrate')->create([
        'business_id' => $theirs->id, 'uuid' => (string) Str::uuid(), 'name' => 'Theirs',
    ]);

    $response = push($token, [paymentMutation($business->id, $foreign)])->assertOk();

    expect($response->json('results.0.status'))->toBe('rejected');
    expect($response->json('results.0.reason'))->toBe('not_found');
});
