@extends('layouts.app')

@section('title', __('reports.title') . ' — ' . config('app.name'))

@section('content')
@php
    // A tenant that has recorded nothing yet renders as a wall of zeros and
    // empty tables, which reads as "broken" rather than "new". Detect that state
    // from the aggregates the tiles already show, plus the two row sets that
    // would be non-empty the moment anything is entered.
    $hasActivity = bccomp($report->salesMonthRupees, '0.00', 2) !== 0
        || bccomp($report->outstanding->totalRupees, '0.00', 2) !== 0
        || bccomp($report->stockValueRupees, '0.00', 2) !== 0
        || bccomp($report->cashPositionRupees, '0.00', 2) !== 0
        || bccomp($report->productionMonthKg, '0.000', 3) !== 0
        || $report->productPerformance !== []
        || $report->finishedGoods !== [];

    // Orders first: accepting them is time-sensitive, and a salesman cannot
    // pack until the owner has decided.
    $manageLinks = [
        ['orders', 'reports.manage_orders'],
        ['customers', 'reports.manage_customers'],
        ['expenses', 'reports.manage_expenses'],
        ['purchases', 'reports.manage_purchases'],
        ['suppliers', 'reports.manage_suppliers'],
        ['beats', 'reports.manage_beats'],
        ['pricing', 'reports.manage_pricing'],
        ['gst', 'reports.manage_gst'],
    ];
@endphp

<div class="mx-auto max-w-5xl p-4">
    <header class="mb-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-bold">{{ __('reports.heading') }}</h1>
            <a href="{{ route('app') }}" class="btn-secondary">{{ __('reports.back_to_app') }}</a>
        </div>

        {{-- Nine equal-weight text links wrapped into an undifferentiated block
             and none of them cleared the 44px touch target. As pills they are
             tappable, and the row scrolls sideways on a narrow phone instead of
             reflowing into four ragged lines. --}}
        <nav aria-label="{{ __('reports.heading') }}"
             class="-mx-4 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <ul class="flex w-max gap-2 md:w-auto md:flex-wrap">
                {{-- The screen you are on, so the row has an anchor point. --}}
                <li>
                    <span aria-current="page"
                          class="inline-flex min-h-tap items-center rounded-lg border border-brand
                                 bg-brand px-3 text-sm font-medium text-white">
                        {{ __('reports.heading') }}
                    </span>
                </li>
                @foreach ($manageLinks as [$routeName, $labelKey])
                    <li>
                        <a href="{{ route($routeName, ['business' => $businessId]) }}"
                           class="inline-flex min-h-tap items-center rounded-lg border border-hairline
                                  bg-surface px-3 text-sm font-medium text-brand hover:bg-canvas">
                            {{ __($labelKey) }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </header>

    {{-- Period picker: GET form, so a bookmark/reload keeps the chosen month. --}}
    <form method="GET" action="{{ route('reports.dashboard') }}" class="mb-4 flex flex-wrap items-end gap-2">
        <input type="hidden" name="business" value="{{ $businessId }}">
        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('reports.month') }}</span>
            <select name="month" class="field-input">
                @foreach (range(1, 12) as $m)
                    <option value="{{ $m }}" @selected($m === $report->period->month)>
                        {{ \Illuminate\Support\Carbon::create()->month($m)->translatedFormat('F') }}
                    </option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('reports.period') }}</span>
            <select name="year" class="field-input">
                @foreach (range((int) date('Y'), 2020) as $y)
                    <option value="{{ $y }}" @selected($y === $report->period->year)>{{ $y }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn-primary">{{ __('reports.view') }}</button>
    </form>

    @unless ($hasActivity)
        {{-- Shown ABOVE the tiles, not instead of them: the zeros stay visible
             and correct, this just explains why they are zero and gives the one
             action that changes it. --}}
        <div class="card mb-4 border-brand bg-canvas">
            <h2 class="text-lg font-semibold">{{ __('reports.empty_title') }}</h2>
            <p class="mt-1 text-ink-muted">{{ __('reports.empty_body') }}</p>
            <a href="{{ route('app') }}" class="btn-primary mt-3">{{ __('reports.empty_cta') }}</a>
        </div>
    @endunless

    @include('reports.partials.tiles')
    @include('reports.partials.pnl')
    @include('reports.partials.insights')
    @include('reports.partials.customers')
    @include('reports.partials.suppliers')
    @include('reports.partials.cash')
    @include('reports.partials.finished-goods')

    <div class="mt-4 grid gap-4 md:grid-cols-2">
        @include('reports.partials.charts')
        @include('reports.partials.trend')
    </div>

    @include('reports.partials.products')
</div>
@endsection
