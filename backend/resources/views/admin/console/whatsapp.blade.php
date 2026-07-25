{{-- resources/views/admin/console/whatsapp.blade.php --}}
@extends('admin.layout')

@section('console')
@php
    /** Secrets are deliberately never bound into any value attribute below. */
    $sourceLabel = fn (string $key) => __('admin.whatsapp_source_' . $sources[$key]);
@endphp

<div class="mb-4 flex items-center justify-between">
    <h1 class="text-xl font-bold">{{ __('admin.whatsapp') }}</h1>
    <span class="text-sm text-ink-muted">
        {{ __('admin.whatsapp_live_driver') }}:
        <strong class="{{ $liveDriver === 'cloud_api' ? 'text-success' : '' }}">{{ $liveDriver }}</strong>
    </span>
</div>

<p class="mb-4 text-sm text-ink-muted">{{ __('admin.whatsapp_intro') }}</p>

@if (session('whatsapp_test'))
    @php $test = session('whatsapp_test'); @endphp
    <div role="status" class="mb-4 rounded-lg border p-3 text-sm {{ $test['ok'] ? 'border-success text-success' : 'border-danger text-danger' }}">
        @if ($test['ok'])
            {{ __('admin.whatsapp_test_ok') }} <code>{{ $test['message'] }}</code>
        @else
            {{ __('admin.whatsapp_test_failed') }}
            @if ($test['code']) <code>{{ $test['code'] }}</code> @endif
            {{ $test['message'] }}
        @endif
    </div>
@endif

<form method="POST" action="{{ route('admin.console.whatsapp.save') }}" class="card space-y-3">
    @csrf

    <label class="block text-sm">
        <span class="block text-ink-muted">{{ __('admin.whatsapp_driver') }} <em class="text-xs">({{ $sourceLabel('driver') }})</em></span>
        <select name="driver" class="field-input">
            <option value="log" @selected(($settings->driver ?? 'log') === 'log')>{{ __('admin.whatsapp_driver_log') }}</option>
            <option value="cloud_api" @selected(($settings->driver ?? null) === 'cloud_api')>{{ __('admin.whatsapp_driver_cloud') }}</option>
        </select>
        @error('driver') <span class="text-xs text-danger">{{ $message }}</span> @enderror
    </label>

    @foreach (['api_version', 'phone_number_id', 'template'] as $field)
        <label class="block text-sm">
            <span class="block text-ink-muted">
                {{ __('admin.whatsapp_' . $field) }} <em class="text-xs">({{ $sourceLabel($field) }})</em>
            </span>
            <input type="text" name="{{ $field }}" class="field-input" value="{{ old($field, $settings->{$field} ?? '') }}">
        </label>
    @endforeach
    <p class="text-xs text-ink-muted">{{ __('admin.whatsapp_template_hint') }}</p>

    {{-- Secrets: write-only. No value attribute, ever — a secret a page can
         render is a secret that leaks through a screenshot or a cached page. --}}
    @foreach (['token', 'verify_token', 'app_secret'] as $secret)
        <label class="block text-sm">
            <span class="block text-ink-muted">
                {{ __('admin.whatsapp_' . $secret) }}
                <em class="text-xs">
                    ({{ $hasSecret[$secret] ? __('admin.whatsapp_secret_set') : __('admin.whatsapp_secret_unset') }},
                    {{ $sourceLabel($secret) }})
                </em>
            </span>
            <input type="password" name="{{ $secret }}" class="field-input" autocomplete="new-password">
        </label>
    @endforeach

    <button type="submit" class="btn-primary">{{ __('admin.whatsapp_save') }}</button>
</form>

<div class="card mt-4">
    <h2 class="mb-2 font-semibold">{{ __('admin.whatsapp_test_heading') }}</h2>
    <p class="mb-3 text-sm text-ink-muted">{{ __('admin.whatsapp_test_intro') }}</p>
    <form method="POST" action="{{ route('admin.console.whatsapp.test') }}" class="flex flex-wrap items-end gap-3">
        @csrf
        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('admin.whatsapp_test_to') }}</span>
            <input type="text" name="to" class="field-input" placeholder="9876543210" value="{{ old('to') }}">
        </label>
        <button type="submit" class="btn-secondary">{{ __('admin.whatsapp_test_send') }}</button>
    </form>
    @error('to') <p class="mt-2 text-xs text-danger">{{ $message }}</p> @enderror
</div>
@endsection
