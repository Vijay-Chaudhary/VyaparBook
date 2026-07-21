{{--
    Shared header for authenticated Blade pages.
    Included only when a user is logged in (see layouts/app.blade.php).
--}}
<header class="border-b border-hairline bg-surface">
    <div class="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4 py-3">
        <a href="{{ route('app') }}" class="flex min-h-tap items-center font-bold">
            {{ config('app.name', 'VyaparBook') }}
        </a>

        <div class="flex items-center gap-3">
            <span class="hidden text-sm text-ink-muted sm:inline">{{ auth()->user()->name }}</span>

            {{-- POST, not a link: logout changes state, so it must not be
                 reachable by a GET that a prefetcher could fire. --}}
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn-secondary px-3">{{ __('auth.sign_out') }}</button>
            </form>
        </div>
    </div>
</header>
