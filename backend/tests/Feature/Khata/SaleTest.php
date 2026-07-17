<?php
// tests/Feature/Khata/SaleTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\Sale;
use App\Models\User;
use App\Services\KhataService;
use App\Services\TokenService;
use Illuminate\Support\Str;

/**
 * Set up a business with a member of the given role, one customer, and one
 * product pack priced at $sellPrice. Returns [business, token, customer, pack].
 */
function saleSetup(string $role = 'owner', string $sellPrice = '90.00'): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => $role,
    ]);
    $token = (new TokenService())->issue($user, $membership);

    $customer = Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(), 'name' => 'Ram Traders',
    ]);

    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव',
    ]);
    $packSize = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $pack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'product_id' => $product->id,
        'pack_size_id' => $packSize->id,
        'default_sell_price' => $sellPrice,
    ]);

    return [$business, $token, $customer, $pack];
}

function postSale(string $token, Customer $customer, array $lines, ?string $uuid = null)
{
    return test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/sales', [
            'uuid' => $uuid ?? (string) Str::uuid(),
            'customer_id' => $customer->id,
            'sale_date' => '2026-07-17',
            'lines' => $lines,
        ]);
}

it('creates a sale with a snapshot rate, correct total, created_by and version 1', function () {
    [$business, $token, $customer, $pack] = saleSetup(sellPrice: '90.00');

    $response = postSale($token, $customer, [['product_pack_id' => $pack->id, 'qty' => 3]])
        ->assertStatus(201)
        ->assertJson(['total' => '270.00']);

    $sale = Sale::on('pgsql_migrate')->with('lines')->find($response->json('id'));
    expect($sale->business_id)->toBe($business->id);
    expect($sale->created_by)->not->toBeNull();
    expect($sale->version)->toBe(1);
    expect($sale->lines->first()->rate)->toBe('90.00');
});

it('replays the same sale when the same uuid is posted twice', function () {
    [$business, $token, $customer, $pack] = saleSetup();
    $uuid = (string) Str::uuid();

    $first = postSale($token, $customer, [['product_pack_id' => $pack->id, 'qty' => 1]], $uuid)
        ->assertStatus(201);
    $second = postSale($token, $customer, [['product_pack_id' => $pack->id, 'qty' => 1]], $uuid)
        ->assertStatus(200);

    expect($second->json('id'))->toBe($first->json('id'));
    expect(Sale::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(1);
});

it('treats a negative-qty line as a return that lowers the total', function () {
    [$business, $token, $customer, $pack] = saleSetup(sellPrice: '90.00');

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 3],
        ['product_pack_id' => $pack->id, 'qty' => -1],
    ])->assertStatus(201)->assertJson(['total' => '180.00']); // 270 - 90
});

it('voids a sale with an append-only reversal and nets outstanding back', function () {
    [$business, $token, $customer, $pack] = saleSetup(sellPrice: '90.00');

    $sale = postSale($token, $customer, [['product_pack_id' => $pack->id, 'qty' => 2]])
        ->assertStatus(201);

    test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/sales/{$sale->json('id')}/void")
        ->assertStatus(201)
        ->assertJson(['total' => '-180.00', 'reverses_id' => $sale->json('id')]);

    // Original untouched; outstanding back to 0 (opening 0 + 180 - 180).
    $original = Sale::on('pgsql_migrate')->find($sale->json('id'));
    expect($original->reverses_id)->toBeNull();
    $fresh = Customer::on('pgsql_migrate')->find($customer->id);
    expect((new KhataService())->outstandingFor($fresh))->toBe('0.00');
});

it('lets a salesman create a sale but not void one', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman');

    $sale = postSale($token, $customer, [['product_pack_id' => $pack->id, 'qty' => 1]])
        ->assertStatus(201);

    test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/sales/{$sale->json('id')}/void")
        ->assertStatus(403);
});

it('blocks an accountant from creating a sale', function () {
    [$business, $token, $customer, $pack] = saleSetup('accountant');

    postSale($token, $customer, [['product_pack_id' => $pack->id, 'qty' => 1]])
        ->assertStatus(403);
});

it('409s on a double void', function () {
    [$business, $token, $customer, $pack] = saleSetup();

    $sale = postSale($token, $customer, [['product_pack_id' => $pack->id, 'qty' => 1]])
        ->assertStatus(201);

    $voidUrl = "/api/v1/sales/{$sale->json('id')}/void";
    test()->withHeader('Authorization', "Bearer {$token}")->postJson($voidUrl)->assertStatus(201);
    test()->withHeader('Authorization', "Bearer {$token}")->postJson($voidUrl)->assertStatus(409);
});

it('returns 404 posting a sale for another businesses customer', function () {
    [$mine, $token, $mineCustomer, $pack] = saleSetup();
    $theirs = Business::factory()->create();
    $foreignCustomer = Customer::on('pgsql_migrate')->create([
        'business_id' => $theirs->id, 'uuid' => (string) Str::uuid(), 'name' => 'Theirs',
    ]);

    postSale($token, $foreignCustomer, [['product_pack_id' => $pack->id, 'qty' => 1]])
        ->assertStatus(404);
});
