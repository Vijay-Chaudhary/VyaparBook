{{-- resources/views/customers/show.blade.php --}}
@extends('layouts.app')
@php use App\Support\Inr; @endphp

@section('title', $customer->name . ' — ' . config('app.name'))

@section('content')
<div class="mx-auto max-w-4xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold">{{ $customer->name }}</h1>
            <p class="text-sm text-ink-muted">
                {{ $customer->village ?? '—' }}@if ($customer->phone) · {{ $customer->phone }} @endif
            </p>
        </div>
        <a href="{{ route('customers', ['business' => $businessId]) }}"
           class="text-sm text-brand">{{ __('customers.back_to_customers') }}</a>
    </header>

    <div class="card mb-4 flex items-center justify-between">
        <span class="text-ink-muted">{{ __('customers.outstanding') }}</span>
        <span class="tabular text-lg font-bold">{{ Inr::format($outstanding) }}</span>
    </div>

    {{-- Who they are is editable here; what they owe is not. The khata below
         comes from sales and payments recorded in the app. --}}
    <form method="POST" action="{{ route('customers.update', ['customer' => $customer->id]) }}"
          class="card grid gap-3 md:grid-cols-4 md:items-end">
        @csrf
        @method('PATCH')
        <input type="hidden" name="business" value="{{ $businessId }}">

        <h2 class="md:col-span-4 font-semibold">{{ __('customers.edit') }}</h2>

        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('customers.name') }}</span>
            <input type="text" name="name" maxlength="120" class="field-input"
                   value="{{ old('name', $customer->name) }}" required>
        </label>

        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('customers.village') }}</span>
            <input type="text" name="village" maxlength="80" class="field-input"
                   value="{{ old('village', $customer->village) }}">
        </label>

        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('customers.phone') }}</span>
            <input type="tel" name="phone" maxlength="20" class="field-input"
                   value="{{ old('phone', $customer->phone) }}">
        </label>

        <button type="submit" class="btn-primary">{{ __('customers.update') }}</button>

        @unless ($customer->phone)
            <p class="md:col-span-4 text-xs text-ink-muted">{{ __('customers.phone_hint') }}</p>
        @endunless

        @error('name')    <p class="md:col-span-4 text-sm text-danger">{{ $message }}</p> @enderror
        @error('village') <p class="md:col-span-4 text-sm text-danger">{{ $message }}</p> @enderror
        @error('phone')   <p class="md:col-span-4 text-sm text-danger">{{ $message }}</p> @enderror
    </form>

    {{-- Archiving is separated from the edit form so it cannot be hit while
         reaching for Update. --}}
    <div class="mt-3 flex justify-end">
        @if ($customer->archived_at)
            <form method="POST" action="{{ route('customers.restore', ['customer' => $customer->id]) }}">
                @csrf
                <input type="hidden" name="business" value="{{ $businessId }}">
                <button type="submit" class="text-sm text-brand">{{ __('customers.restore') }}</button>
            </form>
        @else
            <form method="POST" action="{{ route('customers.destroy', ['customer' => $customer->id]) }}"
                  onsubmit="return confirm('{{ __('customers.archive_confirm') }}')">
                @csrf
                @method('DELETE')
                <input type="hidden" name="business" value="{{ $businessId }}">
                <button type="submit" class="text-sm text-danger">{{ __('customers.archive') }}</button>
            </form>
        @endif
    </div>

    <div class="card mt-4 overflow-x-auto">
        <h2 class="mb-2 font-semibold">{{ __('customers.ledger') }}</h2>

        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-ink-muted">
                    <th>{{ __('customers.date') }}</th>
                    <th>{{ __('customers.particulars') }}</th>
                    <th class="text-right">{{ __('customers.amount') }}</th>
                    <th class="text-right">{{ __('customers.balance') }}</th>
                </tr>
            </thead>
            <tbody>
                {{-- The opening balance is the ledger's first line, so the running
                     balance below it always reconciles with Outstanding above. --}}
                <tr>
                    <td>—</td>
                    <td>{{ __('customers.opening') }}</td>
                    <td class="tabular text-right">{{ Inr::format($customer->opening_balance) }}</td>
                    <td class="tabular text-right">{{ Inr::format($customer->opening_balance) }}</td>
                </tr>
                @foreach ($ledger as $e)
                    <tr>
                        <td class="tabular">{{ $e['date']->format('d M Y') }}</td>
                        <td>{{ __('customers.' . $e['kind']) }}</td>
                        <td class="tabular text-right">{{ Inr::format($e['delta']) }}</td>
                        <td class="tabular text-right">{{ Inr::format($e['running_balance']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($ledger->isEmpty())
            <p class="mt-2 text-sm text-ink-muted">{{ __('customers.no_entries') }}</p>
        @endif
    </div>

    {{-- Said plainly rather than left as a missing button: an owner looking for
         "record payment" here should know where it lives, not assume it broke. --}}
    <p class="mt-3 text-xs text-ink-muted">{{ __('customers.read_only') }}</p>
</div>
@endsection
