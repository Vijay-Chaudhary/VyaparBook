{{-- resources/views/reports/partials/pnl.blade.php --}}
@php
    use App\Support\Inr;
    $net = $report->netProfitMonthRupees;
    $isLoss = bccomp($net, '0.00', 2) < 0;
@endphp
<div class="card mt-4">
    <div class="mb-2 flex items-center justify-between">
        <h2 class="font-semibold">{{ __('reports.pnl') }}</h2>
        <a href="{{ route('expenses', ['business' => $businessId, 'year' => $report->period->year, 'month' => $report->period->month]) }}"
           class="text-sm text-brand">{{ __('reports.manage_expenses') }}</a>
    </div>

    <dl class="space-y-1 text-sm">
        <div class="flex justify-between">
            <dt>{{ __('reports.sales_month') }}</dt>
            <dd class="tabular">{{ Inr::format($report->salesMonthRupees) }}</dd>
        </div>
        <div class="flex justify-between text-ink-muted">
            <dt>− {{ __('reports.est_cost') }}</dt>
            <dd class="tabular">{{ Inr::format(bcsub($report->salesMonthRupees, $report->estGrossProfitMonthRupees, 2)) }}</dd>
        </div>
        <div class="flex justify-between border-t pt-1">
            <dt>= {{ __('reports.est_gross_profit') }}</dt>
            <dd class="tabular">{{ Inr::format($report->estGrossProfitMonthRupees) }}</dd>
        </div>
        <div class="flex justify-between text-ink-muted">
            <dt>− {{ __('reports.expenses') }}</dt>
            <dd class="tabular">{{ Inr::format($report->expensesMonthRupees) }}</dd>
        </div>
        <div class="flex justify-between border-t pt-1 text-base font-bold {{ $isLoss ? 'text-danger' : 'text-success' }}">
            <dt>= {{ __('reports.net_profit') }}</dt>
            <dd class="tabular">{{ Inr::format($net) }} <span class="text-xs font-normal">({{ $report->netProfitMarginPercent }}% {{ __('reports.net_margin') }})</span></dd>
        </div>
    </dl>

    <p class="mt-2 text-xs text-ink-muted">{{ __('reports.gross_profit_caveat') }}</p>

    @if ($report->expenseBreakdown !== [])
        <h3 class="mt-3 mb-1 text-sm font-semibold">{{ __('reports.expenses_by_category') }}</h3>
        <table class="w-full text-sm">
            <tbody>
                @foreach ($report->expenseBreakdown as $row)
                    <tr>
                        <td>{{ __('expenses.categories.' . $row->category) }}</td>
                        <td class="tabular text-right">{{ Inr::format($row->amountRupees) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
