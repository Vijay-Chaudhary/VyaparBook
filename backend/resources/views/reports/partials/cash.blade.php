{{-- resources/views/reports/partials/cash.blade.php --}}
@php
    use App\Support\Inr;
    $months = collect(range(1, 12))
        ->map(fn ($m) => \Illuminate\Support\Carbon::create()->month($m)->translatedFormat('M'))
        ->all();
    $netCashSeries = [
        ['label' => __('reports.net_cash'), 'color' => config('charts.series.net_cash'),
         'values' => collect($report->cashTrend)->map(fn ($r) => $r->netCashRupees)->all()],
    ];
@endphp
<div class="card mt-4">
    <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="font-semibold">{{ __('reports.cash_flow') }}</h2>
        <span class="text-xs text-ink-muted">{{ __('reports.cash_position_hint') }}</span>
    </div>
    <p class="mb-3 text-xs text-ink-muted">{{ __('reports.cash_flow_caption') }}</p>

    <div class="grid gap-4 md:grid-cols-2">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-ink-muted">
                        <th>{{ __('reports.month') }}</th>
                        <th class="text-right">{{ __('reports.cash_in') }}</th>
                        <th class="text-right">{{ __('reports.cash_out') }}</th>
                        <th class="text-right">{{ __('reports.net_cash') }}</th>
                        <th class="text-right">{{ __('reports.cash_position') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report->cashTrend as $row)
                        <tr>
                            <td>{{ $months[$row->month - 1] }}</td>
                            <td class="tabular text-right">{{ Inr::format($row->cashInRupees) }}</td>
                            <td class="tabular text-right">{{ Inr::format($row->cashOutRupees) }}</td>
                            <td class="tabular text-right {{ bccomp($row->netCashRupees, '0.00', 2) < 0 ? 'text-danger' : '' }}">{{ Inr::format($row->netCashRupees) }}</td>
                            <td class="tabular text-right {{ bccomp($row->positionRupees, '0.00', 2) < 0 ? 'text-danger' : '' }}">{{ Inr::format($row->positionRupees) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div>
            <x-svg-grouped-bar-chart :series="$netCashSeries" :labels="$months"
                                     :title="__('reports.monthly_net_cash_chart')" unit="inr" />
        </div>
    </div>
</div>
