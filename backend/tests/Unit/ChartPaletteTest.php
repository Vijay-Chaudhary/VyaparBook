<?php

// Guards config/charts.php against the regression it was created to fix: a
// series colour that looks fine on a designer's monitor and washes out on a
// cheap phone in daylight. The colours replaced here measured 3.15:1 and
// 3.52:1 against the canvas — legal under WCAG's 3:1 graphical-object rule,
// and the two weakest marks on the dashboard.

/** WCAG 2.1 relative luminance. */
function relativeLuminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $channels = array_map(
        function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        },
        [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))],
    );

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function contrastRatio(string $a, string $b): float
{
    $la = relativeLuminance($a);
    $lb = relativeLuminance($b);
    [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];

    return ($hi + 0.05) / ($lo + 0.05);
}

// Both backgrounds a chart can sit on: .card is surface, the page is canvas.
const CHART_BACKGROUNDS = ['surface' => '#FFFFFF', 'canvas' => '#F8FAFC'];

it('holds every series colour above 4.5:1 on both chart backgrounds', function () {
    $series = config('charts.series');

    expect($series)->not->toBeEmpty();

    foreach ($series as $metric => $hex) {
        foreach (CHART_BACKGROUNDS as $name => $bg) {
            expect(contrastRatio($hex, $bg))
                ->toBeGreaterThan(4.5, "series '{$metric}' ({$hex}) on {$name}");
        }
    }
});

it('holds the loss colour above 4.5:1 and keeps it distinct from every series', function () {
    $loss = config('charts.loss');

    foreach (CHART_BACKGROUNDS as $name => $bg) {
        expect(contrastRatio($loss, $bg))->toBeGreaterThan(4.5, "loss on {$name}");
    }

    // A negative bar recolours to loss; if a series already were that colour,
    // a loss month would be indistinguishable from a normal one.
    expect(config('charts.series'))->not->toContain($loss);
});

it('holds chart text above the 4.5:1 text bar and keeps gridlines subtle', function () {
    $chrome = config('charts.chrome');

    foreach (['tick_label', 'group_label', 'legend_label'] as $key) {
        expect(contrastRatio($chrome[$key], '#FFFFFF'))
            ->toBeGreaterThan(4.5, "chrome '{$key}'");
    }

    // Deliberately faint — asserted so nobody "fixes" it into competing with
    // the data. See §10 gridline-subtle.
    expect(contrastRatio($chrome['gridline'], '#FFFFFF'))->toBeLessThan(1.5);

    // The tooltip is the one dark-mode surface in the app; both pairings carry
    // real text and must clear the text bar.
    expect(contrastRatio($chrome['tooltip_fg'], $chrome['tooltip_bg']))->toBeGreaterThan(4.5);
    expect(contrastRatio($chrome['tooltip_fg_dark'], $chrome['tooltip_bg_dark']))->toBeGreaterThan(4.5);
});

it('keeps no hardcoded chart colour in the views', function () {
    $files = [
        resource_path('views/components/svg-grouped-bar-chart.blade.php'),
        resource_path('views/reports/partials/charts.blade.php'),
        resource_path('views/reports/partials/cash.blade.php'),
    ];

    foreach ($files as $file) {
        expect(preg_match('/#[0-9A-Fa-f]{3,8}\b/', file_get_contents($file)))
            ->toBe(0, basename($file).' hardcodes a colour; use config/charts.php');
    }
});
