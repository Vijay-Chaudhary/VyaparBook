<?php
// tests/Unit/CustomerModelTest.php

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Support\Str;

it('generates a uuid primary key and stamps a positive sync_seq', function () {
    $business = Business::factory()->create();

    $customer = Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'Ram Traders',
        'opening_balance' => '250.00',
    ]);

    expect($customer->id)->toBeString();
    expect(strlen($customer->id))->toBe(36);
    expect($customer->fresh()->version)->toBe(1);
    expect($customer->fresh()->sync_seq)->toBeInt()->toBeGreaterThan(0);
});

it('casts opening_balance to a 2-decimal string, not a float', function () {
    $business = Business::factory()->create();

    $customer = Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'Shyam Stores',
        'opening_balance' => '1000.5',
    ]);

    expect($customer->fresh()->opening_balance)->toBe('1000.50');
});

it('defaults opening_balance to 0.00', function () {
    $business = Business::factory()->create();

    $customer = Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'Mohan Kirana',
    ]);

    expect($customer->fresh()->opening_balance)->toBe('0.00');
});

it('rejects a duplicate uuid within the same business', function () {
    $business = Business::factory()->create();
    $uuid = (string) Str::uuid();

    Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => $uuid, 'name' => 'Ram',
    ]);

    expect(fn () => Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => $uuid, 'name' => 'Ram Again',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
