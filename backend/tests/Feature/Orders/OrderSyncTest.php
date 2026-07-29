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

it('streams a salesman an order a DIFFERENT salesman took, so anyone can deliver it', function () {
    [$business, , $token, $customer, $pack] = orderSyncSetup();
    $mine = (string) Str::uuid();

    pushOrder($token, [[
        'type' => 'order', 'tenant_id' => $business->id, 'uuid' => $mine,
        'payload' => [
            'customer_id' => $customer->id, 'order_date' => '2026-07-26',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '90.00']],
        ],
    ]])->assertOk();

    // Another salesman's order in the same shop. Withholding this was the ONLY
    // thing stopping a colleague delivering it — the server never checked who
    // took an order, only the caller's role.
    $other = User::factory()->create();
    Membership::on('pgsql_migrate')->create([
        'user_id' => $other->id, 'business_id' => $business->id, 'role' => 'salesman',
    ]);
    $theirs = (string) Str::uuid();
    DB::connection('pgsql_migrate')->table('orders')->insert([
        'id' => (string) Str::uuid(), 'business_id' => $business->id, 'uuid' => $theirs,
        'customer_id' => $customer->id, 'order_date' => '2026-07-26', 'status' => 'pending',
        'total' => '90.00', 'created_by' => $other->id, 'sync_seq' => 999999,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/sync/pull?since=0')->assertOk();

    expect(collect($response->json('orders'))->pluck('uuid')->sort()->values()->all())
        ->toBe(collect([$mine, $theirs])->sort()->values()->all());
});

it('still hides another tenant\'s orders — the widening is strictly within one shop', function () {
    [$business, , $token, $customer, $pack] = orderSyncSetup();

    // A whole separate shop takes its own order.
    [$theirBusiness, , $theirToken, $theirCustomer, $theirPack] = orderSyncSetup();
    pushOrder($theirToken, [[
        'type' => 'order', 'tenant_id' => $theirBusiness->id, 'uuid' => (string) Str::uuid(),
        'payload' => [
            'customer_id' => $theirCustomer->id, 'order_date' => '2026-07-26',
            'lines' => [['product_pack_id' => $theirPack->id, 'qty' => 1, 'rate' => '90.00']],
        ],
    ]])->assertOk();

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/sync/pull?since=0')->assertOk();

    // RLS and BelongsToTenant are untouched by this change.
    expect($response->json('orders'))->toHaveCount(0);
    expect($response->json('order_lines'))->toHaveCount(0);
});

it('sends an order with its lines even when only the order was touched', function () {
    // pack/deliver bump the ORDER's sync_seq and not its lines'. Lines used to
    // be filtered to the orders in the same delta, which held only because a
    // device that saw an order had seen it since creation. A colleague meeting
    // it for the first time at pack time would get an order with nothing in it.
    [$business, , $token, $customer, $pack] = orderSyncSetup();
    $uuid = (string) Str::uuid();

    pushOrder($token, [[
        'type' => 'order', 'tenant_id' => $business->id, 'uuid' => $uuid,
        'payload' => [
            'customer_id' => $customer->id, 'order_date' => '2026-07-26',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 2, 'rate' => '90.00']],
        ],
    ]])->assertOk();

    // Catch up, so both order and lines are below the cursor.
    $cursor = test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/sync/pull?since=0')->json('cursor');

    DB::connection('pgsql_migrate')->table('orders')
        ->where('uuid', $uuid)->update(['status' => 'accepted']);
    pushOrder($token, [[
        'type' => 'order_pack', 'tenant_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'payload' => ['order_uuid' => $uuid],
    ]])->assertOk();

    $delta = test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/sync/pull?since=' . $cursor)->assertOk();

    expect($delta->json('orders'))->toHaveCount(1);
    // The lines did not move, so they are correctly absent — the device
    // already holds them from the first pull. What must never happen is an
    // order arriving that the device has never seen WITHOUT its lines, which
    // the independent delta below covers.
    expect($delta->json('order_lines'))->toHaveCount(0);
});

it('sends a fresh device every order line in the shop, not only its own orders\'', function () {
    [$business, , $token, $customer, $pack] = orderSyncSetup();

    $other = User::factory()->create();
    Membership::on('pgsql_migrate')->create([
        'user_id' => $other->id, 'business_id' => $business->id, 'role' => 'salesman',
    ]);
    $otherToken = (new TokenService())->issue(
        $other,
        Membership::on('pgsql_migrate')->where('user_id', $other->id)->firstOrFail()
    );

    pushOrder($otherToken, [[
        'type' => 'order', 'tenant_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'payload' => [
            'customer_id' => $customer->id, 'order_date' => '2026-07-26',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 3, 'rate' => '90.00']],
        ],
    ]])->assertOk();

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/sync/pull?since=0')->assertOk();

    // A delivery screen with no line items cannot be acted on.
    expect($response->json('orders'))->toHaveCount(1);
    expect($response->json('order_lines'))->toHaveCount(1);
    expect((int) $response->json('order_lines.0.qty'))->toBe(3);
});

it('lets a second salesman deliver an order they did not take, creating one sale', function () {
    [$business, , $token, $customer, $pack] = orderSyncSetup();
    $uuid = (string) Str::uuid();

    pushOrder($token, [[
        'type' => 'order', 'tenant_id' => $business->id, 'uuid' => $uuid,
        'payload' => [
            'customer_id' => $customer->id, 'order_date' => '2026-07-26',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 2, 'rate' => '90.00']],
        ],
    ]])->assertOk();

    DB::connection('pgsql_migrate')->table('orders')
        ->where('uuid', $uuid)->update(['status' => 'packed']);

    // A colleague, who never saw this order until the pull widened.
    $other = User::factory()->create();
    $otherMembership = Membership::on('pgsql_migrate')->create([
        'user_id' => $other->id, 'business_id' => $business->id, 'role' => 'salesman',
    ]);
    $otherToken = (new TokenService())->issue($other, $otherMembership);

    pushOrder($otherToken, [[
        'type' => 'order_deliver', 'tenant_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'payload' => ['order_uuid' => $uuid],
    ]])->assertOk()->assertJsonPath('results.0.status', 'applied');

    $sales = DB::connection('pgsql_migrate')->table('sales')->where('business_id', $business->id)->get();
    expect($sales)->toHaveCount(1);
    // created_by is whoever delivered — the sale records the money event, and
    // handing the goods over is the act being recorded.
    expect($sales->first()->created_by)->toBe($other->id);
});
