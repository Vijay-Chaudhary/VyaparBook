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

    {{-- A correction that is refused has to say why. Without this the button
         appears to do nothing and the owner tries again. --}}
    @if (session('status'))
        <p class="card mb-3 text-sm">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="card mb-3 text-sm text-danger">{{ session('error') }}</p>
    @endif

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
                    <th></th>
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
                    <td></td>
                </tr>
                @php
                    // Which originals already have a reversal pointing at them.
                    // Derived from the rows already loaded — a reversal IS in the
                    // ledger and carries reverses_id — so no extra query.
                    $reversed = $ledger->filter(fn ($e) => str_ends_with($e['kind'], '_reversal'))
                        ->pluck('ref.reverses_id')->filter()->flip();
                @endphp
                @foreach ($ledger as $e)
                    @php
                        $isReversal = str_ends_with($e['kind'], '_reversal');
                        $done = $reversed->has($e['ref']->id);
                        $isSale = str_starts_with($e['kind'], 'sale');
                    @endphp
                    <tr class="{{ $isReversal || $done ? 'text-ink-muted' : '' }}">
                        <td class="tabular">{{ $e['date']->format('d M Y') }}</td>
                        <td>{{ __('customers.' . $e['kind']) }}</td>
                        <td class="tabular text-right">{{ Inr::format($e['delta']) }}</td>
                        <td class="tabular text-right">{{ Inr::format($e['running_balance']) }}</td>
                        <td class="text-right">
                            {{-- Nothing is ever removed: the button writes a
                                 mirror-image row, so both stay visible and the
                                 ledger reads "sale ₹500, voided ₹500" rather
                                 than showing a gap. A reversal cannot itself be
                                 reversed, and an original only once. --}}
                            @if ($isReversal)
                                <span class="text-xs">{{ __('customers.is_correction') }}</span>
                            @elseif ($done)
                                <span class="text-xs">{{ __('customers.corrected') }}</span>
                            @else
                                <form method="POST" class="inline"
                                      action="{{ $isSale
                                          ? route('customers.sales.void', ['customer' => $customer->id, 'sale' => $e['ref']->id])
                                          : route('customers.payments.reverse', ['customer' => $customer->id, 'payment' => $e['ref']->id]) }}"
                                      onsubmit="return confirm('{{ $isSale ? __('customers.confirm_void') : __('customers.confirm_reverse') }}')">
                                    @csrf
                                    <input type="hidden" name="business" value="{{ $businessId }}">
                                    <button type="submit" class="text-xs text-danger">
                                        {{ $isSale ? __('customers.void') : __('customers.reverse') }}
                                    </button>
                                </form>
                            @endif
                        </td>
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
