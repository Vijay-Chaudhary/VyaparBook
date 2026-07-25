<?php
// tests/Feature/Orders/OrderSyncTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @return array{0: Business, 1: User, 2: string, 3: Customer, 4: ProductPack} */
function orderSyncSetup(string $role = 'salesman'): array
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
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
    ]);
    $size = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $pack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $size->id, 'default_sell_price' => '90.00',
    ]);

    return [$business, $user, $token, $customer, $pack];
}

function pushOrder(string $token, array $mutations)
{
    return test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/sync/push', ['mutations' => $mutations]);
}

it('accepts an order mutation and creates a pending order', function () {
    [$business, , $token, $customer, $pack] = orderSyncSetup();
    $uuid = (string) Str::uuid();

    pushOrder($token, [[
        'type' => 'order', 'tenant_id' => $business->id, 'uuid' => $uuid,
        'payload' => [
            'customer_id' => $customer->id, 'order_date' => '2026-07-26',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 2, 'rate' => '85.00']],
        ],
    ]])->assertOk()->assertJsonPath('results.0.status', 'applied');

    $order = DB::connection('pgsql_migrate')->table('orders')->where('business_id', $business->id)->sole();
    expect($order->status)->toBe('pending');
    expect((string) $order->total)->toBe('170.00');
});

it('walks an order through pack and deliver from the field', function () {
    [$business, $user, $token, $customer, $pack] = orderSyncSetup();
    $uuid = (string) Str::uuid();

    pushOrder($token, [[
        'type' => 'order', 'tenant_id' => $business->id, 'uuid' => $uuid,
        'payload' => [
            'customer_id' => $customer->id, 'order_date' => '2026-07-26',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '90.00']],
        ],
    ]])->assertOk();

    // Acceptance is the online step; do it directly, as the Blade screen would.
    DB::connection('pgsql_migrate')->table('orders')->where('uuid', $uuid)
        ->update(['status' => 'accepted', 'accepted_by' => $user->id, 'accepted_at' => now()]);

    pushOrder($token, [[
        'type' => 'order_pack', 'tenant_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'payload' => ['order_uuid' => $uuid],
    ]])->assertOk()->assertJsonPath('results.0.status', 'applied');

    pushOrder($token, [[
        'type' => 'order_deliver', 'tenant_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'payload' => ['order_uuid' => $uuid],
    ]])->assertOk()->assertJsonPath('results.0.status', 'applied');

    expect(DB::connection('pgsql_migrate')->table('orders')->where('uuid', $uuid)->value('status'))
        ->toBe('delivered');
    expect(DB::connection('pgsql_migrate')->table('sales')->where('business_id', $business->id)->count())
        ->toBe(1);
});

it('parks a deliver for a rejected order without killing the batch', function () {
    [$business, , $token, $customer, $pack] = orderSyncSetup();
    $bad = (string) Str::uuid();
    $good = (string) Str::uuid();

    foreach ([$bad, $good] as $uuid) {
        pushOrder($token, [[
            'type' => 'order', 'tenant_id' => $business->id, 'uuid' => $uuid,
            'payload' => [
                'customer_id' => $customer->id, 'order_date' => '2026-07-26',
                'lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '90.00']],
            ],
        ]])->assertOk();
    }

    // The owner rejected one while the phone was offline; the other is fine.
    DB::connection('pgsql_migrate')->table('orders')->where('uuid', $bad)->update(['status' => 'rejected']);
    DB::connection('pgsql_migrate')->table('orders')->where('uuid', $good)->update(['status' => 'packed']);

    $response = pushOrder($token, [
        ['type' => 'order_deliver', 'tenant_id' => $business->id, 'uuid' => (string) Str::uuid(),
         'payload' => ['order_uuid' => $bad]],
        ['type' => 'order_deliver', 'tenant_id' => $business->id, 'uuid' => (string) Str::uuid(),
         'payload' => ['order_uuid' => $good]],
    ])->assertOk();

    expect($response->json('results.0.status'))->toBe('rejected');
    expect($response->json('results.0.reason'))->toBe('invalid');
    expect($response->json('results.1.status'))->toBe('applied');

    // Exactly one sale: the rejected order made none.
    expect(DB::connection('pgsql_migrate')->table('sales')->where('business_id', $business->id)->count())
        ->toBe(1);
});

it('forbids an accountant from taking an order, as it forbids them a sale', function () {
    [$business, , $token, $customer, $pack] = orderSyncSetup('accountant');

    pushOrder($token, [[
        'type' => 'order', 'tenant_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'payload' => [
            'customer_id' => $customer->id, 'order_date' => '2026-07-26',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '90.00']],
        ],
    ]])->assertOk()->assertJsonPath('results.0.reason', 'forbidden');

    expect(DB::connection('pgsql_migrate')->table('orders')->where('business_id', $business->id)->count())
        ->toBe(0);
});

it('streams a salesman only the orders they took', function () {
    [$business, , $token, $customer, $pack] = orderSyncSetup();
    $mine = (string) Str::uuid();

    pushOrder($token, [[
        'type' => 'order', 'tenant_id' => $business->id, 'uuid' => $mine,
        'payload' => [
            'customer_id' => $customer->id, 'order_date' => '2026-07-26',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '90.00']],
        ],
    ]])->assertOk();

    // Another salesman's order in the same shop.
    $other = User::factory()->create();
    Membership::on('pgsql_migrate')->create([
        'user_id' => $other->id, 'business_id' => $business->id, 'role' => 'salesman',
    ]);
    DB::connection('pgsql_migrate')->table('orders')->insert([
        'id' => (string) Str::uuid(), 'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id, 'order_date' => '2026-07-26', 'status' => 'pending',
        'total' => '90.00', 'created_by' => $other->id, 'sync_seq' => 999999,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/sync/pull?since=0')->assertOk();

    expect($response->json('orders'))->toHaveCount(1);
    expect($response->json('orders.0.uuid'))->toBe($mine);
});
