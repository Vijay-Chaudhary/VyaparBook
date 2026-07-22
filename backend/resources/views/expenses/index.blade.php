{{-- resources/views/expenses/index.blade.php --}}
@extends('layouts.app')

@section('title', __('expenses.title') . ' — ' . config('app.name'))

@section('content')
<div class="mx-auto max-w-5xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('expenses.heading') }}</h1>
        <a href="{{ route('reports.dashboard', ['business' => $businessId]) }}"
           class="text-sm text-brand">{{ __('expenses.back_to_dashboard') }}</a>
    </header>

    <p class="mb-3 text-xs text-ink-muted">{{ __('expenses.operating_only') }}</p>

    {{-- Month/year picker (GET), same shape as the dashboard. --}}
    <form method="GET" action="{{ route('expenses') }}" class="mb-4 flex flex-wrap items-end gap-2">
        <input type="hidden" name="business" value="{{ $businessId }}">
        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('reports.month') }}</span>
            <select name="month" class="field-input">
                @foreach (range(1, 12) as $m)
                    <option value="{{ $m }}" @selected($m === $period->month)>
                        {{ \Illuminate\Support\Carbon::create()->month($m)->translatedFormat('F') }}
                    </option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('reports.period') }}</span>
            <select name="year" class="field-input">
                @foreach (range((int) date('Y'), 2020) as $y)
                    <option value="{{ $y }}" @selected($y === $period->year)>{{ $y }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn-primary">{{ __('reports.view') }}</button>
    </form>

    @include('expenses.partials.form')
    @include('expenses.partials.list')
</div>
@endsection
