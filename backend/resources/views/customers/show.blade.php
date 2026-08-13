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
                        $row = $e['ref'];
                    @endphp
                    <tr class="{{ $isReversal || $done ? 'text-ink-muted' : '' }}">
                        <td class="tabular">{{ $e['date']->format('d M Y') }}</td>
                        <td>{{ __('customers.' . $e['kind']) }}</td>
                        <td class="tabular text-right">{{ Inr::format($e['delta']) }}</td>
                        <td class="tabular text-right">{{ Inr::format($e['running_balance']) }}</td>
                        <td class="text-right align-top">
                            {{-- Half of a reversal pair has no figures of its own
                                 to edit, and deleting either half would revive
                                 the other's effect on the balance. Those rows are
                                 named rather than offered an action; everything
                                 else is corrected by changing it. --}}
                            @if ($isReversal)
                                <span class="text-xs">{{ __('customers.is_correction') }}</span>
                            @elseif ($done)
                                <span class="text-xs">{{ __('customers.corrected') }}</span>
                            @else
                                {{-- Behind a <details> because most rows are read,
                                     not edited: the figures stay the thing you
                                     see and the edit stays one tap away. Mirrors
                                     the order revision form on the orders screen.

                                     ?payment={id} arrives from the "Correct
                                     payment" link on the app's khata. Opened
                                     server-side rather than with a fragment,
                                     because :target cannot force a <details>
                                     open and this screen carries no JS. --}}
                                <details class="text-left"
                                         id="{{ $isSale ? 'sale' : 'payment' }}-{{ $row->id }}"
                                         {{ request($isSale ? 'sale' : 'payment') === $row->id ? 'open' : '' }}>
                                    <summary class="inline-flex min-h-tap cursor-pointer items-center justify-end text-sm font-medium text-brand">
                                        {{ $isSale ? __('customers.edit_sale') : __('customers.edit_payment') }}
                                    </summary>

                                    <div class="card mt-2 max-w-xl">
                                        <form method="POST"
                                              action="{{ $isSale
                                                  ? route('customers.sales.update', ['customer' => $customer->id, 'sale' => $row->id])
                                                  : route('customers.payments.update', ['customer' => $customer->id, 'payment' => $row->id]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="business" value="{{ $businessId }}">

                                            @if ($isSale)
                                                <label class="block text-sm">
                                                    <span class="field-label">{{ __('customers.date') }}</span>
                                                    <input type="date" name="sale_date"
                                                           value="{{ $row->sale_date?->format('Y-m-d') }}"
                                                           class="field-input" required>
                                                </label>

                                                {{-- Quantity and rate per line, and
                                                     nothing else: the total is Σ of
                                                     these and is recomputed server-side,
                                                     never typed. Lines cannot be added
                                                     or dropped here — LedgerEditor says
                                                     why. --}}
                                                @foreach ($row->lines as $line)
                                                    <fieldset class="mt-3">
                                                        <legend class="text-sm font-medium text-ink">
                                                            {{ $line->productPack?->product?->name_en ?: $line->productPack?->product?->name_hi }}
                                                            {{ $line->productPack?->packSize?->label }}
                                                        </legend>
                                                        <div class="flex flex-wrap gap-2">
                                                            <label class="text-sm">
                                                                <span class="field-label">{{ __('customers.qty') }}</span>
                                                                <input type="number" inputmode="numeric" step="1"
                                                                       name="lines[{{ $line->id }}][qty]"
                                                                       value="{{ $line->qty }}" class="field-input" required>
                                                            </label>
                                                            <label class="text-sm">
                                                                <span class="field-label">{{ __('customers.rate') }}</span>
                                                                <input type="number" inputmode="decimal" step="0.01" min="0"
                                                                       name="lines[{{ $line->id }}][rate]"
                                                                       value="{{ $line->rate }}" class="field-input" required>
                                                            </label>
                                                        </div>
                                                    </fieldset>
                                                @endforeach
                                            @else
                                                <label class="block text-sm">
                                                    <span class="field-label">{{ __('customers.amount') }}</span>
                                                    <input type="number" step="0.01" min="0.01" name="amount"
                                                           value="{{ $row->amount }}" class="field-input" required>
                                                </label>
                                                <label class="mt-2 block text-sm">
                                                    <span class="field-label">{{ __('customers.date') }}</span>
                                                    <input type="date" name="payment_date"
                                                           value="{{ $row->payment_date?->format('Y-m-d') }}"
                                                           class="field-input" required>
                                                </label>
                                                <label class="mt-2 block text-sm">
                                                    <span class="field-label">{{ __('customers.mode') }}</span>
                                                    <select name="mode" class="field-input" required>
                                                        @foreach (['cash', 'upi', 'cheque', 'bank', 'other'] as $mode)
                                                            <option value="{{ $mode }}" @selected($row->mode === $mode)>
                                                                {{ __('customers.modes.' . $mode) }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                            @endif

                                            <button type="submit" class="btn-primary mt-3">
                                                {{ __('customers.save_changes') }}
                                            </button>
                                        </form>

                                        {{-- Its own form below a rule: deleting is
                                             destructive and must not sit a stray tap
                                             away from an ordinary edit. Same
                                             separation as voiding an order. --}}
                                        <form method="POST" class="mt-4 border-t border-hairline pt-3"
                                              action="{{ $isSale
                                                  ? route('customers.sales.destroy', ['customer' => $customer->id, 'sale' => $row->id])
                                                  : route('customers.payments.destroy', ['customer' => $customer->id, 'payment' => $row->id]) }}"
                                              onsubmit="return confirm('{{ $isSale ? __('customers.confirm_delete_sale') : __('customers.confirm_delete_payment') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="business" value="{{ $businessId }}">
                                            <button type="submit" class="text-sm text-danger">{{ __('customers.delete') }}</button>
                                        </form>
                                    </div>
                                </details>
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

    {{-- Below the khata, not inside it: a deleted row is not a line of the
         statement, it is a line of what the statement no longer says. Shown at
         all because a delete tapped on the wrong row has to be undoable, and an
         owner who cannot see what they deleted cannot undo it. --}}
    @if ($deleted->isNotEmpty())
        <div class="card mt-4 overflow-x-auto">
            <h2 class="font-semibold">{{ __('customers.deleted_heading') }}</h2>
            <p class="mb-2 text-sm text-ink-muted">{{ __('customers.deleted_hint') }}</p>

            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-ink-muted">
                        <th>{{ __('customers.date') }}</th>
                        <th>{{ __('customers.particulars') }}</th>
                        <th class="text-right">{{ __('customers.amount') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($deleted as $e)
                        <tr class="text-ink-muted">
                            <td class="tabular">{{ $e['date']->format('d M Y') }}</td>
                            <td>
                                {{ __('customers.' . $e['kind']) }}
                                <span class="block text-xs">
                                    {{ __('customers.deleted_on', ['date' => $e['ref']->deleted_at?->format('d M Y')]) }}
                                </span>
                            </td>
                            <td class="tabular text-right">{{ Inr::format($e['delta']) }}</td>
                            <td class="text-right">
                                <form method="POST"
                                      action="{{ $e['kind'] === 'sale'
                                          ? route('customers.sales.restore', ['customer' => $customer->id, 'sale' => $e['ref']->id])
                                          : route('customers.payments.restore', ['customer' => $customer->id, 'payment' => $e['ref']->id]) }}">
                                    @csrf
                                    <input type="hidden" name="business" value="{{ $businessId }}">
                                    <button type="submit" class="text-sm text-brand">{{ __('customers.restore') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Said plainly rather than left as a missing button: an owner looking for
         "record payment" here should know where it lives, not assume it broke. --}}
    <p class="mt-3 text-xs text-ink-muted">{{ __('customers.read_only') }}</p>
</div>
@endsection
