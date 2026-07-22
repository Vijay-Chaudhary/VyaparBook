<?php
// tests/Unit/SvgGroupedBarChartTest.php

use App\View\Components\SvgGroupedBarChart;

function moneyChart(): SvgGroupedBarChart
{
    return new SvgGroupedBarChart(
        series: [
            ['label' => 'Sales', 'color' => '#4472C4', 'values' => [0, 100, 0]],
            ['label' => 'Net',   'color' => '#16a34a', 'values' => [0, -50, 0]],
        ],
        labels: ['Jan', 'Feb', 'Mar'],
        title: 'Money',
        unit: 'inr',
    );
}

it('groups one bar per series per label and shares a zero baseline', function () {
    $c = moneyChart();
    expect($c->groups())->toHaveCount(3);                 // one group per month
    expect($c->groups()[1]['bars'])->toHaveCount(2);      // two series per group

    // Shared range across BOTH series: max 100, min -50, range 150.
    expect($c->zeroBaselinePct())->toBe(66.7);            // (100-0)/150*100

    $feb = $c->groups()[1]['bars'];
    expect($feb[0]['heightPct'])->toBe(66.7)->and($feb[0]['negative'])->toBeFalse(); // Sales 100 up
    expect($feb[1]['yPct'])->toBe(66.7)->and($feb[1]['negative'])->toBeTrue();        // Net -50 down
});

it('carries a series label and a full formatted value per bar for hover tooltips', function () {
    $c = moneyChart();
    $feb = $c->groups()[1]['bars'];

    expect($feb[0]['label'])->toBe('Sales')->and($feb[0]['value'])->toBe('₹100.00');
    expect($feb[1]['label'])->toBe('Net')->and($feb[1]['value'])->toBe('−₹50.00'); // full, not abbreviated

    $kg = new SvgGroupedBarChart(
        series: [['label' => 'Production', 'color' => '#4472C4', 'values' => [0, 80, 0]]],
        labels: ['Jan', 'Feb', 'Mar'], title: 'Kg', unit: 'kg',
    );
    expect($kg->groups()[1]['bars'][0]['value'])->toBe('80 kg');
});

it('builds y-axis ticks including zero and the negative floor, labelled', function () {
    $c = moneyChart();
    $labels = collect($c->ticks())->pluck('label')->all();
    expect($labels)->toContain('₹100')->toContain('₹0')->toContain('−₹50');
    // Each tick has a yPct in 0..100.
    expect(collect($c->ticks())->every(fn ($t) => $t['yPct'] >= 0 && $t['yPct'] <= 100))->toBeTrue();
});

it('exposes legend entries and formats kg ticks without the rupee symbol', function () {
    $c = moneyChart();
    expect($c->legend())->toBe([
        ['label' => 'Sales', 'color' => '#4472C4'],
        ['label' => 'Net', 'color' => '#16a34a'],
    ]);

    $kg = new SvgGroupedBarChart(
        series: [['label' => 'Production', 'color' => '#4472C4', 'values' => [0, 80, 0]]],
        labels: ['Jan', 'Feb', 'Mar'], title: 'Kg', unit: 'kg',
    );
    expect(collect($kg->ticks())->pluck('label')->all())->toContain('80')->toContain('0');
});
