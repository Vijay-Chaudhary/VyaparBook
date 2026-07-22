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
