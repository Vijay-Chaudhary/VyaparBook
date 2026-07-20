@extends('onboarding.layout', ['step' => 2])

@section('title', __('onboarding.choose_template') . ' — ' . config('app.name'))
@section('heading', __('onboarding.choose_template'))
@section('subheading', __('onboarding.choose_template_hint'))

@section('step')
@if ($alreadySeeded)
    {{-- Seeding is one-time. If a catalog exists, do not offer a doomed second
         seed — let them move on. --}}
    <div class="card">
        <p class="text-ink-muted">{{ __('onboarding.already_seeded') }}</p>
        <a href="{{ route('onboarding.invite') }}" class="btn-primary mt-3 w-full">
            {{ __('onboarding.continue') }}
        </a>
    </div>
@else
    <form method="POST" action="{{ route('onboarding.template') }}" class="space-y-2" novalidate>
        @csrf

        <fieldset class="space-y-2">
            <legend class="sr-only">{{ __('onboarding.choose_template') }}</legend>

            @foreach ($templates as $i => $template)
                {{-- Whole card is the label, so the entire row is a 44px+ tap
                     target, not just the radio dot (SKILL.md §2). --}}
                <label class="card flex min-h-tap cursor-pointer items-center gap-3
                              has-[:checked]:border-brand has-[:checked]:bg-brand/5">
                    <input type="radio" name="template" value="{{ $template['slug'] }}"
                           @checked($i === 0) class="h-5 w-5 shrink-0">
                    <span class="font-medium">{{ $template['label'] }}</span>
                </label>
            @endforeach
        </fieldset>

        <button type="submit" class="btn-primary mt-2 w-full">{{ __('onboarding.continue') }}</button>
    </form>
@endif
@endsection
