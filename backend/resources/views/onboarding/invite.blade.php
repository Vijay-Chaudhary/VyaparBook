@extends('onboarding.layout', ['step' => 3])

@section('title', __('onboarding.invite_staff') . ' — ' . config('app.name'))
@section('heading', __('onboarding.invite_staff'))
@section('subheading', __('onboarding.invite_hint'))

@section('step')
@if ($link)
    {{-- A freshly generated link, flashed back after POST. Kept selectable and
         copyable; the owner sends it via WhatsApp. --}}
    <div class="card mb-4">
        <p class="mb-2 text-sm text-ink-muted">{{ __('onboarding.invite_link_ready') }}</p>
        <input type="text" readonly value="{{ $link }}"
               onfocus="this.select()"
               class="field-input tabular text-sm" aria-label="{{ __('onboarding.invite_link_ready') }}">
    </div>
@endif

<form method="POST" action="{{ route('onboarding.invite') }}" class="card space-y-4" novalidate>
    @csrf

    <div>
        <label for="role" class="field-label">{{ __('onboarding.role') }}</label>
        <select id="role" name="role" class="field-input">
            <option value="salesman">{{ __('onboarding.salesman') }}</option>
            <option value="admin">{{ __('onboarding.admin') }}</option>
            <option value="accountant">{{ __('onboarding.accountant') }}</option>
        </select>
    </div>

    <button type="submit" class="btn-secondary w-full">{{ __('onboarding.generate_link') }}</button>
</form>

{{-- Inviting is optional — the owner can always do it later from settings, so
     "finish" leads straight into the app. --}}
<a href="{{ route('app') }}" class="btn-primary mt-4 block w-full">
    {{ $link ? __('onboarding.finish') : __('onboarding.skip_for_now') }}
</a>
@endsection
