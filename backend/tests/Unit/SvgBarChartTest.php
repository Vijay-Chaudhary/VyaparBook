<?php
// tests/Unit/SvgBarChartTest.php

use App\View\Components\SvgBarChart;

it('produces one bar per value with heights scaled to the max', function () {
    $c = new SvgBarChart(
        values: [0, 5, 10],
        labels: ['Jan', 'Feb', 'Mar'],
        title: 'Test',
    );

    expect($c->bars())->toHaveCount(3);
    // Tallest value gets the full bar height; zero gets a zero-height bar.
    expect($c->bars()[2]['heightPct'])->toBe(100.0);
    expect($c->bars()[0]['heightPct'])->toBe(0.0);
    expect($c->bars()[1]['heightPct'])->toBe(50.0);
});

it('handles an all-zero series without dividing by zero', function () {
    $c = new SvgBarChart(values: [0, 0, 0], labels: ['a', 'b', 'c'], title: 'Zero');

    expect(collect($c->bars())->pluck('heightPct')->all())->toBe([0.0, 0.0, 0.0]);
});

it('anchors an all-positive series to a zero baseline at the bottom', function () {
    $c = new SvgBarChart(values: [0, 5, 10], labels: ['Jan', 'Feb', 'Mar'], title: 'Pos');

    expect($c->zeroBaselinePct())->toBe(100.0);   // zero line sits at the bottom
    expect($c->bars()[2]['yPct'])->toBe(0.0);     // tallest bar tops out
    expect($c->bars()[2]['negative'])->toBeFalse();
});

it('draws loss months as full downward bars from a top baseline', function () {
    // A net-profit series where the only active month is a loss.
    $c = new SvgBarChart(values: [0, 0, -6370], labels: ['Jan', 'Feb', 'Mar'], title: 'Net');

    // Every value ≤ 0, so the zero line is at the top and the loss hangs full-height.
    expect($c->zeroBaselinePct())->toBe(0.0);
    expect($c->bars()[2]['heightPct'])->toBe(100.0);   // loss is now VISIBLE (was 0 before)
    expect($c->bars()[2]['yPct'])->toBe(0.0);          // starts at the baseline (top)
    expect($c->bars()[2]['negative'])->toBeTrue();
    expect($c->bars()[0]['heightPct'])->toBe(0.0);     // zero months stay flat
});

it('diverges around zero for a mixed profit/loss series', function () {
    $c = new SvgBarChart(values: [100, -50], labels: ['A', 'B'], title: 'Mixed');

    // range 150 → zero line at (100-0)/150*100 = 66.7 from the top.
    expect($c->zeroBaselinePct())->toBe(66.7);
    // Profit bar grows up from the baseline to the top.
    expect($c->bars()[0]['yPct'])->toBe(0.0);
    expect($c->bars()[0]['heightPct'])->toBe(66.7);
    expect($c->bars()[0]['negative'])->toBeFalse();
    // Loss bar hangs below the baseline.
    expect($c->bars()[1]['yPct'])->toBe(66.7);
    expect($c->bars()[1]['heightPct'])->toBe(33.3);
    expect($c->bars()[1]['negative'])->toBeTrue();
});
