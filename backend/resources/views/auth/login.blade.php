@extends('layouts.app')

@section('title', __('auth.sign_in') . ' — ' . config('app.name'))

@section('content')
<div class="mx-auto flex min-h-dvh max-w-md flex-col justify-center px-4 py-8">
    <h1 class="mb-1 text-2xl font-bold">{{ config('app.name', 'VyaparBook') }}</h1>
    <p class="mb-6 text-ink-muted">{{ __('auth.sign_in_subtitle') }}</p>

    {{--
        Error summary with anchors to each field (SKILL.md §8 error-summary).
        role="alert" so a screen reader announces the failure rather than
        leaving the user to wonder why nothing happened.
    --}}
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-danger bg-red-50 p-3">
            <p class="text-sm font-medium text-danger">{{ __('auth.fix_errors') }}</p>
            <ul class="mt-1 list-inside list-disc text-sm text-danger">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="card space-y-4" novalidate>
        @csrf

        <div>
            {{-- Visible label, not a placeholder: placeholders vanish on focus,
                 leaving the user with no idea what the field wanted. --}}
            <label for="email" class="field-label">{{ __('auth.email') }}</label>
            <input id="email"
                   name="email"
                   type="email"
                   value="{{ old('email') }}"
                   required
                   autofocus
                   {{-- Correct mobile keyboard + system autofill. --}}
                   autocomplete="username"
                   inputmode="email"
                   class="field-input @error('email') border-danger @enderror"
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')
                <p id="email-error" class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="field-label">{{ __('auth.password') }}</label>
            <input id="password"
                   name="password"
                   type="password"
                   required
                   autocomplete="current-password"
                   class="field-input @error('password') border-danger @enderror"
                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
            @error('password')
                <p id="password-error" class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex min-h-tap items-center gap-2">
            <input type="checkbox" name="remember" value="1" class="h-5 w-5 rounded border-hairline">
            <span class="text-base">{{ __('auth.remember_me') }}</span>
        </label>

        <button type="submit" class="btn-primary w-full">{{ __('auth.sign_in') }}</button>

        <a href="{{ route('register') }}" class="block py-2 text-center text-sm text-brand">
            {{ __('onboarding.create_account') }}
        </a>
    </form>
</div>
@endsection
