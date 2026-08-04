{{-- resources/views/reports/partials/tiles.blade.php --}}
@php use App\Support\Inr; @endphp
<div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.sales_today') }}</p>
        <p class="tabular text-lg font-bold">{{ Inr::format($report->salesTodayRupees) }}</p>
    </div>
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.sales_month') }}</p>
        <p class="tabular text-lg font-bold">{{ Inr::format($report->salesMonthRupees) }}</p>
    </div>
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.customer_outstanding') }}</p>
        <p class="tabular text-lg font-bold text-danger">{{ Inr::format($report->outstanding->totalRupees) }}</p>
        {{-- Phase 4a: the one action this figure implies — chase it.
             text-xs is a label size (see tailwind.config.js) and gave this link
             a 16px-tall hit area; as an inline-flex tap target it clears 44px
             without changing the tile's visual weight. --}}
        <a href="{{ route('reminders', ['business' => $businessId]) }}"
           class="-ml-1 inline-flex min-h-tap items-center px-1 text-sm font-medium text-brand">
            {{ __('reminders.heading') }}
        </a>
    </div>
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.production_month') }}</p>
        <p class="tabular text-lg font-bold">{{ rtrim(rtrim($report->productionMonthKg, '0'), '.') ?: '0' }} Kg</p>
    </div>
    {{-- Stock value is point-in-time (what is on hand right now), unlike the
         month figures beside it — reports.stock_value_hint says so. --}}
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.stock_value') }}</p>
        <p class="tabular text-lg font-bold">{{ Inr::format($report->stockValueRupees) }}</p>
        <p class="text-xs text-ink-muted">{{ __('reports.stock_value_hint') }}</p>
    </div>
    {{-- Cash position: running net cash recorded, not a bank balance
         (reports.cash_position_hint). Net-cash-this-month sub-label goes red
         when the month spent more than it collected, like the Net Profit cell. --}}
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.cash_position') }}</p>
        <p class="tabular text-lg font-bold {{ bccomp($report->cashPositionRupees, '0.00', 2) < 0 ? 'text-danger' : '' }}">{{ Inr::format($report->cashPositionRupees) }}</p>
        <p class="text-xs {{ bccomp($report->netCashMonthRupees, '0.00', 2) < 0 ? 'text-danger' : 'text-ink-muted' }}">
            {{ __('reports.net_cash_month') }}: {{ Inr::format($report->netCashMonthRupees) }}
        </p>
    </div>
</div>
