<?php
// app/View/Components/SvgBarChart.php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * A tiny inline-SVG bar chart, rendered server-side — no JS/chart library, so it
 * works in a printed report, needs no assets and trips no CSP.
 *
 * It is a ZERO-BASELINE diverging chart: bars are measured from zero, not from
 * the series minimum. A positive value grows UP from the zero line; a negative
 * value (e.g. a loss month on the net-profit chart) hangs DOWN from it, coloured
 * red. For an all-positive series the zero line sits at the bottom and it looks
 * like an ordinary bar chart. An all-zero series renders flat — never a division
 * by zero.
 *
 * Coordinates are percentages of a 0..100 usable height (yPct = distance from the
 * top; the Blade template maps them into the SVG viewBox).
 */
class SvgBarChart extends Component
{
    /**
     * @param list<int|float|string> $values
     * @param list<string>           $labels
     */
    public function __construct(
        public array $values,
        public array $labels,
        public string $title,
    ) {}

    /**
     * Per-bar geometry relative to the zero baseline.
     *
     * @return list<array{label: string, yPct: float, heightPct: float, negative: bool}>
     */
    public function bars(): array
    {
        [$nums, $range, $max] = $this->bounds();
        $y0 = $this->yOf(0.0, $max, $range); // the zero baseline

        return array_map(
            function (int $i) use ($nums, $max, $range, $y0) {
                $yv = $this->yOf($nums[$i], $max, $range);

                return [
                    'label' => $this->labels[$i] ?? '',
                    'yPct' => min($y0, $yv),            // top edge of the bar
                    'heightPct' => round(abs($yv - $y0), 1),
                    'negative' => $nums[$i] < 0,
                ];
            },
            array_keys($nums),
        );
    }

    /** The y-position (0..100 from the top) of the zero line, for the baseline. */
    public function zeroBaselinePct(): float
    {
        [, $range, $max] = $this->bounds();

        return $this->yOf(0.0, $max, $range);
    }

    /**
     * Normalised bounds. Zero is always included so the baseline stays inside the
     * viewport whether the series is all-positive, all-negative, or mixed.
     *
     * @return array{0: list<float>, 1: float, 2: float} [values, range, max]
     */
    private function bounds(): array
    {
        $nums = array_map('floatval', $this->values);
        $max = max([0.0, ...$nums]);
        $min = min([0.0, ...$nums]);

        return [$nums, $max - $min, $max];
    }

    /** Map a value onto the 0..100 vertical axis (top = max, bottom = min). */
    private function yOf(float $v, float $max, float $range): float
    {
        return $range > 0 ? round(($max - $v) / $range * 100, 1) : 100.0;
    }

    public function render(): View
    {
        return view('components.svg-bar-chart');
    }
}
