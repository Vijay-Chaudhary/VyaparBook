{{-- resources/views/suppliers/index.blade.php --}}
@extends('layouts.app')
@php use App\Support\Inr; @endphp

@section('title', __('suppliers.title') . ' — ' . config('app.name'))

@section('content')
<div class="mx-auto max-w-5xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('suppliers.heading') }}</h1>
        <div class="flex gap-3 text-sm">
            <a href="{{ route('purchases', ['business' => $businessId]) }}"
               class="text-brand">{{ __('suppliers.purchases_link') }}</a>
            <a href="{{ route('reports.dashboard', ['business' => $businessId]) }}"
               class="text-brand">{{ __('suppliers.back_to_dashboard') }}</a>
        </div>
    </header>

    <form method="POST" action="{{ route('suppliers.store') }}" class="card grid gap-3 md:grid-cols-5 md:items-end">
        @csrf
        <input type="hidden" name="business" value="{{ $businessId }}">

        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('suppliers.name') }}</span>
            <input type="text" name="name" maxlength="120" class="field-input" value="{{ old('name') }}" required>
        </label>

        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('suppliers.village') }}</span>
            <input type="text" name="village" maxlength="120" class="field-input" value="{{ old('village') }}">
        </label>

        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('suppliers.phone') }}</span>
            <input type="tel" name="phone" maxlength="20" class="field-input" value="{{ old('phone') }}">
        </label>

        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('suppliers.opening_balance') }}</span>
            <input type="number" step="0.01" name="opening_balance" class="field-input"
                   value="{{ old('opening_balance', '0') }}">
        </label>

        <button type="submit" class="btn-primary">{{ __('suppliers.save') }}</button>

        <p class="md:col-span-5 text-xs text-ink-muted">{{ __('suppliers.opening_hint') }}</p>

        @error('name')            <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
        @error('village')         <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
        @error('phone')           <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
        @error('opening_balance') <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
    </form>

    <div class="card mt-4 overflow-x-auto">
        <div class="mb-2 flex items-center justify-between">
            <h2 class="font-semibold">{{ __('suppliers.outstanding') }}</h2>
            <p class="tabular font-bold">
                {{ __('suppliers.total_outstanding') }}: {{ Inr::format($summary->totalRupees) }}
            </p>
        </div>

        @if ($summary->suppliers === [])
            <p class="text-sm text-ink-muted">{{ __('suppliers.no_suppliers') }}</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-ink-muted">
                        <th>{{ __('suppliers.name') }}</th>
                        <th>{{ __('suppliers.village') }}</th>
                        <th class="text-right">{{ __('suppliers.outstanding') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary->suppliers as $s)
                        <tr>
                            <td>
                                <a class="text-brand"
                                   href="{{ route('suppliers.show', ['supplier' => $s->id, 'business' => $businessId]) }}">
                                    {{ $s->name }}
                                </a>
                            </td>
                            <td>{{ $s->village ?? '—' }}</td>
                            <td class="tabular text-right">{{ Inr::format($s->outstandingRupees) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
