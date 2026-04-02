@props(['title' => null])

<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? ($brandingAppName ?? 'SimpleAd Manager') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-white">

    <div class="flex h-full min-h-screen">
        {{-- LEFT: Image Slideshow (hidden on mobile) --}}
        @php
            $hasImages = collect($slideshowSlides ?? [])->contains(fn ($s) => !empty($s['image']));
        @endphp

        <div class="hidden md:flex md:w-1/2 md:flex-col relative overflow-hidden bg-[#1A1A2E] rounded-2xl m-3"
             x-data="slideshow({{ Js::from($slideshowSlides ?? []) }})">

            @if($hasImages)
                {{-- First image rendered server-side as fallback --}}
                @php $firstSlide = ($slideshowSlides ?? [])[0] ?? null; @endphp
                @if($firstSlide && !empty($firstSlide['image']))
                    <img src="{{ $firstSlide['image'] }}"
                         alt="{{ $firstSlide['alt'] ?? '' }}"
                         class="absolute inset-0 h-full w-full object-cover"
                         x-show="false"
                         loading="eager">
                @endif

                {{-- Alpine-powered crossfade slides --}}
                <template x-for="(slide, index) in slides" :key="index">
                    <img :src="slide.image"
                         :alt="slide.alt"
                         class="absolute inset-0 h-full w-full object-cover transition-opacity duration-700"
                         :class="current === index ? 'opacity-100' : 'opacity-0'"
                         loading="eager">
                </template>
            @endif

            {{-- Gradient overlay --}}
            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/30 to-transparent"></div>

            {{-- Content overlay --}}
            <div class="relative z-10 flex h-full flex-col justify-end p-8">
                {{-- Slide text --}}
                <div class="mb-6">
                    <template x-for="(slide, index) in slides" :key="'text-' + index">
                        <div x-show="current === index"
                             x-transition:enter="transition ease-out duration-500"
                             x-transition:enter-start="opacity-0 translate-y-4"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-300"
                             x-transition:leave-start="opacity-100 translate-y-0"
                             x-transition:leave-end="opacity-0 -translate-y-4">
                            <h2 class="text-2xl font-bold text-white mb-2" x-text="slide.title"></h2>
                            <p class="text-sm text-white/70 max-w-md" x-text="slide.subtitle"></p>
                        </div>
                    </template>
                </div>

                {{-- Navigation controls (compact) --}}
                <div class="flex items-center gap-3">
                    {{-- Play/Pause --}}
                    <button @click="togglePlay()"
                            class="flex h-8 w-8 items-center justify-center rounded-full bg-white/15 text-white backdrop-blur-sm transition hover:bg-white/25"
                            :aria-label="playing ? 'Pause slideshow' : 'Play slideshow'">
                        <svg x-show="playing" class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z"/>
                        </svg>
                        <svg x-show="!playing" class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M8 5v14l11-7z"/>
                        </svg>
                    </button>

                    {{-- Counter --}}
                    <span class="text-sm text-white/60">
                        <span x-text="current + 1"></span> / <span x-text="slides.length"></span>
                    </span>

                    {{-- Prev --}}
                    <button @click="prev(); if (playing) _startTimer()"
                            class="flex h-8 w-8 items-center justify-center rounded-full bg-white/15 text-white backdrop-blur-sm transition hover:bg-white/25"
                            aria-label="Previous slide">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </button>

                    {{-- Next --}}
                    <button @click="next(); if (playing) _startTimer()"
                            class="flex h-8 w-8 items-center justify-center rounded-full bg-white/15 text-white backdrop-blur-sm transition hover:bg-white/25"
                            aria-label="Next slide">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </button>
                </div>

                {{-- Unsplash attribution --}}
                @if($hasImages)
                    <div class="mt-3">
                        <template x-for="(slide, index) in slides" :key="'attr-' + index">
                            <p x-show="current === index && slide.author"
                               class="text-xs text-white/30 transition-opacity duration-300">
                                Photo by <a :href="slide.author_url + '?utm_source=simplead_manager&utm_medium=referral'"
                                            class="underline hover:text-white/50"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            x-text="slide.author"></a>
                                on <a href="https://unsplash.com/?utm_source=simplead_manager&utm_medium=referral"
                                      class="underline hover:text-white/50"
                                      target="_blank"
                                      rel="noopener noreferrer">Unsplash</a>
                            </p>
                        </template>
                    </div>
                @endif
            </div>
        </div>

        {{-- RIGHT: Auth Form --}}
        <div class="flex flex-1 items-center justify-center bg-white px-4 py-8 md:px-12">
            <div class="w-full max-w-md">
                {{-- App Name / Logo --}}
                @php
                    $brandingLogo = app(\App\Services\SettingsService::class)->get('branding.logo');
                @endphp
                <div class="mb-8">
                    @if($brandingLogo)
                        <svg class="w-44 h-auto" viewBox="0 400 1000 210" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                            <defs>
                                <linearGradient id="b" x1="92.945" y1="355.682" x2="252.233" y2="557.231" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#014df9"/><stop offset="1" stop-color="#ecc0fd"/></linearGradient>
                                <linearGradient id="c" x1="47.005" y1="391.99" x2="206.293" y2="593.538" xlink:href="#b"/>
                                <linearGradient id="d" x1="-27.074" y1="450.535" x2="132.215" y2="652.084" xlink:href="#b"/>
                                <linearGradient id="e" x1="18.866" y1="414.228" x2="178.155" y2="615.777" xlink:href="#b"/>
                            </defs>
                            <g><path d="m249.21,521.466l24.752-4.601c1.587,13.487,12.693,22.372,27.766,22.372,13.486,0,21.895-6.188,21.895-14.914,0-6.981-5.553-11.107-18.881-13.803l-15.708-3.015c-24.91-4.76-36.017-14.756-36.017-32.526,0-22.213,18.881-36.493,45.06-36.493,23.641,0,41.887,12.852,44.743,32.526l-24.275,5.395c-1.428-10.948-9.837-17.929-20.785-17.929-11.106,0-18.088,5.871-18.088,13.963,0,6.822,5.077,10.63,16.818,12.851l16.025,3.015c25.703,4.284,37.444,14.755,37.444,33.16,0,23.641-20.309,37.921-49.344,37.921-28.083,0-48.868-13.486-51.407-37.921Z" fill="#030d4a"/><path d="m361.859,449.274c0-8.409,5.712-13.962,14.28-13.962s14.438,5.553,14.438,13.962c0,8.251-5.87,13.804-14.438,13.804s-14.28-5.553-14.28-13.804Zm2.221,106.146v-81.235h24.117v81.235h-24.117Z" fill="#030d4a"/><path d="m406.442,555.42v-81.235h19.515l3.649,14.756h.159c4.443-10.789,15.232-17.135,27.766-17.135,12.693,0,21.102,6.188,24.593,17.77h.159c6.188-12.058,16.977-17.77,29.353-17.77,16.025,0,25.545,10.789,25.545,29.194v54.421h-24.275v-49.026c0-8.886-4.919-14.121-13.01-14.121-10.154,0-16.025,7.457-16.025,18.563v44.584h-24.117v-49.026c0-8.886-4.919-14.121-13.169-14.121-9.996,0-15.866,7.457-15.866,18.563v44.584h-24.275Z" fill="#030d4a"/><path d="m554.154,588.74v-114.555h20.309l3.649,15.549h.159c5.712-11.741,15.549-17.929,28.401-17.929,21.261,0,36.175,17.135,36.175,42.997,0,25.704-14.914,42.998-36.175,42.998-12.534,0-22.371-6.188-28.083-17.929h-.159v48.868h-24.275Zm64.417-73.937c0-13.01-8.568-22.53-20.626-22.53-11.9,0-20.468,9.678-20.468,22.53,0,12.693,8.568,22.372,20.468,22.372,12.058,0,20.626-9.678,20.626-22.372Z" fill="#030d4a"/><path d="m657.601,555.42v-117.728h24.275v117.728h-24.275Z" fill="#030d4a"/><path d="m696.629,514.326c-.159-25.703,17.77-43.315,43.791-43.315,25.386,0,42.046,16.818,42.204,41.252,0,3.173-.476,6.664-1.11,9.203h-59.975c1.428,11.741,11.265,19.357,24.592,19.357,10.154,0,19.674-4.601,24.434-11.106l13.804,10.63c-6.505,10.789-21.737,18.246-39.19,18.246-28.559,0-48.551-18.087-48.551-44.267Zm61.403-6.822c-.793-12.059-7.616-19.198-17.612-19.198-10.154,0-17.612,7.774-19.04,19.198h36.651Z" fill="#030d4a"/><path d="m794.521,534.635c0-13.962,12.058-24.116,36.334-26.655l15.708-1.904c3.808-.317,6.347-2.539,6.347-5.871,0-6.981-6.029-11.582-14.756-11.582-9.837,0-16.659,6.981-17.453,15.073l-23.641-3.649c1.428-16.818,17.135-29.035,40.459-29.035,23.8,0,39.19,12.535,39.19,32.843v31.415c0,3.649,1.587,5.87,4.125,5.87,2.063,0,4.601-1.428,6.347-3.649l5.712,12.852c-4.918,4.919-13.327,7.457-20.785,7.457-9.837,0-17.452-6.029-18.563-13.486l-.317-2.856h-.159c-5.236,9.678-15.866,16.342-29.511,16.342-16.977,0-29.035-9.202-29.035-23.165Zm57.912-12.376v-3.332l-15.549,1.746c-13.487,1.586-18.088,5.87-18.088,11.106,0,5.553,5.871,9.361,13.963,9.361,11.423,0,19.674-7.933,19.674-18.881Z" fill="#030d4a"/><path d="m894.954,514.802c0-25.862,14.915-42.997,36.175-42.997,12.534,0,22.372,6.188,28.084,17.929h.159v-52.042h24.276v117.728h-20.309l-3.649-15.549h-.159c-5.712,11.741-15.708,17.929-28.401,17.929-21.261,0-36.175-17.294-36.175-42.998Zm65.369,0c0-12.852-8.568-22.53-20.626-22.53-11.899,0-20.626,9.678-20.626,22.53s8.727,22.53,20.626,22.53c12.059,0,20.626-9.837,20.626-22.53Z" fill="#030d4a"/></g>
                            <g><polygon points="201.669 504.641 201.669 411.26 157.452 411.26 123.126 475.863 201.669 504.641" fill="url(#b)"/><path d="m109.017,411.26h-46.332c-25.588,0-46.332,20.743-46.332,46.332h0c0,20.921,13.87,38.593,32.914,44.347l108.185-90.679h-48.436Z" fill="url(#c)"/><polygon points="16.353 494.089 16.353 587.469 60.56 587.469 94.887 522.867 16.353 494.089" fill="url(#d)"/><path d="m109.006,587.469h46.332c25.588,0,46.332-20.743,46.332-46.332h0c0-20.924-13.874-38.597-32.921-44.35l-108.188,90.681h48.446Z" fill="url(#e)"/></g>
                        </svg>
                    @else
                        <h1 class="text-xl font-bold text-gray-900">{{ $brandingAppName ?? 'SimpleAd Manager' }}</h1>
                    @endif
                </div>

                {{-- Login / Sign-up Tabs --}}
                @php
                    $isLogin = request()->routeIs('login');
                    $isRegister = request()->routeIs('register');
                @endphp
                @if(Route::has('register'))
                    <div class="mb-8 flex gap-1 rounded-full bg-gray-100 p-1 w-fit">
                        <a href="{{ route('login') }}"
                           class="rounded-full px-5 py-1.5 text-sm font-medium transition {{ $isLogin ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                            Login
                        </a>
                        <a href="{{ route('register') }}"
                           class="rounded-full px-5 py-1.5 text-sm font-medium transition {{ $isRegister ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                            Sign-up
                        </a>
                    </div>
                @endif

                {{-- Form content --}}
                {{ $slot }}
            </div>
        </div>
    </div>

    @livewireScripts
</body>
</html>
