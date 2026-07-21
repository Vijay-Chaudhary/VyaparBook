<?php
// tests/fixtures/import/make-customers-xlsx.php
//
// Regenerates customers.xlsx. Committed because the fixture is binary: without
// this you cannot see what the sheet contains, let alone add a case to it.
//
//   php tests/fixtures/import/make-customers-xlsx.php
//
// Every cell below is stored as a genuine Excel native type — numeric phone,
// float balance, formula, date serial. That is the entire point: each casts to
// something silently wrong (9.99E+9, "250.0", 45292) unless SpreadsheetReader
// converts it deliberately. Storing them as strings would test nothing.

require __DIR__.'/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$out = $argv[1] ?? __DIR__.'/customers.xlsx';

$ss = new Spreadsheet();
$sheet = $ss->getActiveSheet();

// Header, with a trailing empty header cell (D) that must be dropped.
$sheet->fromArray(['name', 'village', 'phone', '', 'opening_balance'], null, 'A1');

// Row 2: phone as a NUMBER (the scientific-notation trap), balance as float.
$sheet->setCellValue('A2', 'Ram Traders');
$sheet->setCellValue('B2', 'Bagru');
$sheet->setCellValueExplicit('C2', 9990001111, DataType::TYPE_NUMERIC);
$sheet->setCellValue('E2', 250.00); // integral float -> must be "250", not "250.0"

// Row 3: blank phone, zero balance, and a fractional value.
$sheet->setCellValue('A3', '  Shyam Stores  '); // untrimmed -> must be trimmed
$sheet->setCellValue('B3', 'Sanganer');
$sheet->setCellValue('E3', 0);

// Row 4: fully blank row -> must be skipped entirely.

// Row 5: a formula for the balance -> must yield the computed result.
$sheet->setCellValue('A5', 'Gopal Kirana');
$sheet->setCellValue('B5', 'Amber');
$sheet->setCellValueExplicit('C5', '0771234567', DataType::TYPE_STRING); // leading zero preserved
$sheet->setCellValue('E5', '=100+25.5'); // -> "125.5"

// Row 6: a date in the village column (nonsense semantically, but proves date
// cells convert instead of arriving as serial 45292).
$sheet->setCellValue('A6', 'Date Tester');
$sheet->setCellValue('B6', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTime('2024-01-01')));
$sheet->getStyle('B6')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
$sheet->setCellValue('E6', 10);

(new Xlsx($ss))->save($out);
echo "wrote {$out}\n";
