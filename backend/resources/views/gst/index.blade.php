{{-- resources/views/gst/index.blade.php --}}
@extends('layouts.app')

@section('title', __('gst.title') . ' — ' . config('app.name'))

@section('content')
<div class="mx-auto max-w-4xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('gst.heading') }}</h1>
        <a href="{{ route('invoices', ['business' => $businessId]) }}" class="text-sm text-brand">{{ __('gst.invoices_heading') }}</a>
    </header>

    <p class="mb-3 text-xs text-ink-muted">{{ __('gst.intro') }}</p>

    @if (session('status'))
        <p class="card mb-3 text-sm">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('gst.save') }}">
        @csrf
        <input type="hidden" name="business" value="{{ $businessId }}">

        <div class="card flex flex-wrap items-end gap-3">
            <label class="text-sm">
                <span class="block text-ink-muted">{{ __('gst.default_rate') }}</span>
                <input type="number" step="0.01" min="0" max="100" name="default_gst_rate_percent"
                       class="field-input" value="{{ old('default_gst_rate_percent', $business->default_gst_rate_percent) }}">
            </label>
            <label class="text-sm">
                <span class="block text-ink-muted">{{ __('gst.state_code') }}</span>
                <input type="text" maxlength="2" name="state_code" class="field-input w-20"
                       value="{{ old('state_code', $business->state_code) }}">
            </label>
            <button type="submit" class="btn-primary">{{ __('gst.save') }}</button>
        </div>
        <p class="mt-2 text-xs text-ink-muted">{{ __('gst.default_rate_hint') }} {{ __('gst.state_code_hint') }}</p>
        @error('default_gst_rate_percent') <p class="text-xs text-danger">{{ $message }}</p> @enderror
        @error('state_code') <p class="text-xs text-danger">{{ $message }}</p> @enderror

        <div class="card mt-4 overflow-x-auto">
            @if ($products->isEmpty())
                <p class="text-sm text-ink-muted">{{ __('gst.no_products') }}</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-ink-muted">
                            <th>{{ __('gst.product') }}</th>
                            <th>{{ __('gst.hsn') }}</th>
                            <th>{{ __('gst.rate') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $product)
                            <tr>
                                <td>{{ $product->name_en ?: $product->name_hi }}</td>
                                <td>
                                    <input type="text" maxlength="8" class="field-input w-32"
                                           name="products[{{ $product->id }}][hsn_code]" value="{{ $product->hsn_code }}">
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0" max="100" class="field-input w-24"
                                           name="products[{{ $product->id }}][gst_rate_percent]" value="{{ $product->gst_rate_percent }}">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <button type="submit" class="btn-primary mt-3">{{ __('gst.save') }}</button>
            @endif
        </div>
    </form>
</div>
@endsection
