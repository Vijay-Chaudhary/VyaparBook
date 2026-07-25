{{-- resources/views/beats/index.blade.php --}}
@extends('layouts.app')

@section('title', __('beats.title') . ' — ' . config('app.name'))

@section('content')
<div class="mx-auto max-w-5xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('beats.heading') }}</h1>
        <a href="{{ route('reports.dashboard', ['business' => $businessId]) }}"
           class="text-sm text-brand">{{ __('reminders.back_to_dashboard') }}</a>
    </header>

    <p class="mb-3 text-xs text-ink-muted">{{ __('beats.intro') }}</p>

    @if (session('status'))
        <p class="card mb-3 text-sm">{{ session('status') }}</p>
    @endif

    {{-- Add a beat --}}
    <form method="POST" action="{{ route('beats.store') }}" class="card flex flex-wrap items-end gap-3">
        @csrf
        <input type="hidden" name="business" value="{{ $businessId }}">
        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('beats.name') }}</span>
            <input type="text" name="name" maxlength="80" class="field-input" value="{{ old('name') }}">
        </label>
        <fieldset class="text-sm">
            <legend class="block text-ink-muted">{{ __('beats.weekdays') }}</legend>
            <div class="flex flex-wrap gap-2">
                @foreach (__('beats.days') as $iso => $label)
                    <label class="flex items-center gap-1">
                        <input type="checkbox" name="weekdays[]" value="{{ $iso }}">
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </fieldset>
        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('beats.assigned_to') }}</span>
            <select name="assigned_user_id" class="field-input">
                <option value="">{{ __('beats.unassigned') }}</option>
                @foreach ($staff as $member)
                    <option value="{{ $member->user_id }}">{{ $member->user?->name }} ({{ $member->role }})</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn-primary">{{ __('beats.add') }}</button>
        @error('name') <p class="w-full text-xs text-danger">{{ $message }}</p> @enderror
        @error('weekdays') <p class="w-full text-xs text-danger">{{ $message }}</p> @enderror
    </form>

    @if ($beats->isEmpty())
        <p class="card mt-4 text-sm text-ink-muted">{{ __('beats.no_beats') }}</p>
    @endif

    @foreach ($beats as $beat)
        <div class="card mt-4">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="font-semibold">
                        {{ $beat->name }}
                        @if (in_array($today, $beat->weekdays ?? [], true))
                            {{-- The one thing an owner scans this page for. --}}
                            <span class="ml-1 text-xs text-success">{{ __('beats.runs_today') }}</span>
                        @endif
                    </h2>
                    <p class="text-xs text-ink-muted">
                        @foreach ($beat->weekdays ?? [] as $iso)
                            {{ __('beats.days')[$iso] ?? $iso }}@if (! $loop->last), @endif
                        @endforeach
                        ·
                        {{ $staff->firstWhere('user_id', $beat->assigned_user_id)?->user?->name ?? __('beats.unassigned') }}
                    </p>
                </div>
                <form method="POST" action="{{ route('beats.archive', ['beat' => $beat->id]) }}"
                      onsubmit="return confirm('{{ __('beats.archive') }}?')">
                    @csrf
                    <input type="hidden" name="business" value="{{ $businessId }}">
                    <button type="submit" class="text-xs text-danger">{{ __('beats.archive') }}</button>
                </form>
            </div>

            <form method="POST" action="{{ route('beats.customers', ['beat' => $beat->id]) }}">
                @csrf
                <input type="hidden" name="business" value="{{ $businessId }}">
                <p class="mb-2 text-xs text-ink-muted">{{ __('beats.customers_hint') }}</p>
                @php $onBeat = $beat->beatCustomers->pluck('customer_id')->all(); @endphp
                <div class="grid gap-1 md:grid-cols-3">
                    @foreach ($customers as $customer)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="customers[]" value="{{ $customer->id }}"
                                   @checked(in_array($customer->id, $onBeat, true))>
                            {{ $customer->name }} <span class="text-xs text-ink-muted">{{ $customer->village }}</span>
                        </label>
                    @endforeach
                </div>
                <button type="submit" class="btn-primary mt-3">{{ __('beats.save') }}</button>
            </form>
        </div>
    @endforeach
</div>
@endsection
