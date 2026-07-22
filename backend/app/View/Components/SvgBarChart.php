<?php
// app/View/Components/SvgBarChart.php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * A tiny inline-SVG bar chart, rendered server-side — no JS/chart library, so it
 * works in a printed report, needs no assets and trips no CSP. Bar heights are
 * a percentage of the series max; an all-zero series renders flat, never a
 * division by zero.
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

    /** @return list<array{label: string, heightPct: float}> */
    public function bars(): array
    {
        $nums = array_map('floatval', $this->values);
        $max = max($nums);

        return array_map(
            fn (int $i) => [
                'label' => $this->labels[$i] ?? '',
                'heightPct' => $max > 0 ? round($nums[$i] / $max * 100, 1) : 0.0,
            ],
            array_keys($nums),
        );
    }

    public function render(): View
    {
        return view('components.svg-bar-chart');
    }
}
