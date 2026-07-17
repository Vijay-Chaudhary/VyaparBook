<?php
// tests/Unit/KhataServiceTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Services\KhataService;
use Illuminate\Support\Str;

function khataCustomer(Business $business, string $opening = '0.00'): Customer
{
    return Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'Ram Traders',
        'opening_balance' => $opening,
    ]);
}

function khataSale(Customer $c, User $u, string $total, string $date = '2026-07-10', ?string $reverses = null): Sale
{
    $sale = new Sale([
        'business_id' => $c->business_id,
        'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id,
        'sale_date' => $date,
        'reverses_id' => $reverses,
    ]);
    $sale->setConnection('pgsql_migrate');
    $sale->created_by = $u->id;
    $sale->total = $total;
    $sale->save();

    return $sale;
}

function khataPayment(Customer $c, User $u, string $amount, string $date = '2026-07-12', ?string $reverses = null): Payment
{
    $payment = new Payment([
        'business_id' => $c->business_id,
        'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id,
        'payment_date' => $date,
        'amount' => $amount,
        'mode' => 'cash',
        'reverses_id' => $reverses,
    ]);
    $payment->setConnection('pgsql_migrate');
    $payment->created_by = $u->id;
    $payment->save();

    return $payment;
}

it('returns the opening balance verbatim when there is no activity', function () {
    $business = Business::factory()->create();
    $customer = khataCustomer($business, '250.00');

    expect((new KhataService())->outstandingFor($customer))->toBe('250.00');
});

it('computes outstanding as opening + sales - payments, exactly', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $customer = khataCustomer($business, '100.00');

    khataSale($customer, $user, '270.00');
    khataSale($customer, $user, '58.50');
    khataPayment($customer, $user, '200.00');

    // 100 + 270 + 58.50 - 200 = 228.50
    expect((new KhataService())->outstandingFor($customer))->toBe('228.50');
});

it('leaves outstanding unchanged after a sale and its reversal net out', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $customer = khataCustomer($business, '0.00');

    $sale = khataSale($customer, $user, '180.00');
    khataSale($customer, $user, '-180.00', '2026-07-11', $sale->id); // void

    expect((new KhataService())->outstandingFor($customer))->toBe('0.00');
});

it('builds a time-ordered ledger whose final running balance equals outstanding', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $customer = khataCustomer($business, '100.00');

    khataSale($customer, $user, '270.00', '2026-07-10');
    khataPayment($customer, $user, '200.00', '2026-07-12');
    khataSale($customer, $user, '58.50', '2026-07-15');

    $service = new KhataService();
    $ledger = $service->ledgerFor($customer);

    expect($ledger)->toHaveCount(3);
    // running balance: 100 +270 =370, -200 =170, +58.50 =228.50
    expect($ledger->pluck('running_balance')->all())->toBe(['370.00', '170.00', '228.50']);
    expect($ledger->last()['running_balance'])->toBe($service->outstandingFor($customer));
});

it('tags a reversal entry distinctly in the ledger', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $customer = khataCustomer($business, '0.00');

    $sale = khataSale($customer, $user, '180.00', '2026-07-10');
    khataSale($customer, $user, '-180.00', '2026-07-11', $sale->id);

    $kinds = (new KhataService())->ledgerFor($customer)->pluck('kind')->all();
    expect($kinds)->toBe(['sale', 'sale_reversal']);
});

it('snapshots a product pack rate as the sale-time price', function () {
    $business = Business::factory()->create();
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
        'default_sell_price' => '90.00',
    ]);

    expect((new KhataService())->snapshotRate($pack))->toBe('90.00');
});
