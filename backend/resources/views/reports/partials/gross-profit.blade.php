{{-- resources/views/reports/partials/gross-profit.blade.php --}}
@php use App\Support\Inr; @endphp
<div class="card mt-4">
    <p class="text-sm text-ink-muted">{{ __('reports.est_gross_profit_month') }}</p>
    <p class="tabular text-2xl font-bold text-success">{{ Inr::format($report->estGrossProfitMonthRupees) }}</p>
    <p class="mt-1 text-xs text-ink-muted">{{ __('reports.gross_profit_caveat') }}</p>
</div>
