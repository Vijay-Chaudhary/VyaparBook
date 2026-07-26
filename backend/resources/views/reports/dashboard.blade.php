@extends('layouts.app')

@section('title', __('reports.title') . ' — ' . config('app.name'))

@section('content')
<div class="mx-auto max-w-5xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('reports.heading') }}</h1>
        <nav class="flex gap-3 text-sm">
            {{-- Orders first: accepting them is time-sensitive, and a salesman
                 cannot pack until the owner has decided. --}}
            <a href="{{ route('orders', ['business' => $businessId]) }}" class="text-brand">{{ __('reports.manage_orders') }}</a>
            <a href="{{ route('customers', ['business' => $businessId]) }}" class="text-brand">{{ __('reports.manage_customers') }}</a>
            <a href="{{ route('expenses', ['business' => $businessId]) }}" class="text-brand">{{ __('reports.manage_expenses') }}</a>
            <a href="{{ route('purchases', ['business' => $businessId]) }}" class="text-brand">{{ __('reports.manage_purchases') }}</a>
            <a href="{{ route('suppliers', ['business' => $businessId]) }}" class="text-brand">{{ __('reports.manage_suppliers') }}</a>
            <a href="{{ route('beats', ['business' => $businessId]) }}" class="text-brand">{{ __('reports.manage_beats') }}</a>
            <a href="{{ route('gst', ['business' => $businessId]) }}" class="text-brand">{{ __('reports.manage_gst') }}</a>
            <a href="{{ route('app') }}" class="text-brand">{{ __('reports.back_to_app') }}</a>
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
