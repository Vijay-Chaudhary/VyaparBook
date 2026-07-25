{{-- resources/views/invoices/show.blade.php — the printable tax invoice. --}}
@extends('layouts.app', ['bare' => true])

@section('title', $invoice->number . ' — ' . config('app.name'))

@section('content')
@php use App\Support\Inr; @endphp
{{-- print:* strips the app chrome so a browser print gives a clean document. --}}
<style>
    @media print {
        .no-print { display: none !important; }
        body { background: #fff; }
    }
</style>

<div class="mx-auto max-w-3xl p-4">
    <div class="no-print mb-4 flex items-center justify-between">
        <a href="{{ route('invoices', ['business' => $businessId]) }}" class="text-sm text-brand">&larr; {{ __('gst.invoices_heading') }}</a>
        <button type="button" onclick="window.print()" class="btn-primary">{{ __('gst.print') }}</button>
    </div>

    <div class="card">
        <h1 class="mb-1 text-center text-lg font-bold tracking-wide">{{ __('gst.tax_invoice') }}</h1>
        <p class="mb-4 text-center text-sm">{{ config('app.name') }}</p>

        <div class="mb-4 grid gap-2 text-sm md:grid-cols-2">
            <div>
                <p><strong>{{ __('gst.seller_gstin') }}:</strong> {{ $invoice->seller_gstin }}</p>
                @if ($invoice->seller_state_code)
                    <p><strong>{{ __('gst.state_code') }}:</strong> {{ $invoice->seller_state_code }}</p>
                @endif
            </div>
            <div class="md:text-right">
                <p><strong>{{ __('gst.number') }}:</strong> {{ $invoice->number }}</p>
                <p><strong>{{ __('gst.date') }}:</strong> {{ $invoice->issued_on?->format('d M Y') }}</p>
            </div>
        </div>

        <div class="mb-4 text-sm">
            <p><strong>{{ __('gst.buyer') }}:</strong> {{ $invoice->buyer_name }}
                @if ($invoice->buyer_village) , {{ $invoice->buyer_village }} @endif
            </p>
            @if ($invoice->buyer_gstin)
                <p><strong>{{ __('gst.seller_gstin') }}:</strong> {{ $invoice->buyer_gstin }}</p>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-ink-muted">
                        <th>{{ __('gst.description') }}</th>
                        <th>{{ __('gst.hsn') }}</th>
                        <th class="text-right">{{ __('gst.qty') }}</th>
                        <th class="text-right">{{ __('gst.rate_each') }}</th>
                        <th class="text-right">{{ __('gst.taxable') }}</th>
                        <th class="text-right">{{ __('gst.rate') }}</th>
                        <th class="text-right">{{ __('gst.cgst') }}</th>
                        <th class="text-right">{{ __('gst.sgst') }}</th>
                        <th class="text-right">{{ __('gst.line_total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoice->lines as $line)
                        <tr>
                            <td>{{ $line->description }}</td>
                            <td class="tabular">{{ $line->hsn_code ?? '—' }}</td>
                            <td class="tabular text-right">{{ $line->qty }}</td>
                            <td class="tabular text-right">{{ Inr::format($line->rate) }}</td>
                            <td class="tabular text-right">{{ Inr::format($line->taxable_value) }}</td>
                            <td class="tabular text-right">{{ rtrim(rtrim((string) $line->gst_rate_percent, '0'), '.') }}%</td>
                            <td class="tabular text-right">{{ Inr::format($line->cgst) }}</td>
                            <td class="tabular text-right">{{ Inr::format($line->sgst) }}</td>
                            <td class="tabular text-right">{{ Inr::format($line->line_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-bold">
                        <td colspan="4" class="text-right">{{ __('gst.grand_total') }}</td>
                        <td class="tabular text-right">{{ Inr::format($invoice->taxable_total) }}</td>
                        <td></td>
                        <td class="tabular text-right">{{ Inr::format($invoice->cgst_total) }}</td>
                        <td class="tabular text-right">{{ Inr::format($invoice->sgst_total) }}</td>
                        <td class="tabular text-right">{{ Inr::format($invoice->grand_total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="mt-4 text-xs text-ink-muted">{{ __('gst.inclusive_note') }}</p>
    </div>
</div>
@endsection
