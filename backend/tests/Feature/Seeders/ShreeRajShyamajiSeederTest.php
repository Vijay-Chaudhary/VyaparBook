<?php
// tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\Purchase;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\StockService;
use Database\Seeders\ShreeRajShyamajiSeeder;

/** The seeder writes on pgsql_migrate, so assertions read from there too. */
function srsBusiness(): Business
{
    return Business::on('pgsql_migrate')->where('name', 'Shree Raj Shyama Ji Namkeen')->firstOrFail();
}

function srsCount(string $class): int
{
    return $class::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count();
}

beforeEach(function () {
    $this->seed(ShreeRajShyamajiSeeder::class);
});

it('seeds the masters onto one business', function () {
    expect(srsCount(Customer::class))->toBe(40)
        ->and(srsCount(Supplier::class))->toBe(6)
        ->and(srsCount(RawMaterial::class))->toBe(16)
        ->and(srsCount(Product::class))->toBe(3)
        ->and(srsCount(PackSize::class))->toBe(17)
        ->and(srsCount(ProductPack::class))->toBe(21);
});

it('keeps same-named customers in different villages apart', function () {
    $rows = Customer::on('pgsql_migrate')
        ->where('business_id', srsBusiness()->id)
        ->where('name', 'Santosh Singh')
        ->orderBy('village')
        ->pluck('village')
        ->all();

    expect($rows)->toBe(['Aziz', 'Harpur']);
});

it('records oil in the unit it is bought in', function () {
    $oil = RawMaterial::on('pgsql_migrate')
        ->where('business_id', srsBusiness()->id)
        ->where('name', 'Refined Oil')
        ->firstOrFail();

    expect($oil->unit)->toBe('tina');
});

it('seeds every purchase with the total the invoices add up to', function () {
    $rows = Purchase::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get();

    expect($rows)->toHaveCount(23);

    $total = $rows->reduce(fn (string $c, $p) => bcadd($c, (string) $p->total, 2), '0.00');
    expect($total)->toBe('342305.00');
});

it('raises stock for every purchase, so on-hand is not zero', function () {
    // on-hand is a sum over stock_movements, not a column: a Purchase row alone
    // moves nothing. PurchaseWriter pairs each with a positive `in` movement.
    $ins = StockMovement::on('pgsql_migrate')
        ->where('business_id', srsBusiness()->id)
        ->whereNotNull('purchase_id')
        ->get();

    expect($ins)->toHaveCount(23);
    expect($ins->every(fn ($m) => bccomp((string) $m->qty, '0', 3) > 0))->toBeTrue();
});
