<?php
// tests/Feature/Sync/SyncPushTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function syncSetup(string $role = 'owner'): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => $role,
    ]);
    $token = (new TokenService())->issue($user, $membership);

    $customer = Customer::create([
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
    expect(Payment::where('business_id', $business->id)->count())->toBe(1);
});

it('reports a replayed mutation as duplicate and creates nothing', function () {
    [$business, $token, $customer] = syncSetup();
    $mutation = paymentMutation($business->id, $customer);

    push($token, [$mutation])->assertOk();
    $second = push($token, [$mutation])->assertOk();

    expect($second->json('results.0.status'))->toBe('duplicate');
    expect(Payment::where('business_id', $business->id)->count())->toBe(1);
});

it('rejects a mutation whose tenant_id is not the session tenant and writes nothing', function () {
    [$business, $token, $customer] = syncSetup();
    $otherTenant = (string) Str::uuid();

    $response = push($token, [paymentMutation($otherTenant, $customer)])->assertOk();

    expect($response->json('results.0.status'))->toBe('rejected');
    expect($response->json('results.0.reason'))->toBe('tenant_mismatch');
    expect(Payment::where('business_id', $business->id)->count())->toBe(0);
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
    $foreign = Customer::create([
        'business_id' => $theirs->id, 'uuid' => (string) Str::uuid(), 'name' => 'Theirs',
    ]);

    $response = push($token, [paymentMutation($business->id, $foreign)])->assertOk();

    expect($response->json('results.0.status'))->toBe('rejected');
    expect($response->json('results.0.reason'))->toBe('not_found');
});

it('rejects only the bad mutation and still applies the rest of the batch', function () {
    // The below-cost rate used to be this test's trigger. The floor no longer
    // refuses anything, so an unknown pack drives it instead — the guarantee
    // being protected was never about pricing: one bad mutation must be
    // REPORTED and roll back alone, inside its own savepoint, while its
    // neighbours in the same batch still apply.
    [$business, $token, $customer] = syncSetup();

    $product = App\Models\Product::create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
    ]);
    $size = App\Models\PackSize::create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $pack = App\Models\ProductPack::create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $size->id, 'default_sell_price' => '90.00',
        'default_cost_price' => '70.00',
    ]);

    $good = (string) Str::uuid();
    $bad = (string) Str::uuid();

    $saleMutation = fn (string $uuid, string $packId) => [
        'type' => 'sale', 'tenant_id' => $business->id, 'uuid' => $uuid,
        'payload' => [
            'uuid' => $uuid, 'customer_id' => $customer->id, 'sale_date' => '2026-07-20',
            'lines' => [['product_pack_id' => $packId, 'qty' => 1, 'rate' => '80.00']],
        ],
    ];

    $response = push($token, [
        $saleMutation($good, $pack->id),
        $saleMutation($bad, (string) Str::uuid()),
    ])->assertOk();

    // The promise is that the bad mutation is REPORTED, not merely absent: a
    // results array that mislabelled it would still leave the DB looking right.
    $byUuid = collect($response->json('results'))->keyBy('uuid');
    expect($byUuid[$good]['status'])->toBe('applied');
    expect($byUuid[$bad]['status'])->toBe('rejected');
    expect($byUuid[$bad]['reason'])->toBe('not_found');

    // The legitimate sale survives its neighbour's rejection.
    expect(DB::table('sales')
        ->where('business_id', $business->id)->where('uuid', $good)->count())->toBe(1);
    expect(DB::table('sales')
        ->where('business_id', $business->id)->where('uuid', $bad)->count())->toBe(0);
});

it('applies a below-cost sale from the field instead of parking it', function () {
    // A phone that confirmed a below-cost line must not have its sale parked on
    // arrival — that would strand the salesman's work after the shop was told.
    [$business, $token, $customer] = syncSetup();

    $product = App\Models\Product::create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
    ]);
    $size = App\Models\PackSize::create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $pack = App\Models\ProductPack::create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $size->id, 'default_sell_price' => '90.00',
        'default_cost_price' => '70.00',
    ]);

    $uuid = (string) Str::uuid();

    $response = push($token, [[
        'type' => 'sale', 'tenant_id' => $business->id, 'uuid' => $uuid,
        'payload' => [
            'uuid' => $uuid, 'customer_id' => $customer->id, 'sale_date' => '2026-07-20',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '10.00']],
        ],
    ]])->assertOk();

    expect($response->json('results.0.status'))->toBe('applied');
    expect((string) DB::table('sale_lines')
        ->where('business_id', $business->id)->value('cost_at_sale'))->toBe('70.00');
});
