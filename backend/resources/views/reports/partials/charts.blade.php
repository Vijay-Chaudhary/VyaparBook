{{-- resources/views/reports/partials/charts.blade.php --}}
@php
    $months = collect(range(1, 12))
        ->map(fn ($m) => \Illuminate\Support\Carbon::create()->month($m)->translatedFormat('M'))
        ->all();
    $salesValues = collect($report->trend)->map(fn ($t) => $t->salesRupees)->all();
    $prodValues = collect($report->trend)->map(fn ($t) => $t->productionKg)->all();
@endphp
<div class="card space-y-4">
    <x-svg-bar-chart :values="$salesValues" :labels="$months" :title="__('reports.monthly_sales_chart')" />
    <x-svg-bar-chart :values="$prodValues" :labels="$months" :title="__('reports.monthly_production_chart')" />
</div>
