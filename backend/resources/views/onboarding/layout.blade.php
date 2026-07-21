@extends('layouts.app', ['bare' => true])

{{-- Shared frame for onboarding steps: a narrow, centred column with a step
     indicator, so a non-technical owner always knows where they are in the flow
     (SKILL.md §8 multi-step-progress).

     bare = true drops the app header: its "home" link points at /app, which a
     user mid-onboarding has no business landing on, and its sign-out button
     sitting beside the flow's own buttons is clutter (and an ambiguous second
     submit target). A small "sign out" is offered at the foot instead. --}}

@section('content')
<div class="mx-auto flex min-h-dvh max-w-md flex-col justify-center px-4 py-8">
    <div class="mb-6 text-center font-bold text-brand">{{ config('app.name', 'VyaparBook') }}</div>

    @isset($step)
        <ol class="mb-6 flex items-center gap-2" aria-label="{{ $step }}/3">
            @for ($i = 1; $i <= 3; $i++)
                <li class="h-1.5 flex-1 rounded-full {{ $i <= $step ? 'bg-brand' : 'bg-hairline' }}"
                    @if($i === $step) aria-current="step" @endif></li>
            @endfor
        </ol>
    @endisset

    <h1 class="mb-1 text-2xl font-bold">@yield('heading')</h1>
    @hasSection('subheading')
        <p class="mb-6 text-ink-muted">@yield('subheading')</p>
    @else
        <div class="mb-6"></div>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-danger bg-red-50 p-3">
            <ul class="list-inside list-disc text-sm text-danger">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('step')

    {{-- Escape hatch, foot of page: a way out that is not mixed in with the
         flow's own primary actions. --}}
    <form method="POST" action="{{ route('logout') }}" class="mt-6 text-center">
        @csrf
        <button type="submit" class="min-h-tap text-sm text-ink-muted">{{ __('auth.sign_out') }}</button>
    </form>
</div>
@endsection
