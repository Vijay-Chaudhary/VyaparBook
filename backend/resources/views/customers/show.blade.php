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
        <a href="{{ route('reports.dashboard', ['business' => $businessId]) }}"
           class="text-sm text-brand">{{ __('customers.back_to_dashboard') }}</a>
    </header>

    <div class="card mb-4 flex items-center justify-between">
        <span class="text-ink-muted">{{ __('customers.outstanding') }}</span>
        <span class="tabular text-lg font-bold">{{ Inr::format($outstanding) }}</span>
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
