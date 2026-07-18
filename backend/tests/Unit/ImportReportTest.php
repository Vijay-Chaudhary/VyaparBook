<?php
// tests/Unit/ImportReportTest.php

use App\Import\ImportReport;

it('starts empty', function () {
    $report = new ImportReport();

    expect($report->created)->toBe(0);
    expect($report->updated)->toBe(0);
    expect($report->skipped)->toBe(0);
    expect($report->errors)->toBe([]);
    expect($report->hasErrors())->toBeFalse();
});

it('records an error as a skip', function () {
    $report = new ImportReport();

    $report->addError(7, 'bad');

    expect($report->skipped)->toBe(1);
    expect($report->errors[0])->toBe(['row' => 7, 'message' => 'bad']);
    expect($report->hasErrors())->toBeTrue();
});

it('renders a summary line', function () {
    $report = new ImportReport();
    $report->created = 2;
    $report->updated = 1;
    $report->addError(3, 'nope');

    expect($report->summaryLine())->toBe('Created: 2  Updated: 1  Skipped: 1');
});
