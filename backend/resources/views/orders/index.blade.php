{{-- resources/views/orders/index.blade.php --}}
@extends('layouts.app')

@section('title', __('orders.title') . ' — ' . config('app.name'))

@section('content')
@php use App\Orders\OrderAdjustment; use App\Orders\OrderStatus; use App\Pricing\PriceFloor; use App\Support\Inr; @endphp
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
                            @php
                                $floor = $line->productPack ? PriceFloor::for($line->productPack) : null;
                                $under = $floor !== null && bccomp((string) $line->rate, $floor, 2) < 0;
                            @endphp
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
                                    {{-- Cost is advice, not a limit: this shop sells
                                         some packs under cost deliberately. Shown only
                                         when the rate is actually below it, so the
                                         common line stays uncluttered. --}}
                                    @if ($under)
                                        <span class="block text-xs font-medium text-danger">
                                            {{ __('orders.under_cost', ['cost' => Inr::format($floor)]) }}
                                        </span>
                                    @endif
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
                            {{-- A salesman could already cancel from the phone;
                                 the owner could not, and was left watching an
                                 order they had decided against. Not a reversal:
                                 an order before delivery is not money, so there
                                 is nothing on the books to mirror. --}}
                            @unless (OrderStatus::isTerminal($order->status))
                                <form method="POST"
                                      action="{{ route('orders.cancel', ['order' => $order->id]) }}"
                                      onsubmit="return confirm('{{ __('orders.confirm_cancel') }}')">
                                    @csrf
                                    <input type="hidden" name="business" value="{{ $businessId }}">
                                    {{-- text-xs gave this a 16px hit area; as an
                                         inline-flex target it clears 44px without
                                         changing how the cell reads. --}}
                                    <button type="submit"
                                            class="-ml-1 inline-flex min-h-tap items-center px-1 text-sm font-medium text-danger">
                                        {{ __('orders.cancel') }}
                                    </button>
                                </form>
                            @endunless
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

                                {{-- Owner corrections, including after delivery.
                                     Behind a <details> because most rows are
                                     read, not edited — the figures stay the
                                     thing you see, and the machinery for
                                     changing them stays one tap away. --}}
                                @unless (in_array($order->status, [OrderStatus::CANCELLED, OrderStatus::REJECTED], true))
                                    {{-- ?order={id} arrives from the "Correct this
                                         order" link on a customer's khata in the
                                         app. Opened server-side rather than with a
                                         fragment, because :target cannot force a
                                         <details> open and this screen carries no
                                         JS of its own. --}}
                                    <details class="mt-2" id="order-{{ $order->id }}" {{ request('order') === $order->id ? 'open' : '' }}>
                                        <summary class="inline-flex min-h-tap cursor-pointer items-center text-sm font-medium text-brand">
                                            {{ __('orders.revise') }}
                                        </summary>

                                        <div class="card mt-2 max-w-xl">
                                            <h3 class="text-base font-semibold text-ink">{{ __('orders.revise_heading') }}</h3>
                                            <p class="mt-1 text-sm text-ink-muted">{{ __('orders.revise_hint') }}</p>

                                            <form method="POST" class="mt-3"
                                                  action="{{ route('orders.revise', ['order' => $order->id]) }}">
                                                @csrf
                                                <input type="hidden" name="business" value="{{ $businessId }}">

                                                @foreach ($order->lines as $line)
                                                    <fieldset class="mb-3">
                                                        <legend class="text-sm font-medium text-ink">
                                                            {{ $line->productPack?->product?->name_en ?: $line->productPack?->product?->name_hi }}
                                                            {{ $line->productPack?->packSize?->label }}
                                                        </legend>
                                                        <div class="flex flex-wrap gap-2">
                                                            <label class="text-sm">
                                                                <span class="field-label">{{ __('orders.qty') }}</span>
                                                                <input type="number" inputmode="numeric" step="1"
                                                                       name="lines[{{ $line->id }}][qty]"
                                                                       value="{{ $line->qty }}" class="field-input" required>
                                                            </label>
                                                            <label class="text-sm">
                                                                <span class="field-label">{{ __('orders.rate') }}</span>
                                                                <input type="number" inputmode="decimal" step="0.01" min="0"
                                                                       name="lines[{{ $line->id }}][rate]"
                                                                       value="{{ $line->rate }}" class="field-input" required>
                                                            </label>
                                                        </div>
                                                    </fieldset>
                                                @endforeach

                                                <button type="submit" class="btn-primary">{{ __('orders.revise') }}</button>
                                            </form>

                                            {{-- Separated from the correction form
                                                 above: voiding is destructive and
                                                 must not sit a stray tap away from
                                                 an ordinary edit. --}}
                                            <form method="POST" class="mt-4 border-t border-hairline pt-3"
                                                  action="{{ route('orders.void', ['order' => $order->id]) }}"
                                                  onsubmit="return confirm('{{ __('orders.void_confirm') }}')">
                                                @csrf
                                                <input type="hidden" name="business" value="{{ $businessId }}">
                                                <label class="block text-sm">
                                                    <span class="field-label">{{ __('orders.void_note') }}</span>
                                                    <input type="text" name="status_note" maxlength="255" class="field-input">
                                                </label>
                                                <button type="submit"
                                                        class="btn-secondary mt-2 border-danger text-danger">
                                                    {{ __('orders.void') }}
                                                </button>
                                            </form>
                                        </div>
                                    </details>
                                @endunless
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
