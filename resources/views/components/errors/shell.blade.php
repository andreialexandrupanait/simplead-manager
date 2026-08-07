@props([
    'code',
    'heading',
    'message',
])

@php
    // An error page must never be the thing that fails. @vite() throws when the build manifest is
    // missing, which turns every 403 into a 500 — that is how this surfaced: adding a 403 page made
    // four tests report 500 instead. The manifest is always there in production, but "always" is
    // exactly the assumption an error page is not allowed to make, since the moment it is wrong is
    // the moment this page is what people see.
    $hasBuiltAssets = is_file(public_path('build/manifest.json'));
@endphp

{{--
    The five error pages were four copies of the same twenty lines, each with its English hardcoded
    and `lang="en"` pinned regardless of who was reading. One shell instead, and the text goes
    through the translator like everything else in the app.

    The rail colour is the background on purpose: an error page that still looks like the product
    reads as "the product had a problem", not "the internet had a problem".
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }} — SimpleAd Manager</title>
    @if($hasBuiltAssets)
        @vite(['resources/css/app.css'])
    @else
        {{-- Enough to keep the page readable and on-brand without the build. --}}
        <style>
            :root { color-scheme: dark; }
            body { margin: 0; min-height: 100%; background: #1A1A1A; color: #FAFAFA;
                   font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
            .wrap { min-height: 100vh; display: flex; flex-direction: column; align-items: center;
                    justify-content: center; text-align: center; padding: 3rem 1rem; }
            .code { font-size: 3.75rem; font-weight: 700; color: #2271b1; margin: 0; }
            h1 { font-size: 1.5rem; font-weight: 600; margin: 1rem 0 0; }
            p { color: #9CA3AF; margin: .5rem 0 0; max-width: 28rem; }
            a { display: inline-block; margin-top: 2rem; padding: .625rem 1.25rem; border-radius: .5rem;
                background: #1d6098; color: #fff; text-decoration: none; font-size: .875rem; font-weight: 500; }
        </style>
    @endif
</head>
<body class="h-full bg-sidebar">
    <div class="wrap flex min-h-full flex-col items-center justify-center px-4 py-12 text-center">
        <p class="code text-6xl font-bold text-accent-500">{{ $code }}</p>
        <h1 class="mt-4 text-2xl font-semibold text-white">{{ $heading }}</h1>
        <p class="mt-2 max-w-md text-gray-400">{{ $message }}</p>
        <a href="/" class="mt-8 inline-flex items-center gap-2 rounded-lg bg-accent-600 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-accent-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-400 focus-visible:ring-offset-2 focus-visible:ring-offset-[#1A1A1A]">
            &larr; {{ __('Back to the dashboard') }}
        </a>
    </div>
</body>
</html>
