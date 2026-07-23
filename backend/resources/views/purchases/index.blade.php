{{-- resources/views/purchases/index.blade.php --}}
@extends('layouts.app')

@section('title', __('purchases.title') . ' — ' . config('app.name'))

@section('content')
<div class="mx-auto max-w-5xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('purchases.heading') }}</h1>
        <div class="flex gap-3 text-sm">
            <a href="{{ route('suppliers', ['business' => $businessId]) }}"
               class="text-brand">{{ __('purchases.suppliers_link') }}</a>
            <a href="{{ route('reports.dashboard', ['business' => $businessId]) }}"
               class="text-brand">{{ __('purchases.back_to_dashboard') }}</a>
        </div>
    </header>

    <p class="mb-3 text-xs text-ink-muted">{{ __('purchases.raw_material_only') }}</p>

    {{-- Month/year picker (GET), same shape as expenses and the dashboard. --}}
    <form method="GET" action="{{ route('purchases') }}" class="mb-4 flex flex-wrap items-end gap-2">
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

    @include('purchases.partials.form')
    @include('purchases.partials.list')
</div>
@endsection
