{{-- resources/views/customers/index.blade.php --}}
@extends('layouts.app')
@php use App\Support\Inr; @endphp

@section('title', __('customers.title') . ' — ' . config('app.name'))

@section('content')
<div class="mx-auto max-w-5xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('customers.heading') }}</h1>
        <a href="{{ route('reports.dashboard', ['business' => $businessId]) }}"
           class="text-sm text-brand">{{ __('customers.back_to_dashboard') }}</a>
    </header>

    <form method="POST" action="{{ route('customers.store') }}" class="card grid gap-3 md:grid-cols-5 md:items-end">
        @csrf
        <input type="hidden" name="business" value="{{ $businessId }}">

        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('customers.name') }}</span>
            <input type="text" name="name" maxlength="120" class="field-input" value="{{ old('name') }}" required>
        </label>

        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('customers.village') }}</span>
            <input type="text" name="village" maxlength="80" class="field-input" value="{{ old('village') }}">
        </label>

        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('customers.phone') }}</span>
            <input type="tel" name="phone" maxlength="20" class="field-input" value="{{ old('phone') }}">
        </label>

        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('customers.opening_balance') }}</span>
            <input type="number" step="0.01" min="0" name="opening_balance" class="field-input"
                   value="{{ old('opening_balance', '0') }}">
        </label>

        <button type="submit" class="btn-primary">{{ __('customers.save') }}</button>

        <p class="md:col-span-5 text-xs text-ink-muted">{{ __('customers.opening_hint') }}</p>

        @error('name')            <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
        @error('village')         <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
        @error('phone')           <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
        @error('opening_balance') <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
    </form>

    <div class="card mt-4 overflow-x-auto">
        <div class="mb-2 flex items-center justify-between">
            <h2 class="font-semibold">{{ __('customers.outstanding') }}</h2>
            <p class="tabular font-bold">
                {{ __('customers.total_outstanding') }}: {{ Inr::format($summary->totalRupees) }}
            </p>
        </div>

        @if ($summary->customers === [])
            <p class="text-sm text-ink-muted">{{ __('customers.no_customers') }}</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-ink-muted">
                        <th>{{ __('customers.name') }}</th>
                        <th>{{ __('customers.village') }}</th>
                        <th class="text-right">{{ __('customers.outstanding') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary->customers as $row)
                        <tr>
                            <td>
                                <a class="text-brand"
                                   href="{{ route('customers.show', ['customer' => $row->customerId, 'business' => $businessId]) }}">
                                    {{ $row->name }}
                                </a>
                            </td>
                            <td>{{ $row->village ?? '—' }}</td>
                            <td class="tabular text-right">{{ Inr::format($row->outstandingRupees) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Only when there is something in it: an empty "Archived" heading reads
         like a section that failed to load. --}}
    @if ($archived->isNotEmpty())
        <div class="card mt-4 overflow-x-auto">
            <h2 class="mb-1 font-semibold">{{ __('customers.archived_heading') }}</h2>
            <p class="mb-2 text-xs text-ink-muted">{{ __('customers.archived_hint') }}</p>

            <table class="w-full text-sm">
                <tbody>
                    @foreach ($archived as $row)
                        <tr>
                            <td>
                                <a class="text-brand"
                                   href="{{ route('customers.show', ['customer' => $row->id, 'business' => $businessId]) }}">
                                    {{ $row->name }}
                                </a>
                            </td>
                            <td>{{ $row->village ?? '—' }}</td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('customers.restore', ['customer' => $row->id]) }}">
                                    @csrf
                                    <input type="hidden" name="business" value="{{ $businessId }}">
                                    <button type="submit" class="text-brand">{{ __('customers.restore') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
