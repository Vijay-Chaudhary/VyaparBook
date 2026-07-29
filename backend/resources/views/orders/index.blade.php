{{-- resources/views/orders/index.blade.php --}}
@extends('layouts.app')

@section('title', __('orders.title') . ' — ' . config('app.name'))

@section('content')
@php use App\Orders\OrderAdjustment; use App\Support\Inr; @endphp
<div class="mx-auto max-w-5xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('orders.heading') }}</h1>
        <a href="{{ route('reports.dashboard', ['business' => $businessId]) }}"
           class="text-sm text-brand">{{ __('reminders.back_to_dashboard') }}</a>
    </header>

    @if (session('status'))
        <p class="card mb-3 text-sm">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="card mb-3 text-sm text-danger">{{ session('error') }}</p>
    @endif

    @if ($pending->isEmpty())
        <p class="card text-sm text-ink-muted">{{ __('orders.pending_none') }}</p>
    @endif

    @foreach ($pending as $order)
        <div class="card mt-4">
            <div class="mb-2 flex items-center justify-between">
                {{-- Accepting an order is a credit decision, so the customer's
                     khata is one tap away rather than a separate hunt. --}}
                <h2 class="font-semibold">
                    @if ($order->customer)
                        <a class="text-brand"
                           href="{{ route('customers.show', ['customer' => $order->customer->id, 'business' => $businessId]) }}">
                            {{ $order->customer->name }}
                        </a>
                    @else
                        —
                    @endif
                </h2>
                <span class="text-xs text-ink-muted">
                    {{ __('orders.order_date') }}: {{ $order->order_date?->format('d M Y') }}
                </span>
            </div>

            <form method="POST" action="{{ route('orders.accept', ['order' => $order->id]) }}">
                @csrf
                <input type="hidden" name="business" value="{{ $businessId }}">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-ink-muted">
                            <th>{{ __('orders.product') }}</th>
                            <th class="text-right">{{ __('orders.qty') }}</th>
                            <th class="text-right">{{ __('orders.rate') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($order->lines as $line)
                            <tr>
                                <td>
                                    {{ $line->productPack?->product?->name_en ?: $line->productPack?->product?->name_hi }}
                                    {{ $line->productPack?->packSize?->label }}
                                </td>
                                <td class="text-right">
                                    <input type="number" class="field-input w-20 text-right"
                                           name="lines[{{ $line->id }}][qty]" value="{{ $line->qty }}">
                                </td>
                                <td class="text-right">
                                    <input type="number" step="0.01" min="0" class="field-input w-24 text-right"
                                           name="lines[{{ $line->id }}][rate]" value="{{ $line->rate }}">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <p class="mt-2 text-right font-bold">{{ __('orders.total') }}: {{ Inr::format($order->total) }}</p>
                <button type="submit" class="btn-primary mt-2">{{ __('orders.accept') }}</button>
            </form>

            <form method="POST" action="{{ route('orders.reject', ['order' => $order->id]) }}"
                  class="mt-2 flex items-end gap-2">
                @csrf
                <input type="hidden" name="business" value="{{ $businessId }}">
                <label class="text-sm">
                    <span class="block text-ink-muted">{{ __('orders.reason') }}</span>
                    <input type="text" name="status_note" maxlength="255" class="field-input">
                </label>
                <button type="submit" class="text-sm text-danger">{{ __('orders.reject') }}</button>
            </form>
        </div>
    @endforeach

    <div class="card mt-6 overflow-x-auto">
        <h2 class="mb-2 font-semibold">{{ __('orders.recent') }}</h2>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-ink-muted">
                    <th>{{ __('orders.customer') }}</th>
                    <th>{{ __('orders.status') }}</th>
                    <th class="text-right">{{ __('orders.total') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recent as $order)
                    @php $originalTotal = OrderAdjustment::originalTotal($order->lines); @endphp
                    <tr>
                        <td>
                            @if ($order->customer)
                                <a class="text-brand"
                                   href="{{ route('customers.show', ['customer' => $order->customer->id, 'business' => $businessId]) }}">
                                    {{ $order->customer->name }}
                                </a>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            {{ __('orders.statuses.' . $order->status) }}
                            {{-- Says the order was renegotiated without making
                                 the reader open it and compare line by line. --}}
                            @if (OrderAdjustment::anyChanged($order->lines))
                                <span class="block text-xs text-ink-muted">{{ __('orders.adjusted') }}</span>
                            @endif
                        </td>
                        <td class="tabular text-right">
                            {{ Inr::format($order->total) }}
                            @if ($originalTotal !== null && bccomp($originalTotal, (string) $order->total, 2) !== 0)
                                <span class="block text-xs font-normal text-ink-muted">
                                    {{ __('orders.was', ['value' => Inr::format($originalTotal)]) }}
                                </span>
                            @endif
                        </td>
                    </tr>
                    @if ($order->lines->isNotEmpty())
                        {{-- What was agreed, at the rates that were accepted. A
                             decided order showing only a total cannot settle a
                             dispute about what was meant to go out — and where
                             acceptance changed a line, what was asked for too,
                             which is the other half of that same dispute. --}}
                        <tr>
                            <td colspan="3" class="pb-3 pl-3 text-xs text-ink-muted">
                                @foreach ($order->lines as $line)
                                    <span class="mr-3 inline-block">
                                        {{ $line->productPack?->product?->name_en ?: $line->productPack?->product?->name_hi }}
                                        {{ $line->productPack?->packSize?->label }}
                                        <span class="tabular">{{ $line->qty }} × {{ Inr::format($line->rate) }}</span>
                                        @if (OrderAdjustment::changed($line))
                                            {{-- Both halves, even when only one
                                                 moved: "was 10 × ₹90" is what
                                                 was promised, and half of it is
                                                 not a promise anyone made. --}}
                                            <span class="tabular text-danger">
                                                {{ __('orders.was', [
                                                    'value' => $line->ordered_qty . ' × ' . Inr::format($line->ordered_rate),
                                                ]) }}
                                            </span>
                                        @endif
                                    </span>
                                @endforeach
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
