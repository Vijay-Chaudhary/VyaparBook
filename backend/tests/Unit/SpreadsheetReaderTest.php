<?php
// tests/Unit/SpreadsheetReaderTest.php

use App\Import\CsvReader;
use App\Import\SheetReaderFactory;
use App\Import\SpreadsheetReader;

$fixture = fn (string $name) => dirname(__DIR__).'/fixtures/import/'.$name;

/**
 * customers.xlsx stores its values as genuine Excel types (numeric phone, float
 * balance, formula, date serial), which is the whole hazard: each one casts to
 * something silently wrong rather than loudly broken.
 */
it('yields header-keyed, trimmed string rows from a spreadsheet', function () use ($fixture) {
    $rows = iterator_to_array((new SpreadsheetReader())->rows($fixture('customers.xlsx')));

    expect($rows)->toHaveCount(4);
    expect($rows[0]['name'])->toBe('Ram Traders')
        ->and($rows[0]['village'])->toBe('Bagru');

    // Whitespace is stripped exactly as CsvReader strips it.
    expect($rows[1]['name'])->toBe('Shyam Stores');
});

it('renders a numeric phone as plain digits, never scientific notation', function () use ($fixture) {
    $rows = iterator_to_array((new SpreadsheetReader())->rows($fixture('customers.xlsx')));

    // Stored as the number 9990001111 — a naive (string) cast risks "9.990001111E+9",
    // which would be written to the tenant's books as their customer's phone.
    expect($rows[0]['phone'])->toBe('9990001111');

    // A text-formatted phone keeps its leading zero.
    expect($rows[2]['phone'])->toBe('0771234567');
});

it('renders money without a float tail, and keeps the fractional part', function () use ($fixture) {
    $rows = iterator_to_array((new SpreadsheetReader())->rows($fixture('customers.xlsx')));

    // 250.00 is an integral float: "250", not "250.0" (and not "250.0000000000").
    expect($rows[0]['opening_balance'])->toBe('250')
        ->and($rows[1]['opening_balance'])->toBe('0');

    // Every value must satisfy the importer's is_numeric() gate.
    foreach ($rows as $row) {
        expect(is_numeric($row['opening_balance']))->toBeTrue();
    }
});

it('resolves a formula to its computed result, not its source', function () use ($fixture) {
    $rows = iterator_to_array((new SpreadsheetReader())->rows($fixture('customers.xlsx')));

    // Cell holds "=100+25.5".
    expect($rows[2]['opening_balance'])->toBe('125.5');
});

it('converts a date cell instead of leaking the excel serial', function () use ($fixture) {
    $rows = iterator_to_array((new SpreadsheetReader())->rows($fixture('customers.xlsx')));

    // 2024-01-01 is serial 45292 on the wire.
    expect($rows[3]['village'])->toBe('2024-01-01');
});

it('skips blank rows and drops columns with an empty header', function () use ($fixture) {
    $rows = iterator_to_array((new SpreadsheetReader())->rows($fixture('customers.xlsx')));

    // Row 4 of the sheet is entirely empty — trailing formatted-but-empty rows
    // would otherwise arrive as bogus "name is required" errors.
    expect($rows)->toHaveCount(4);

    // Column D has no header, so it must not appear as a '' key.
    expect(array_keys($rows[0]))->toBe(['name', 'village', 'phone', 'opening_balance']);
});

it('throws when the file cannot be read', function () {
    (new SpreadsheetReader())->rows('/no/such/file.xlsx')->current();
})->throws(RuntimeException::class);

it('throws on a file that is not a spreadsheet at all', function () use ($fixture) {
    // Named .xlsx, actually binary junk — IOFactory recognises no container.
    (new SpreadsheetReader())->rows($fixture('not-a-spreadsheet.xlsx'))->current();
})->throws(RuntimeException::class);

/**
 * Sniffing the container rather than trusting the extension means an operator
 * who renames a CSV to .xlsx (or the reverse) still gets their import, with the
 * same normalisation applied — note 250.00 arriving as "250" either way.
 */
it('still reads a CSV handed to the spreadsheet reader', function () use ($fixture) {
    $rows = iterator_to_array((new SpreadsheetReader())->rows($fixture('customers.csv')));

    expect($rows)->toHaveCount(2);
    expect($rows[0]['name'])->toBe('Ram Traders')
        ->and($rows[0]['phone'])->toBe('9990001111')
        ->and($rows[0]['opening_balance'])->toBe('250');
});

it('routes each extension to the right reader', function (string $file, string $expected) {
    expect((new SheetReaderFactory())->for($file))->toBeInstanceOf($expected);
})->with([
    'xlsx' => ['/tmp/a.xlsx', SpreadsheetReader::class],
    'xls' => ['/tmp/a.xls', SpreadsheetReader::class],
    'ods' => ['/tmp/a.ods', SpreadsheetReader::class],
    'uppercase XLSX' => ['/tmp/A.XLSX', SpreadsheetReader::class],
    'csv' => ['/tmp/a.csv', CsvReader::class],
    'no extension falls back to csv' => ['/tmp/a', CsvReader::class],
]);
