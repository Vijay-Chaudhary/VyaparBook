{{-- resources/views/reports/partials/charts.blade.php --}}
@php
    $months = collect(range(1, 12))
        ->map(fn ($m) => \Illuminate\Support\Carbon::create()->month($m)->translatedFormat('M'))
        ->all();

    $moneySeries = [
        ['label' => __('reports.sales'), 'color' => config('charts.series.sales'),
         'values' => collect($report->trend)->map(fn ($t) => $t->salesRupees)->all()],
        ['label' => __('reports.gross_profit'), 'color' => config('charts.series.gross_profit'),
         'values' => collect($report->trend)->map(fn ($t) => $t->grossProfitRupees)->all()],
        ['label' => __('reports.net_profit'), 'color' => config('charts.series.net_profit'),
         'values' => collect($report->trend)->map(fn ($t) => $t->netProfitRupees)->all()],
    ];
    $prodSeries = [
        ['label' => __('reports.production'), 'color' => config('charts.series.production'),
         'values' => collect($report->trend)->map(fn ($t) => $t->productionKg)->all()],
    ];
@endphp
<div class="card space-y-4">
    <x-svg-grouped-bar-chart :series="$moneySeries" :labels="$months"
                             :title="__('reports.monthly_money_chart')" unit="inr" />
    <x-svg-grouped-bar-chart :series="$prodSeries" :labels="$months"
                             :title="__('reports.monthly_production_chart')" unit="kg" />
</div>
