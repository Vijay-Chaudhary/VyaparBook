@extends('layouts.app', ['bare' => true])

{{-- The platform console frame. bare=true drops the shopkeeper header (its home
     link points at /app, which an operator has no business landing on); this
     supplies a distinct console header instead, so it is always visually clear
     you are in the cross-tenant admin surface, not a shop. --}}

@section('content')
<div class="min-h-dvh">
    <header class="border-b border-hairline bg-surface">
        <div class="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4 py-3">
            <a href="{{ route('admin.console') }}" class="flex min-h-tap items-center gap-2 font-bold">
                {{ __('admin.console') }}
            </a>

            <div class="flex items-center gap-3">
                <span class="hidden text-sm text-ink-muted sm:inline">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn-secondary px-3">{{ __('admin.sign_out') }}</button>
                </form>
            </div>
        </div>
    </header>

    <div class="mx-auto max-w-4xl px-4 py-6">
        {{-- Flash: success and error land here on every action redirect. --}}
        @if (session('console_status'))
            <div role="status" class="mb-4 rounded-lg border border-success bg-green-50 p-3 text-sm text-success">
                {{ __('admin.flash_' . session('console_status')) }}
            </div>
        @endif

        @if (session('console_error'))
            <div role="alert" class="mb-4 rounded-lg border border-danger bg-red-50 p-3 text-sm text-danger">
                {{ __('admin.error_' . session('console_error')) }}
            </div>
        @endif

        @yield('console')
    </div>
</div>
@endsection
