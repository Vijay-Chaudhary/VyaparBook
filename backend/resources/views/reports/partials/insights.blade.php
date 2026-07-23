{{-- resources/views/reports/partials/insights.blade.php --}}
<div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.highest_selling_product') }}</p>
        <p class="font-semibold">{{ $report->highestSellingName ?? '—' }}</p>
    </div>
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.highest_profit_product') }}</p>
        <p class="font-semibold text-success">{{ $report->highestProfitName ?? '—' }}</p>
    </div>
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.stock_low_alerts') }}</p>
        <p class="font-semibold text-danger">{{ $report->lowStockCount }}</p>
    </div>
</div>

<div class="card mt-4">
    <h2 class="mb-2 font-semibold">{{ __('reports.low_stock') }}</h2>
    @if ($report->lowStock === [])
        <p class="text-sm text-ink-muted">{{ __('reports.no_low_stock') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-ink-muted">
                    <th>{{ __('reports.material') }}</th>
                    <th class="text-right">{{ __('reports.on_hand') }}</th>
                    <th class="text-right">{{ __('reports.reorder') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report->lowStock as $row)
                    <tr>
                        <td>{{ $row->name }}</td>
                        <td class="tabular text-right">{{ $row->onHand }}</td>
                        <td class="tabular text-right">{{ $row->reorder }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- Stock valuation sits with the materials it values. Cost/Kg is the
     weighted average of everything ever bought of that material; a material
     never purchased has no cost source, so it shows — rather than ₹0.00,
     which would read as "worth nothing" instead of "not known". --}}
<div class="card mt-4 overflow-x-auto">
    <div class="mb-2 flex items-center justify-between">
        <h2 class="font-semibold">{{ __('reports.stock_valuation') }}</h2>
        <p class="tabular font-bold">{{ \App\Support\Inr::format($report->stockValueRupees) }}</p>
    </div>

    @if ($report->stockValuation === [])
        <p class="text-sm text-ink-muted">{{ __('reports.no_materials') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-ink-muted">
                    <th>{{ __('reports.material') }}</th>
                    <th class="text-right">{{ __('reports.on_hand') }}</th>
                    <th class="text-right">{{ __('reports.cost_per_kg') }}</th>
                    <th class="text-right">{{ __('reports.stock_value') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report->stockValuation as $row)
                    @php $priced = bccomp($row->costPerKgRupees, '0.00', 2) !== 0; @endphp
                    <tr>
                        <td>{{ $row->name }}</td>
                        <td class="tabular text-right">{{ $row->onHand }}</td>
                        <td class="tabular text-right">
                            {{ $priced ? \App\Support\Inr::format($row->costPerKgRupees) : '—' }}
                        </td>
                        <td class="tabular text-right">
                            {{ $priced ? \App\Support\Inr::format($row->valueRupees) : '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
