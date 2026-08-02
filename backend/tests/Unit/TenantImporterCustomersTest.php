<?php
// tests/Unit/TenantImporterCustomersTest.php

use App\Import\TenantImporter;
use App\Models\Business;
use App\Models\Customer;

$countFor = fn (Business $b) => Customer::withoutGlobalScopes()
    ->where('business_id', $b->id)
    ->count();

$validRows = fn () => [
    ['name' => 'Ram Traders', 'village' => 'Bagru', 'phone' => '9990001111', 'opening_balance' => '250.00'],
    ['name' => 'Shyam Stores', 'village' => 'Sanganer', 'phone' => '', 'opening_balance' => '0'],
];

it('imports valid customers with their opening balance', function () use ($validRows, $countFor) {
    $business = Business::factory()->create();

    $report = (new TenantImporter())->importCustomers($business->id, $validRows(), false);

    expect($report->created)->toBe(2);
    expect($report->updated)->toBe(0);
    expect($countFor($business))->toBe(2);

    $ram = Customer::withoutGlobalScopes()
        ->where('business_id', $business->id)->where('name', 'Ram Traders')->first();
    expect($ram->opening_balance)->toBe('250.00');
    expect($ram->village)->toBe('Bagru');
    expect($ram->phone)->toBe('9990001111');
});

it('is idempotent on re-run — updates rather than duplicates', function () use ($validRows, $countFor) {
    $business = Business::factory()->create();
    $importer = new TenantImporter();

    $importer->importCustomers($business->id, $validRows(), false);
    $report = $importer->importCustomers($business->id, $validRows(), false);

    expect($report->created)->toBe(0);
    expect($report->updated)->toBe(2);
    expect($countFor($business))->toBe(2);
});

it('skips a row with an empty name and reports it', function () use ($countFor) {
    $business = Business::factory()->create();

    $report = (new TenantImporter())->importCustomers($business->id, [
        ['name' => 'Good Trader', 'village' => 'Bagru', 'phone' => '', 'opening_balance' => '10'],
        ['name' => '  ', 'village' => 'X', 'phone' => '', 'opening_balance' => '0'],
    ], false);

    expect($report->created)->toBe(1);
    expect($report->skipped)->toBe(1);
    expect($report->errors[0]['row'])->toBe(2);
    expect($report->errors[0]['message'])->toBe('name is required');
    expect($countFor($business))->toBe(1);
});

it('skips a non-numeric opening_balance', function () use ($countFor) {
    $business = Business::factory()->create();

    $report = (new TenantImporter())->importCustomers($business->id, [
        ['name' => 'Bad Balance', 'village' => '', 'phone' => '', 'opening_balance' => 'abc'],
    ], false);

    expect($report->skipped)->toBe(1);
    expect($report->errors[0]['message'])->toBe('opening_balance must be a number');
    expect($countFor($business))->toBe(0);
});

it('persists nothing on a dry run but still reports the tally', function () use ($validRows, $countFor) {
    $business = Business::factory()->create();

    $report = (new TenantImporter())->importCustomers($business->id, $validRows(), true);

    expect($report->created)->toBe(2);
    expect($countFor($business))->toBe(0);
});
