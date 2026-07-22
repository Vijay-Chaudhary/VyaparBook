# Dashboard Grouped Charts with Numeric Axis — Implementation Plan

> **For agentic workers:** Steps use checkbox (`- [ ]`) syntax for tracking. TDD throughout, small commits.

**Goal:** Replace the dashboard's four separate, number-less single-series bar charts with (a) one **grouped multi-bar ₹ chart** showing Sales, Est. gross profit and Net profit side-by-side per month, and (b) a **Production (kg)** chart — both with a **numeric y-axis (labeled reference gridlines) and a legend**. Losses stay loss-aware (net-profit bars below the zero line, in red).

**Architecture:** A new server-rendered `SvgGroupedBarChart` view component that takes 1..N named colored series over shared month labels, computes ONE shared zero-baseline across all series (so grouped bars are comparable), and exposes grouped-bar geometry, y-axis ticks, and legend data. It supersedes `SvgBarChart` on the dashboard (single-series = N=1). A new `Inr::abbreviate()` formats axis ticks (`₹8.3K`, `₹2.6L`). Money in the report stays bcmath decimal strings; only display-only axis-tick labels use float division.

**Tech Stack:** PHP 8.3 / Laravel 11, Blade, inline SVG (no JS/chart lib, CSP-safe), Pest.

**Design decisions (confirmed with the user):**
- **₹ grouped + kg separate:** Sales/Gross/Net (all rupees, comparable) in one grouped chart; Production (kg) as its own chart — never mixed on one axis.
- **Numbers = y-axis reference labels + legend** (not a value stamped on every bar — 36 labels would be unreadable on a static SVG).

**App root is `backend/`.** All paths relative to it; run commands there. Work on `master` (no feature branch). Local Postgres running.

---

## File structure

**Create:**
- `app/View/Components/SvgGroupedBarChart.php` — multi-series grouped-bar geometry, ticks, legend.
- `resources/views/components/svg-grouped-bar-chart.blade.php` — the SVG template.
- `tests/Unit/SvgGroupedBarChartTest.php`, `tests/Unit/InrAbbreviateTest.php`.

**Modify:**
- `app/Support/Inr.php` — add `abbreviate()`.
- `resources/views/reports/partials/charts.blade.php` — use the grouped ₹ chart + kg chart.
- `lang/en/reports.php`, `lang/hi/reports.php` — a combined chart title + reuse existing series labels for the legend.
- `tests/Feature/Web/ReportsDashboardTest.php` — assert legend labels + a tick value render.

**Remove (only after confirming it's unused elsewhere — see Task 4):**
- `app/View/Components/SvgBarChart.php`, `resources/views/components/svg-bar-chart.blade.php`, `tests/Unit/SvgBarChartTest.php`.

---

## Task 1: `Inr::abbreviate()` — compact rupee axis labels

**Files:** Modify `app/Support/Inr.php`; Test `tests/Unit/InrAbbreviateTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
// tests/Unit/InrAbbreviateTest.php

use App\Support\Inr;

it('abbreviates rupee amounts for axis labels', function () {
    expect(Inr::abbreviate('8272.00'))->toBe('₹8.3K');
    expect(Inr::abbreviate('264004.00'))->toBe('₹2.6L');
    expect(Inr::abbreviate('15000000'))->toBe('₹1.5Cr');
    expect(Inr::abbreviate('999.50'))->toBe('₹999.5');
    expect(Inr::abbreviate('0'))->toBe('₹0');
});

it('shows a leading minus for negative amounts and can omit the symbol', function () {
    expect(Inr::abbreviate('-15420.00'))->toBe('−₹15.4K');
    expect(Inr::abbreviate('5000', withSymbol: false))->toBe('5K');
});
```

- [ ] **Step 2:** Run `./vendor/bin/pest tests/Unit/InrAbbreviateTest.php` → FAIL (method not found).

- [ ] **Step 3: Implement** — add to `app/Support/Inr.php` (inside the class):

```php
    /**
     * Compact rupee label for chart axes: ₹8.3K, ₹2.6L, ₹1.5Cr. Display-only
     * (float division is fine here — this never touches a stored figure). Uses
     * Indian scale words (K = thousand, L = lakh, Cr = crore) and the same U+2212
     * minus and ₹ as format().
     */
    public static function abbreviate(string $amount, bool $withSymbol = true): string
    {
        $negative = str_starts_with($amount, '-');
        $n = (float) ltrim($amount, '-');
        $symbol = $withSymbol ? '₹' : '';
        $sign = $negative ? '−' : '';

        $trim = fn (string $s) => rtrim(rtrim($s, '0'), '.');

        if ($n >= 10000000) {
            $body = $trim(number_format($n / 10000000, 1, '.', '')) . 'Cr';
        } elseif ($n >= 100000) {
            $body = $trim(number_format($n / 100000, 1, '.', '')) . 'L';
        } elseif ($n >= 1000) {
            $body = $trim(number_format($n / 1000, 1, '.', '')) . 'K';
        } else {
            $body = $trim(number_format($n, 2, '.', '')) ?: '0';
        }

        return "{$sign}{$symbol}{$body}";
    }
```

- [ ] **Step 4:** Run the test → PASS.
- [ ] **Step 5: Commit**

```bash
git add app/Support/Inr.php tests/Unit/InrAbbreviateTest.php
git commit -m "feat: add Inr::abbreviate for compact chart axis labels"
```

---

## Task 2: `SvgGroupedBarChart` component (geometry, ticks, legend)

**Files:** Create `app/View/Components/SvgGroupedBarChart.php`; Test `tests/Unit/SvgGroupedBarChartTest.php`

- [ ] **Step 1: Failing test**

```php
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
```

- [ ] **Step 2:** Run `./vendor/bin/pest tests/Unit/SvgGroupedBarChartTest.php` → FAIL (class not found).

- [ ] **Step 3: Implement**

```php
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

    /** @return list<array{label: string, bars: list<array{color: string, yPct: float, heightPct: float, negative: bool}>}> */
    public function groups(): array
    {
        [$max, , $range] = $this->bounds();
        $y0 = $this->yOf(0.0, $max, $range);

        return array_map(function (int $i) use ($max, $range, $y0) {
            $bars = array_map(function (array $s) use ($i, $max, $range, $y0) {
                $v = (float) ($s['values'][$i] ?? 0);
                $yv = $this->yOf($v, $max, $range);

                return [
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

    public function render(): View
    {
        return view('components.svg-grouped-bar-chart');
    }
}
```

- [ ] **Step 4:** Run the test → PASS.
- [ ] **Step 5: Commit**

```bash
git add app/View/Components/SvgGroupedBarChart.php tests/Unit/SvgGroupedBarChartTest.php
git commit -m "feat: add SvgGroupedBarChart component (grouped bars, y-axis ticks, legend)"
```

---

## Task 3: Grouped-chart Blade template

**Files:** Create `resources/views/components/svg-grouped-bar-chart.blade.php`

- [ ] **Step 1: Create the template**

Layout: a left gutter (x 0–34) for y-axis labels, plot area x 36–298 / y 0–100, month labels at y 108, legend at y 120+. Class-component public methods are exposed as closures (`$groups`, `$ticks`, `$zeroBaselinePct`, `$legend`) — not via `$this`.

```blade
{{-- resources/views/components/svg-grouped-bar-chart.blade.php --}}
@php
    $groupData = $groups();
    $tickData = $ticks();
    $baseline = $zeroBaselinePct();
    $legendData = $legend();

    $plotLeft = 36; $plotRight = 298; $plotW = $plotRight - $plotLeft;
    $count = max(1, count($groupData));
    $slot = $plotW / $count;
    $seriesCount = max(1, count($groupData[0]['bars'] ?? [1]));
    $barW = ($slot * 0.7) / $seriesCount;
    $groupW = $barW * $seriesCount;
@endphp
<figure class="chart">
    <figcaption class="chart-title">{{ $title }}</figcaption>
    <svg viewBox="0 0 300 132" role="img" aria-label="{{ $title }}" class="w-full">
        {{-- y-axis gridlines + labels --}}
        @foreach ($tickData as $t)
            <line x1="{{ $plotLeft }}" y1="{{ $t['yPct'] }}" x2="{{ $plotRight }}" y2="{{ $t['yPct'] }}"
                  stroke="#e5e7eb" stroke-width="0.3"></line>
            <text x="{{ $plotLeft - 2 }}" y="{{ $t['yPct'] + 2 }}" font-size="5"
                  text-anchor="end" fill="#6b7280">{{ $t['label'] }}</text>
        @endforeach
        {{-- zero baseline (bold) --}}
        <line x1="{{ $plotLeft }}" y1="{{ $baseline }}" x2="{{ $plotRight }}" y2="{{ $baseline }}"
              stroke="#9ca3af" stroke-width="0.5"></line>

        {{-- grouped bars --}}
        @foreach ($groupData as $gi => $group)
            @php $slotX = $plotLeft + $gi * $slot + ($slot - $groupW) / 2; @endphp
            @foreach ($group['bars'] as $bi => $bar)
                <rect x="{{ $slotX + $bi * $barW }}" y="{{ $bar['yPct'] }}"
                      width="{{ $barW * 0.9 }}" height="{{ $bar['heightPct'] }}"
                      fill="{{ $bar['color'] }}"></rect>
            @endforeach
            <text x="{{ $plotLeft + $gi * $slot + $slot / 2 }}" y="108" font-size="5"
                  text-anchor="middle" fill="#555">{{ $group['label'] }}</text>
        @endforeach

        {{-- legend --}}
        @php $lx = $plotLeft; @endphp
        @foreach ($legendData as $item)
            <rect x="{{ $lx }}" y="120" width="5" height="5" fill="{{ $item['color'] }}"></rect>
            <text x="{{ $lx + 7 }}" y="124.5" font-size="5" fill="#374151">{{ $item['label'] }}</text>
            @php $lx += 7 + strlen($item['label']) * 3 + 8; @endphp
        @endforeach
    </svg>
</figure>
```

- [ ] **Step 2: Smoke-render** — `php artisan view:clear`; a quick tinker/route render is optional (the dashboard render test in Task 4 exercises it).
- [ ] **Step 3: Commit**

```bash
git add resources/views/components/svg-grouped-bar-chart.blade.php
git commit -m "feat: add grouped bar chart Blade template with y-axis and legend"
```

---

## Task 4: Wire into the dashboard; retire `SvgBarChart`

**Files:** Modify `resources/views/reports/partials/charts.blade.php`, `lang/en/reports.php`, `lang/hi/reports.php`, `tests/Feature/Web/ReportsDashboardTest.php`. Remove old `SvgBarChart` if unused.

- [ ] **Step 1: Add lang keys** — in `lang/en/reports.php` (near the chart keys):

```php
    'monthly_money_chart' => 'Monthly performance (₹)',
```
and `lang/hi/reports.php`:
```php
    'monthly_money_chart' => 'मासिक प्रदर्शन (₹)',
```
(The legend reuses existing `sales`, `est_gross_profit`, `net_profit`, and `production` keys; the kg chart keeps `monthly_production_chart`.)

- [ ] **Step 2: Rewrite `resources/views/reports/partials/charts.blade.php`**

```blade
{{-- resources/views/reports/partials/charts.blade.php --}}
@php
    $months = collect(range(1, 12))
        ->map(fn ($m) => \Illuminate\Support\Carbon::create()->month($m)->translatedFormat('M'))
        ->all();

    $moneySeries = [
        ['label' => __('reports.sales'), 'color' => '#4472C4',
         'values' => collect($report->trend)->map(fn ($t) => $t->salesRupees)->all()],
        ['label' => __('reports.est_gross_profit'), 'color' => '#16a34a',
         'values' => collect($report->trend)->map(fn ($t) => $t->grossProfitRupees)->all()],
        ['label' => __('reports.net_profit'), 'color' => '#7c3aed',
         'values' => collect($report->trend)->map(fn ($t) => $t->netProfitRupees)->all()],
    ];
    $prodSeries = [
        ['label' => __('reports.production'), 'color' => '#4472C4',
         'values' => collect($report->trend)->map(fn ($t) => $t->productionKg)->all()],
    ];
@endphp
<div class="card space-y-4">
    <x-svg-grouped-bar-chart :series="$moneySeries" :labels="$months"
                             :title="__('reports.monthly_money_chart')" unit="inr" />
    <x-svg-grouped-bar-chart :series="$prodSeries" :labels="$months"
                             :title="__('reports.monthly_production_chart')" unit="kg" />
</div>
```

(Net profit's base color is purple `#7c3aed`; loss months auto-render red via the component's LOSS_COLOR.)

- [ ] **Step 3: Update the dashboard render test** — in `tests/Feature/Web/ReportsDashboardTest.php`, add to the existing render test's assertion chain:

```php
            ->assertSee(__('reports.monthly_money_chart'))   // grouped chart title
            ->assertSee('₹0')                                 // a y-axis tick label renders
```

- [ ] **Step 4: Confirm `SvgBarChart` is unused, then remove it**

```bash
grep -rn "svg-bar-chart\|SvgBarChart" resources app tests | grep -v svg-grouped
```
If the only hits are the component/template/test themselves (no other usage), remove them:
```bash
git rm app/View/Components/SvgBarChart.php resources/views/components/svg-bar-chart.blade.php tests/Unit/SvgBarChartTest.php
```
If anything else uses it, **keep** it and note the leftover in the commit message instead.

- [ ] **Step 5: Run tests**

```bash
php artisan view:clear && ./vendor/bin/pest tests/Feature/Web/ReportsDashboardTest.php tests/Unit/SvgGroupedBarChartTest.php tests/Unit/InrAbbreviateTest.php
```
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add -A resources/views/reports/partials/charts.blade.php lang/en/reports.php lang/hi/reports.php tests/Feature/Web/ReportsDashboardTest.php app/View/Components resources/views/components tests/Unit
git commit -m "feat: use grouped money chart + kg chart on the dashboard; retire SvgBarChart"
```

---

## Task 5: Full suite + live check + wrap-up

- [ ] **Step 1:** `php artisan test` → all green.
- [ ] **Step 2:** Re-seed demo data, start `php artisan serve`, log in as the Namkeen owner, open `/reports/dashboard`, confirm: the grouped ₹ chart shows 3 colored bars per month with a legend and ₹ y-axis labels; a loss month shows the Net bar red below zero; the Production chart shows kg labels. Capture a screenshot.
- [ ] **Step 3:** `git push origin master`.

---

## Self-review / traceability
- **Grouped ₹ chart + separate kg** (user decision): Task 4 wiring — 3-series money chart + 1-series production chart.
- **Numbers = y-axis + legend** (user decision): `ticks()` + `legend()` (Task 2) rendered in the template (Task 3); render test asserts a tick label (Task 4).
- **Loss-aware**: shared zero-baseline + LOSS_COLOR (Task 2), verified by the negative-series unit test.
- **No dead code**: old single-series `SvgBarChart` retired once confirmed unused (Task 4).
- **Money discipline**: report figures stay bcmath strings; only axis-tick labels use display-only float math (documented in `abbreviate()` and `formatTick()`).
