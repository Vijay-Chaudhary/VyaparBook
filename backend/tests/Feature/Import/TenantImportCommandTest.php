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
    Membership::on('pgsql_migrate')->create([
        'user_id' => User::factory()->create()->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);
};

$customerCount = fn (Business $b) => Customer::on('pgsql_migrate')
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

    $besan = RawMaterial::on('pgsql_migrate')->withoutGlobalScopes()
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

    expect(Customer::on('pgsql_migrate')->withoutGlobalScopes()->count())->toBe(0);
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

    Customer::on('pgsql_migrate')->create([
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
