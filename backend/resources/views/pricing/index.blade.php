{{-- resources/views/pricing/index.blade.php --}}
@extends('layouts.app')

@section('title', __('pricing.title') . ' — ' . config('app.name'))

@section('content')
@php use App\Pricing\Margin; use App\Pricing\PriceFloor; use App\Support\Inr; @endphp
<div class="mx-auto max-w-5xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('pricing.heading') }}</h1>
        <a href="{{ route('reports.dashboard', ['business' => $businessId]) }}"
           class="text-sm text-brand">{{ __('reminders.back_to_dashboard') }}</a>
    </header>

    @if (session('status'))
        <p class="card mb-3 text-sm">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="card mb-3 text-sm text-danger">{{ session('error') }}</p>
    @endif

    {{-- Selling price is a starting suggestion, not a price. Every sale here is
         negotiated per customer, so calling this column "the price" would
         misdescribe the business. --}}
    <p class="card mb-4 text-sm text-ink-muted">{{ __('pricing.intro') }}</p>

    @if ($errors->any())
        <ul class="card mb-3 text-sm text-danger">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('pricing.save') }}">
        @csrf
        <input type="hidden" name="business" value="{{ $businessId }}">

        @foreach ($products as $product)
            @php $perKg = $product->base_cost_per_kg; @endphp
            <section class="card mt-4">
                <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                    <h2 class="font-semibold">
                        {{ $product->name_en ?: $product->name_hi }}
                    </h2>
                    <label class="text-sm">
                        <span class="block text-ink-muted">{{ __('pricing.cost_per_kg') }}</span>
                        <input type="number" step="0.01" min="0"
                               class="field-input tabular w-32 text-right"
                               name="products[{{ $product->id }}][base_cost_per_kg]"
                               value="{{ $perKg }}">
                    </label>
                </div>

                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-ink-muted">
                            <th>{{ __('pricing.pack') }}</th>
                            <th class="text-right">{{ __('pricing.weight') }}</th>
                            <th class="text-right">{{ __('pricing.pack_cost') }}</th>
                            <th class="text-right">{{ __('pricing.sell') }}</th>
                            <th class="text-right">{{ __('pricing.margin') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($product->productPacks->sortBy(fn ($p) => (float) ($p->packSize?->weight_kg ?? 0)) as $pack)
                            @php
                                // The number the cost floor will ACTUALLY use —
                                // the stored per-pack cost when set, otherwise
                                // derived from per-kg. Showing anything else
                                // would make this screen lie about the effect.
                                $effective = PriceFloor::for($pack);
                                $derived = $pack->default_cost_price === null;
                                $sell = (string) $pack->default_sell_price;
                                $loss = Margin::isLoss($sell, $effective);
                            @endphp
                            <tr class="border-t border-hairline">
                                <td class="py-2">{{ $pack->packSize?->label }}</td>
                                <td class="tabular py-2 text-right text-ink-muted">{{ $pack->packSize?->weight_kg }}</td>
                                <td class="py-2 text-right">
                                    <input type="number" step="0.01" min="0"
                                           class="field-input tabular w-24 text-right"
                                           name="packs[{{ $pack->id }}][default_cost_price]"
                                           value="{{ $pack->default_cost_price }}"
                                           placeholder="{{ $effective }}">
                                    {{-- Makes the precedence visible. A blank box
                                         is not "no cost": it falls back to per-kg,
                                         and an owner who cannot see that will not
                                         understand why editing per-kg did nothing. --}}
                                    <span class="block text-xs text-ink-muted">
                                        @if ($derived && $effective !== null)
                                            {{ __('pricing.from_per_kg', ['value' => Inr::format($effective)]) }}
                                        @elseif ($derived)
                                            {{ __('pricing.no_cost') }}
                                        @else
                                            {{ __('pricing.overrides_per_kg') }}
                                        @endif
                                    </span>
                                </td>
                                <td class="py-2 text-right">
                                    <input type="number" step="0.01" min="0"
                                           class="field-input tabular w-24 text-right"
                                           name="packs[{{ $pack->id }}][default_sell_price]"
                                           value="{{ $pack->default_sell_price }}">
                                </td>
                                <td class="tabular py-2 text-right {{ $loss ? 'font-medium text-danger' : '' }}">
                                    @php
                                        $amount = Margin::amount($sell, $effective);
                                        $percent = Margin::percent($sell, $effective);
                                    @endphp
                                    @if ($amount === null)
                                        <span class="text-ink-muted">—</span>
                                    @else
                                        {{ Inr::format($amount) }}
                                        @if ($percent !== null)
                                            <span class="block text-xs">{{ $percent }}%</span>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-2 text-ink-muted">{{ __('pricing.no_packs') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </section>
        @endforeach

        @if ($products->isEmpty())
            <p class="card text-sm text-ink-muted">{{ __('pricing.no_products') }}</p>
        @else
            <button type="submit" class="btn-primary mt-4 w-full">{{ __('pricing.save_all') }}</button>
        @endif
    </form>

    {{-- Separate forms, outside the one above: nesting is invalid HTML, and
         recosting overwrites the very fields that form is editing. --}}
    @foreach ($products as $product)
        @if ($product->base_cost_per_kg !== null && $product->productPacks->isNotEmpty())
            <form method="POST" action="{{ route('pricing.recost', ['product' => $product->id]) }}" class="mt-3">
                @csrf
                <input type="hidden" name="business" value="{{ $businessId }}">
                <button type="submit" class="btn-secondary w-full text-sm">
                    {{ __('pricing.recost', [
                        'product' => $product->name_en ?: $product->name_hi,
                        'value' => Inr::format($product->base_cost_per_kg),
                    ]) }}
                </button>
            </form>
        @endif
    @endforeach
</div>
@endsection
