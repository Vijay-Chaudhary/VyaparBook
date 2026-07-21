<?php
// tests/Unit/ReportPeriodTest.php

use App\Reports\ReportPeriod;
use Illuminate\Support\Carbon;

it('keeps a valid year and month', function () {
    $p = ReportPeriod::fromInput(2025, 3);
    expect($p->year)->toBe(2025)->and($p->month)->toBe(3);
});

it('clamps an out-of-range month into 1..12', function () {
    expect(ReportPeriod::fromInput(2025, 0)->month)->toBe(1);
    expect(ReportPeriod::fromInput(2025, 13)->month)->toBe(12);
});

it('clamps the year to a sane window and falls back to now for nulls', function () {
    Carbon::setTestNow('2026-07-22');

    expect(ReportPeriod::fromInput(1900, 5)->year)->toBe(2020);   // floor
    expect(ReportPeriod::fromInput(3000, 5)->year)->toBe(2026);   // ceil = current year
    expect(ReportPeriod::fromInput(null, null)->year)->toBe(2026);
    expect(ReportPeriod::fromInput(null, null)->month)->toBe(7);

    Carbon::setTestNow();
});
