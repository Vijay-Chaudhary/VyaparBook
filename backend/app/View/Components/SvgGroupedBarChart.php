<?php
// app/View/Components/SvgGroupedBarChart.php

namespace App\View\Components;

use App\Support\Inr;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Server-rendered grouped bar chart — no JS/chart lib, CSP-safe, printable.
 *
 * Takes 1..N named colored series over shared x labels and draws their bars
 * grouped within each label slot. All series share ONE zero-baseline computed
 * across every value, so heights are comparable. Positive bars grow up from the
 * zero line; negatives (e.g. a loss month on Net profit) hang down and are
 * coloured red. A numeric y-axis (ticks()) and a legend() give the chart scale.
 *
 * Coordinates are percentages of a 0..100 plot height (yPct = distance from the
 * top). Axis-tick VALUES are display-only, so their float math never touches a
 * stored figure.
 */
class SvgGroupedBarChart extends Component
{
    private const LOSS_COLOR = '#dc2626';

    /**
     * @param list<array{label: string, color: string, values: list<int|float|string>}> $series
     * @param list<string> $labels
     * @param 'inr'|'kg'    $unit
     */
    public function __construct(
        public array $series,
        public array $labels,
        public string $title,
        public string $unit = 'inr',
    ) {}

    /** @return list<array{label: string, bars: list<array{label: string, value: string, color: string, yPct: float, heightPct: float, negative: bool}>}> */
    public function groups(): array
    {
        [$max, , $range] = $this->bounds();
        $y0 = $this->yOf(0.0, $max, $range);

        return array_map(function (int $i) use ($max, $range, $y0) {
            $bars = array_map(function (array $s) use ($i, $max, $range, $y0) {
                $raw = (string) ($s['values'][$i] ?? '0');
                $v = (float) $raw;
                $yv = $this->yOf($v, $max, $range);

                return [
                    'label' => $s['label'],                  // series name, for the hover tooltip
                    'value' => $this->formatValue($raw),     // full (un-abbreviated) value
                    'color' => $v < 0 ? self::LOSS_COLOR : $s['color'],
                    'yPct' => min($y0, $yv),
                    'heightPct' => round(abs($yv - $y0), 1),
                    'negative' => $v < 0,
                ];
            }, $this->series);

            return ['label' => $this->labels[$i] ?? '', 'bars' => array_values($bars)];
        }, range(0, count($this->labels) - 1));
    }

    public function zeroBaselinePct(): float
    {
        [$max, , $range] = $this->bounds();

        return $this->yOf(0.0, $max, $range);
    }

    /** @return list<array{yPct: float, label: string}> */
    public function ticks(): array
    {
        [$max, $min, $range] = $this->bounds();

        $points = [$max];
        if ($max > 0) {
            $points[] = $max / 2;
        }
        $points[] = 0.0;
        if ($min < 0) {
            $points[] = $min;
        }

        return array_map(
            fn (float $v) => ['yPct' => $this->yOf($v, $max, $range), 'label' => $this->formatTick($v)],
            array_values(array_unique($points)),
        );
    }

    /** @return list<array{label: string, color: string}> */
    public function legend(): array
    {
        return array_map(fn (array $s) => ['label' => $s['label'], 'color' => $s['color']], $this->series);
    }

    /** @return array{0: float, 1: float, 2: float} [max, min, range] with zero always included. */
    private function bounds(): array
    {
        $all = array_merge(...array_map(
            fn (array $s) => array_map('floatval', $s['values']),
            $this->series,
        ));
        $max = max([0.0, ...$all]);
        $min = min([0.0, ...$all]);

        return [$max, $min, $max - $min];
    }

    private function yOf(float $v, float $max, float $range): float
    {
        return $range > 0 ? round(($max - $v) / $range * 100, 1) : 100.0;
    }

    private function formatTick(float $v): string
    {
        if ($this->unit === 'kg') {
            $s = rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');

            return $s === '' || $s === '-0' ? '0' : $s;
        }

        return Inr::abbreviate(number_format($v, 2, '.', ''));
    }

    /** Full (un-abbreviated) value for a hover tooltip: ₹8,272.00 or "80 kg". */
    private function formatValue(string $amount): string
    {
        if ($this->unit === 'kg') {
            $s = rtrim(rtrim(bcadd($amount === '' ? '0' : $amount, '0', 3), '0'), '.');

            return (($s === '' || $s === '-0') ? '0' : $s) . ' kg';
        }

        return Inr::format($amount === '' ? '0' : $amount);
    }

    public function render(): View
    {
        return view('components.svg-grouped-bar-chart');
    }
}
