{{-- resources/views/reports/partials/tiles.blade.php --}}
@php use App\Support\Inr; @endphp
<div class="grid grid-cols-2 gap-3 md:grid-cols-4">
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
    </div>
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.production_month') }}</p>
        <p class="tabular text-lg font-bold">{{ rtrim(rtrim($report->productionMonthKg, '0'), '.') ?: '0' }} Kg</p>
    </div>
</div>
