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
use Illuminate\Support\Facades\DB;
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

it('honours a negotiated rate from the client', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 2, 'rate' => '80.00'],
    ])->assertCreated();

    $line = DB::connection('pgsql_migrate')->table('sale_lines')
        ->where('business_id', $business->id)->sole();

    expect((string) $line->rate)->toBe('80.00');
    expect((string) $line->line_total)->toBe('160.00');
    // Server-authored, and it records what list WAS that day.
    expect((string) $line->list_rate)->toBe('90.00');
});

it('falls back to the default when no rate is sent, so older clients still work', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 1],
    ])->assertCreated();

    $line = DB::connection('pgsql_migrate')->table('sale_lines')
        ->where('business_id', $business->id)->sole();

    expect((string) $line->rate)->toBe('90.00');
    expect((string) $line->list_rate)->toBe('90.00');
});

it('ignores a client-sent list_rate, so a discount cannot be faked', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '80.00', 'list_rate' => '80.00'],
    ])->assertCreated();

    $line = DB::connection('pgsql_migrate')->table('sale_lines')
        ->where('business_id', $business->id)->sole();

    expect((string) $line->list_rate)->toBe('90.00');   // the server's, not the client's
});

it('refuses a rate below the pack cost floor', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');
    DB::connection('pgsql_migrate')->table('product_packs')
        ->where('id', $pack->id)->update(['default_cost_price' => '70.00']);

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '69.99'],
    ])
        ->assertStatus(422)
        // The test product only has name_hi set (saleSetup does not set name_en),
        // so the fallback chain in LedgerWriter lands on the Hindi name — this
        // also incidentally proves the `sales.rate_below_floor` key resolves and
        // both placeholders are filled, not just that some 422 came back.
        ->assertJsonPath('errors.lines.0', 'Rate for सेव cannot be below 70.00.');

    expect(DB::connection('pgsql_migrate')->table('sale_lines')
        ->where('business_id', $business->id)->count())->toBe(0);
});

it('allows a rate exactly at the floor', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');
    DB::connection('pgsql_migrate')->table('product_packs')
        ->where('id', $pack->id)->update(['default_cost_price' => '70.00']);

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '70.00'],
    ])->assertCreated();
});

it('allows a rate above list — negotiating upward is legitimate', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '120.00'],
    ])->assertCreated();

    $line = DB::connection('pgsql_migrate')->table('sale_lines')
        ->where('business_id', $business->id)->sole();

    expect((string) $line->rate)->toBe('120.00');
    expect((string) $line->list_rate)->toBe('90.00');
});

it('voids a sale by copying both rates unchanged and negating only qty and total', function () {
    // owner, not salesman: voiding is an owner/admin-only act (KhataPolicy::voidSale) —
    // this test is about list_rate surviving a void, not about who may void.
    [$business, $token, $customer, $pack] = saleSetup('owner', '90.00');

    $created = postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 2, 'rate' => '80.00'],
    ])->assertCreated();

    test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/sales/'.$created->json('id').'/void')
        ->assertCreated();

    $reversal = DB::connection('pgsql_migrate')->table('sale_lines')
        ->where('business_id', $business->id)->where('qty', -2)->sole();

    // The price is mirrored, not re-derived: today's default may have moved.
    expect((string) $reversal->rate)->toBe('80.00');
    expect((string) $reversal->list_rate)->toBe('90.00');
    expect((string) $reversal->line_total)->toBe('-160.00');
});

it('rejects a negative rate — a return is a negative qty, not a negative price', function () {
    [, $token, $customer, $pack] = saleSetup('salesman', '90.00');

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '-5.00'],
    ])->assertStatus(422);
});

it('applies the floor to a return line too, independent of the qty sign', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');
    DB::connection('pgsql_migrate')->table('product_packs')
        ->where('id', $pack->id)->update(['default_cost_price' => '70.00']);

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => -1, 'rate' => '60.00'],
    ])->assertStatus(422);
});

it('rolls back the whole sale when only a later line breaks the floor', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');
    DB::connection('pgsql_migrate')->table('product_packs')
        ->where('id', $pack->id)->update(['default_cost_price' => '70.00']);

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '90.00'],   // valid on its own
        ['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '60.00'],  // below the floor
    ])->assertStatus(422);

    // The transaction boundary in LedgerWriter::createSale must undo the first
    // line too — a partial write would leave a sale with only its valid line.
    expect(DB::connection('pgsql_migrate')->table('sales')
        ->where('business_id', $business->id)->count())->toBe(0);
    expect(DB::connection('pgsql_migrate')->table('sale_lines')
        ->where('business_id', $business->id)->count())->toBe(0);
});
