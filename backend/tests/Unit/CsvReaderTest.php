<?php
// tests/Unit/CsvReaderTest.php

use App\Import\CsvReader;

$fixture = fn (string $name) => dirname(__DIR__) . '/fixtures/import/' . $name;

it('yields header-keyed, trimmed rows from a CSV', function () use ($fixture) {
    $rows = iterator_to_array((new CsvReader())->rows($fixture('customers.csv')));

    expect($rows)->toHaveCount(2);
    expect($rows[0]['name'])->toBe('Ram Traders');
    expect($rows[0]['village'])->toBe('Bagru');
    expect($rows[0]['opening_balance'])->toBe('250.00');
    expect($rows[1]['phone'])->toBe('');
    expect($rows[1]['opening_balance'])->toBe('0');
});

it('throws when the path cannot be opened', function () {
    (new CsvReader())->rows('/no/such/file.csv')->current();
})->throws(RuntimeException::class);
