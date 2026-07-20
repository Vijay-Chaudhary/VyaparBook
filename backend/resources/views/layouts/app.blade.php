{{--
    Base layout for every Blade page.

    `lang` is driven by the app locale (Hindi default, PRD §16) so screen
    readers pick the right pronunciation and the browser hyphenates correctly.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    {{-- No maximum-scale / user-scalable=no: disabling zoom breaks the app for
         low-vision users and is a WCAG failure. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1D4ED8">

    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icon-192.png" sizes="192x192" type="image/png">
    <link rel="apple-touch-icon" href="/icon-192.png">

    <title>@yield('title', config('app.name', 'VyaparBook'))</title>

    {{-- Preload the Devanagari face: it is the default language's font and
         carries ₹, so it is on the critical path for first paint. --}}
    <link rel="preload" href="/fonts/noto-devanagari-deva.woff2" as="font" type="font/woff2" crossorigin>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-dvh">
    {{-- Keyboard users must be able to skip repeated navigation (SKILL.md §1
         skip-links). Visible only once focused. --}}
    <a href="#main"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50
              focus:rounded-lg focus:bg-brand focus:px-4 focus:py-2 focus:text-white">
        {{ __('ui.skip_to_content') }}
    </a>

    {{-- $bare drops the app chrome — used by onboarding, which has its own
         minimal frame and must not offer the /app header before a business
         exists. --}}
    @includeWhen(auth()->check() && ! ($bare ?? false), 'partials.header')

    <main id="main" tabindex="-1">
        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
