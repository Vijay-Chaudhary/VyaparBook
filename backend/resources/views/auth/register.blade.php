@extends('layouts.app')

@section('title', __('onboarding.create_account') . ' — ' . config('app.name'))

@section('content')
<div class="mx-auto flex min-h-dvh max-w-md flex-col justify-center px-4 py-8">
    <h1 class="mb-1 text-2xl font-bold">{{ config('app.name', 'VyaparBook') }}</h1>
    <p class="mb-6 text-ink-muted">{{ __('onboarding.create_account') }}</p>

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-danger bg-red-50 p-3">
            <ul class="list-inside list-disc text-sm text-danger">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('register') }}" class="card space-y-4" novalidate>
        @csrf

        <div>
            <label for="name" class="field-label">{{ __('onboarding.name') }}</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus
                   autocomplete="name" class="field-input @error('name') border-danger @enderror">
            @error('name')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="email" class="field-label">{{ __('auth.email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required
                   autocomplete="username" inputmode="email"
                   class="field-input @error('email') border-danger @enderror">
            @error('email')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="field-label">{{ __('auth.password') }}</label>
            <input id="password" name="password" type="password" required minlength="8"
                   autocomplete="new-password"
                   class="field-input @error('password') border-danger @enderror">
            @error('password')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password_confirmation" class="field-label">{{ __('onboarding.confirm_password') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required
                   autocomplete="new-password" class="field-input">
        </div>

        {{-- DPDP consent: an unchecked box fails 'accepted' server-side. Never
             pre-checked — consent must be an affirmative action. --}}
        <label class="flex items-start gap-2">
            <input type="checkbox" name="consent" value="1" required
                   class="mt-1 h-5 w-5 shrink-0 rounded border-hairline">
            <span class="text-sm">{{ __('onboarding.consent_label') }}</span>
        </label>

        <button type="submit" class="btn-primary w-full">{{ __('onboarding.create_account') }}</button>

        <a href="{{ route('login') }}" class="block py-2 text-center text-sm text-brand">
            {{ __('onboarding.have_account') }}
        </a>
    </form>
</div>
@endsection
