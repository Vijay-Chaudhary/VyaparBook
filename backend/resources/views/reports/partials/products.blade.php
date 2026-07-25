{{-- resources/views/reports/partials/products.blade.php --}}
@php use App\Support\Inr; @endphp
<div class="card mt-4 overflow-x-auto">
    <h2 class="mb-2 font-semibold">{{ __('reports.product_performance') }}</h2>
    @if ($report->productPerformance === [])
        <p class="text-sm text-ink-muted">{{ __('reports.no_products') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-ink-muted">
                    <th>{{ __('reports.product') }}</th>
                    <th class="text-right">{{ __('reports.qty_sold') }}</th>
                    <th class="text-right">{{ __('reports.sales') }}</th>
                    <th class="text-right">{{ __('reports.cogs') }}</th>
                    <th class="text-right">{{ __('reports.est_profit') }}</th>
                    <th class="text-right">{{ __('reports.margin') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report->productPerformance as $row)
                    <tr>
                        <td>{{ $row->name }}</td>
                        <td class="tabular text-right">{{ $row->qtySold }}</td>
                        <td class="tabular text-right">{{ Inr::format($row->salesRupees) }}</td>
                        <td class="tabular text-right">{{ Inr::format($row->costRupees) }}</td>
                        <td class="tabular text-right">{{ Inr::format($row->profitRupees) }}</td>
                        <td class="tabular text-right">{{ $row->marginPercent }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
