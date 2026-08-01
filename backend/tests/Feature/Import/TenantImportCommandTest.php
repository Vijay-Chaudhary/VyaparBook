<?php
// tests/Feature/Import/TenantImportCommandTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\RawMaterial;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Support\Str;

$fixture = fn (string $name) => dirname(__DIR__, 2) . '/fixtures/import/' . $name;

$ownerFor = function (Business $business): void {
    Membership::create([
        'user_id' => User::factory()->create()->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);
};

$customerCount = fn (Business $b) => Customer
    ->withoutGlobalScopes()->where('business_id', $b->id)->count();

it('imports customers and exits 0', function () use ($fixture, $customerCount) {
    $business = Business::factory()->create();

    $this->artisan('tenant:import', [
        'business_id' => $business->id,
        'type' => 'customers',
        'path' => $fixture('customers.csv'),
    ])->assertExitCode(0);

    expect($customerCount($business))->toBe(2);
});

it('imports raw materials with their opening stock', function () use ($fixture, $ownerFor) {
    $business = Business::factory()->create();
    $ownerFor($business);

    $this->artisan('tenant:import', [
        'business_id' => $business->id,
        'type' => 'raw-materials',
        'path' => $fixture('raw-materials.csv'),
    ])->assertExitCode(0);

    $besan = RawMaterial::withoutGlobalScopes()
        ->where('business_id', $business->id)->where('name', 'Besan')->first();
    expect((new StockService())->onHandFor($besan))->toBe('100.000');
});

it('applies good rows, warns bad ones, and exits 1 on errors', function () use ($fixture, $customerCount) {
    $business = Business::factory()->create();

    $this->artisan('tenant:import', [
        'business_id' => $business->id,
        'type' => 'customers',
        'path' => $fixture('customers-with-errors.csv'),
    ])
        ->expectsOutputToContain('Row 2: name is required')
        ->assertExitCode(1);

    expect($customerCount($business))->toBe(1);
});

it('exits 1 and writes nothing for a missing file', function () use ($customerCount) {
    $business = Business::factory()->create();

    $this->artisan('tenant:import', [
        'business_id' => $business->id,
        'type' => 'customers',
        'path' => '/no/such/file.csv',
    ])->assertExitCode(1);

    expect($customerCount($business))->toBe(0);
});

it('exits 1 for an unknown business', function () use ($fixture) {
    $this->artisan('tenant:import', [
        'business_id' => (string) Str::uuid(),
        'type' => 'customers',
        'path' => $fixture('customers.csv'),
    ])->assertExitCode(1);

    expect(Customer::withoutGlobalScopes()->count())->toBe(0);
});

it('exits 1 for an unknown type', function () use ($fixture) {
    $business = Business::factory()->create();

    $this->artisan('tenant:import', [
        'business_id' => $business->id,
        'type' => 'suppliers',
        'path' => $fixture('customers.csv'),
    ])->assertExitCode(1);
});

it('never touches another tenant (cross-tenant isolation)', function () use ($fixture, $customerCount) {
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();

    Customer::create([
        'business_id' => $businessB->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'B-Only Customer',
        'opening_balance' => '99.00',
    ]);

    $this->artisan('tenant:import', [
        'business_id' => $businessA->id,
        'type' => 'customers',
        'path' => $fixture('customers.csv'),
    ])->assertExitCode(0);

    expect($customerCount($businessA))->toBe(2);
    expect($customerCount($businessB))->toBe(1); // untouched
});

/**
 * The end-to-end reason this reader exists: tenant #1's books arrive as a
 * spreadsheet, not a CSV. Asserts the values land correctly typed in the
 * tenant's tables, not merely that the command exits 0.
 */
it('imports customers from an xlsx and stores excel-native values correctly', function () use ($fixture) {
    $business = Business::factory()->create();

    $this->artisan('tenant:import', [
        'business_id' => $business->id,
        'type' => 'customers',
        'path' => $fixture('customers.xlsx'),
    ])->assertExitCode(0);

    $customers = Customer::withoutGlobalScopes()
        ->where('business_id', $business->id)->orderBy('name')->get();

    expect($customers)->toHaveCount(4);

    $ram = $customers->firstWhere('name', 'Ram Traders');
    // Stored as a number in the sheet — must not have become "9.990001111E+9".
    expect($ram->phone)->toBe('9990001111')
        ->and((float) $ram->opening_balance)->toBe(250.0);

    // Balance came from the formula "=100+25.5".
    expect((float) $customers->firstWhere('name', 'Gopal Kirana')->opening_balance)->toBe(125.5);
});
