<?php
// tests/Unit/SaleModelTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\User;
use Illuminate\Support\Str;

/** Build a product_pack on the migrate connection (bypasses RLS) for one business. */
function packFor(Business $business, string $sellPrice = '90.00'): ProductPack
{
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'base_cost_per_kg' => '120.00',
    ]);
    $packSize = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => Str::random(6), 'weight_kg' => '0.500',
    ]);

    return ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'product_id' => $product->id,
        'pack_size_id' => $packSize->id,
        'default_sell_price' => $sellPrice,
    ]);
}

/** A customer on the migrate connection (bypasses RLS) for one business. */
function customerFor(Business $business): Customer
{
    return Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'Ram Traders',
    ]);
}

function saleWithLines(Business $business, Customer $customer, User $user, array $lines): Sale
{
    // Compute the total up front so the sale is saved exactly once — a second
    // save would bump HasVersion to 2 on a brand-new row. The controller mirrors
    // this single-write pattern.
    $total = '0.00';
    foreach ($lines as [$pack, $qty, $rate]) {
        $total = bcadd($total, bcmul((string) $rate, (string) $qty, 2), 2);
    }

    $sale = new Sale([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'sale_date' => '2026-07-17',
    ]);
    $sale->setConnection('pgsql_migrate');
    $sale->created_by = $user->id;
    $sale->total = $total;
    $sale->save();

    foreach ($lines as [$pack, $qty, $rate]) {
        $line = new SaleLine([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'product_pack_id' => $pack->id,
            'qty' => $qty,
            'rate' => $rate,
        ]);
        $line->setConnection('pgsql_migrate');
        $line->line_total = bcmul((string) $rate, (string) $qty, 2);
        $line->save();
    }

    return $sale;
}

it('relates a sale to its lines and stamps created_by, version and sync_seq', function () {
    $business = Business::factory()->create();
    $customer = customerFor($business);
    $user = User::factory()->create();
    $pack = packFor($business);

    $sale = saleWithLines($business, $customer, $user, [[$pack, 3, '90.00']]);

    $fresh = Sale::on('pgsql_migrate')->with('lines')->find($sale->id);
    expect($fresh->lines)->toHaveCount(1);
    expect($fresh->total)->toBe('270.00');
    expect($fresh->created_by)->toBe($user->id);
    expect($fresh->version)->toBe(1);
    expect($fresh->sync_seq)->toBeGreaterThan(0);
});

it('freezes the line rate as a snapshot, independent of the pack price later', function () {
    $business = Business::factory()->create();
    $customer = customerFor($business);
    $user = User::factory()->create();
    $pack = packFor($business, '90.00');

    $sale = saleWithLines($business, $customer, $user, [[$pack, 1, '90.00']]);

    // The catalog re-prices the pack afterwards.
    $pack->update(['default_sell_price' => '999.00']);

    $line = SaleLine::on('pgsql_migrate')->where('sale_id', $sale->id)->first();
    expect($line->rate)->toBe('90.00');       // still the sale-time price
    expect($line->line_total)->toBe('90.00');
});

it('resolves a reversal back to the original it voids', function () {
    $business = Business::factory()->create();
    $customer = customerFor($business);
    $user = User::factory()->create();
    $pack = packFor($business);

    $original = saleWithLines($business, $customer, $user, [[$pack, 2, '90.00']]);

    $reversal = new Sale([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'sale_date' => '2026-07-18',
        'reverses_id' => $original->id,
    ]);
    $reversal->setConnection('pgsql_migrate');
    $reversal->created_by = $user->id;
    $reversal->total = '-180.00';
    $reversal->save();

    $fresh = Sale::on('pgsql_migrate')->with('reverses')->find($reversal->id);
    expect($fresh->reverses->id)->toBe($original->id);
    expect($fresh->total)->toBe('-180.00');
});

it('sets the non-fillable created_by and total via the factory afterMaking hook', function () {
    // make(), not create(): sales is RLS-protected and this unit test holds no
    // tenant context. Scalar overrides avoid the nested Business/Customer
    // factories, so the only thing under test is the afterMaking hook that fills
    // the two columns mass assignment would otherwise drop.
    $sale = Sale::factory()->make([
        'business_id' => (string) Str::uuid(),
        'customer_id' => (string) Str::uuid(),
    ]);

    expect($sale->created_by)->not->toBeNull();
    expect($sale->total)->toBe('0.00');
});
