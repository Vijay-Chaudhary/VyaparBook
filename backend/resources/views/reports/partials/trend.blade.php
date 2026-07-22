{{-- resources/views/reports/partials/trend.blade.php --}}
@php use App\Support\Inr; @endphp
<div class="card overflow-x-auto">
    <h2 class="mb-2 font-semibold">{{ __('reports.monthly_trend') }}</h2>
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-ink-muted">
                <th>{{ __('reports.month') }}</th>
                <th class="text-right">{{ __('reports.sales') }}</th>
                <th class="text-right">{{ __('reports.production') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report->trend as $row)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::create()->month($row->month)->translatedFormat('M') }}</td>
                    <td class="tabular text-right">{{ Inr::format($row->salesRupees) }}</td>
                    <td class="tabular text-right">{{ rtrim(rtrim($row->productionKg, '0'), '.') ?: '0' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
