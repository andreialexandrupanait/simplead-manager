<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'SimpleAd Manager' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-[#1A1A2E]">

    <div class="flex min-h-full items-center justify-center px-4 py-12">
        <div class="w-full max-w-md">
            {{-- Logo --}}
            <div class="mb-8 text-center">
                <h1 class="text-2xl font-bold text-white">SimpleAd Manager</h1>
            </div>

            {{-- Card --}}
            <div class="rounded-xl bg-white p-8 shadow-xl">
                {{ $slot }}
            </div>
        </div>
    </div>

</body>
</html>
