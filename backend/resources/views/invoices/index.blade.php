{{-- resources/views/invoices/index.blade.php --}}
@extends('layouts.app')

@section('title', __('gst.invoices_title') . ' — ' . config('app.name'))

@section('content')
@php use App\Support\Inr; @endphp
<div class="mx-auto max-w-5xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('gst.invoices_heading') }}</h1>
        <a href="{{ route('gst', ['business' => $businessId]) }}" class="text-sm text-brand">{{ __('gst.heading') }}</a>
    </header>

    @if (session('error'))
        <p class="card mb-3 text-sm text-danger">{{ session('error') }}</p>
    @endif

    <div class="card mt-4 overflow-x-auto">
        <h2 class="mb-2 font-semibold">{{ __('gst.awaiting') }}</h2>
        @if ($uninvoiced->isEmpty())
            <p class="text-sm text-ink-muted">{{ __('gst.awaiting_none') }}</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-ink-muted">
                        <th>{{ __('gst.date') }}</th>
                        <th>{{ __('gst.customer') }}</th>
                        <th class="text-right">{{ __('gst.amount') }}</th>
                        <th class="text-right">{{ __('gst.create') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($uninvoiced as $sale)
                        <tr>
                            <td class="tabular">{{ $sale->sale_date?->format('d M Y') }}</td>
                            <td>
                                @if ($sale->customer)
                                    <a class="text-brand"
                                       href="{{ route('customers.show', ['customer' => $sale->customer->id, 'business' => $businessId]) }}">
                                        {{ $sale->customer->name }}
                                    </a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="tabular text-right">{{ Inr::format($sale->total) }}</td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('invoices.store') }}" class="flex items-center justify-end gap-2">
                                    @csrf
                                    <input type="hidden" name="business" value="{{ $businessId }}">
                                    <input type="hidden" name="sale" value="{{ $sale->id }}">
                                    <input type="text" name="buyer_gstin" maxlength="15"
                                           placeholder="{{ __('gst.buyer_gstin') }}" class="field-input w-44">
                                    <button type="submit" class="btn-primary">{{ __('gst.create') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card mt-4 overflow-x-auto">
        <h2 class="mb-2 font-semibold">{{ __('gst.issued') }}</h2>
        @if ($invoices->isEmpty())
            <p class="text-sm text-ink-muted">{{ __('gst.issued_none') }}</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-ink-muted">
                        <th>{{ __('gst.number') }}</th>
                        <th>{{ __('gst.date') }}</th>
                        <th>{{ __('gst.customer') }}</th>
                        <th class="text-right">{{ __('gst.grand_total') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoices as $invoice)
                        <tr>
                            <td class="tabular">{{ $invoice->number }}</td>
                            <td class="tabular">{{ $invoice->issued_on?->format('d M Y') }}</td>
                            <td>{{ $invoice->buyer_name }}</td>
                            <td class="tabular text-right">{{ Inr::format($invoice->grand_total) }}</td>
                            <td class="text-right">
                                <a class="text-brand" href="{{ route('invoices.show', ['invoice' => $invoice->id, 'business' => $businessId]) }}">{{ __('gst.view') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
