@extends('layouts.app')

@section('title', config('app.name', 'VyaparBook'))

@section('content')
{{--
    The single cached shell for every offline-capable screen
    (docs/frontend-plan.md §1). React mounts here and routes client-side.

    Nothing tenant-specific is rendered server-side: this exact HTML is what
    the service worker caches and replays offline, so baking data into it
    would serve one shop stale figures belonging to a moment long past —
    or, worse, to a different business after a tenant switch.
--}}
<div id="app-root"
     data-user-name="{{ auth()->user()->name }}"
     data-locale="{{ app()->getLocale() }}">
    {{-- Replaced by React on mount. Visible only while the bundle loads, and
         it is what an offline visitor sees if the JS cache ever misses. --}}
    <div class="flex min-h-dvh items-center justify-center p-4">
        <p class="text-ink-muted">{{ __('ui.loading') }}</p>
    </div>
</div>
@endsection
