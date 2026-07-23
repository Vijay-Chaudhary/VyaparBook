{{-- resources/views/suppliers/show.blade.php --}}
@extends('layouts.app')
@php use App\Support\Inr; @endphp

@section('title', $supplier->name . ' — ' . config('app.name'))

@section('content')
<div class="mx-auto max-w-4xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold">{{ $supplier->name }}</h1>
            <p class="text-sm text-ink-muted">
                {{ $supplier->village ?? '—' }}@if ($supplier->phone) · {{ $supplier->phone }} @endif
            </p>
        </div>
        <a href="{{ route('suppliers', ['business' => $businessId]) }}"
           class="text-sm text-brand">{{ __('suppliers.heading') }}</a>
    </header>

    <div class="card mb-4 flex items-center justify-between">
        <span class="text-ink-muted">{{ __('suppliers.outstanding') }}</span>
        <span class="tabular text-lg font-bold">{{ Inr::format($outstanding) }}</span>
    </div>

    <form method="POST"
          action="{{ route('suppliers.payments.store', ['supplier' => $supplier->id]) }}"
          class="card grid gap-3 md:grid-cols-5 md:items-end">
        @csrf
        <input type="hidden" name="business" value="{{ $businessId }}">

        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('suppliers.payment_amount') }}</span>
            <input type="number" step="0.01" min="0.01" name="amount" class="field-input"
                   value="{{ old('amount') }}" required>
        </label>

        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('suppliers.payment_date') }}</span>
            <input type="date" name="payment_date" class="field-input"
                   value="{{ old('payment_date', now()->format('Y-m-d')) }}" required>
        </label>

        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('suppliers.mode') }}</span>
            <select name="mode" class="field-input">
                @foreach ($modes as $mode)
                    <option value="{{ $mode }}" @selected(old('mode') === $mode)>
                        {{ __('suppliers.modes.' . $mode) }}
                    </option>
                @endforeach
            </select>
        </label>

        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('suppliers.note') }}</span>
            <input type="text" name="note" maxlength="255" class="field-input" value="{{ old('note') }}">
        </label>

        <button type="submit" class="btn-primary">{{ __('suppliers.record_payment') }}</button>

        <p class="md:col-span-5 text-xs text-ink-muted">{{ __('suppliers.payment_not_cash') }}</p>

        @error('amount')       <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
        @error('payment_date') <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
        @error('mode')         <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
        @error('note')         <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
    </form>

    <div class="card mt-4 overflow-x-auto">
        <h2 class="mb-2 font-semibold">{{ __('suppliers.ledger') }}</h2>

        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-ink-muted">
                    <th>{{ __('suppliers.date') }}</th>
                    <th>{{ __('suppliers.particulars') }}</th>
                    <th class="text-right">{{ __('suppliers.amount') }}</th>
                    <th class="text-right">{{ __('suppliers.balance') }}</th>
                </tr>
            </thead>
            <tbody>
                {{-- The opening balance is the ledger's first line, so the running
                     balance below it always reconciles with Outstanding above. --}}
                <tr>
                    <td>—</td>
                    <td>{{ __('suppliers.opening') }}</td>
                    <td class="tabular text-right">{{ Inr::format($supplier->opening_balance) }}</td>
                    <td class="tabular text-right">{{ Inr::format($supplier->opening_balance) }}</td>
                </tr>
                @foreach ($ledger as $e)
                    <tr>
                        <td class="tabular">{{ $e['date']->format('d M Y') }}</td>
                        <td>
                            {{ __('suppliers.' . $e['kind']) }}
                            @if ($e['kind'] === 'purchase')
                                — {{ $e['ref']->rawMaterial?->name ?? '' }} {{ $e['ref']->qty }}
                            @else
                                — {{ __('suppliers.modes.' . $e['ref']->mode) }}
                            @endif
                        </td>
                        <td class="tabular text-right">{{ Inr::format($e['delta']) }}</td>
                        <td class="tabular text-right">{{ Inr::format($e['running_balance']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($ledger->isEmpty())
            <p class="mt-2 text-sm text-ink-muted">{{ __('suppliers.no_entries') }}</p>
        @endif
    </div>
</div>
@endsection
