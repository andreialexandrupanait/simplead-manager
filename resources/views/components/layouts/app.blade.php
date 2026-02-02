<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'SimpleAd Manager' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-gray-50">

    <div class="flex h-full" x-data="{ sidebarOpen: true, mobileSidebarOpen: false }">

        {{-- Mobile overlay --}}
        <div x-show="mobileSidebarOpen" x-transition.opacity
             class="fixed inset-0 z-40 bg-black/50 lg:hidden"
             @click="mobileSidebarOpen = false">
        </div>

        {{-- Sidebar --}}
        <aside class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-[#1A1A2E] transition-transform duration-200
                      lg:translate-x-0"
               :class="mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full'">

            {{-- Logo area --}}
            <div class="flex h-16 items-center px-6">
                <a href="{{ route('dashboard') }}" class="text-lg font-bold text-white">
                    SimpleAd Manager
                </a>
            </div>

            {{-- Dynamic sidebar content --}}
            <nav class="flex-1 overflow-y-auto scrollbar-thin px-3 py-4">
                @if(isset($siteContext) && $siteContext)
                    <x-sidebar.site-sidebar :site="$siteContext" />
                @else
                    <x-sidebar.global-sidebar />
                @endif
            </nav>

            {{-- Sidebar footer --}}
            <div class="border-t border-white/10 p-4">
                <div class="flex items-center gap-3">
                    <div class="h-8 w-8 rounded-full bg-purple-500 flex items-center justify-center text-white text-sm font-medium">
                        {{ auth()->user()->initials }}
                    </div>
                    <div class="text-sm text-white/80">{{ auth()->user()->name }}</div>
                </div>
            </div>
        </aside>

        {{-- Main content --}}
        <div class="flex flex-1 flex-col lg:pl-64">

            {{-- Header --}}
            <header class="sticky top-0 z-30 flex h-16 items-center gap-4 border-b bg-white px-6 shadow-sm">
                {{-- Mobile menu toggle --}}
                <button @click="mobileSidebarOpen = true" class="lg:hidden text-gray-500">
                    <x-icons.menu class="h-6 w-6" />
                </button>

                {{-- Breadcrumb (optional) --}}
                @if(isset($breadcrumb))
                    <div class="hidden lg:flex items-center text-sm text-gray-500">
                        {{ $breadcrumb }}
                    </div>
                @endif

                <div class="flex-1"></div>

                {{-- Search --}}
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" class="text-gray-400 hover:text-gray-600">
                        <x-icons.search class="h-5 w-5" />
                    </button>
                </div>

                {{-- Notifications --}}
                <button class="relative text-gray-400 hover:text-gray-600">
                    <x-icons.bell class="h-5 w-5" />
                    <span class="absolute -top-1 -right-1 h-2 w-2 rounded-full bg-red-500"></span>
                </button>

                {{-- Avatar dropdown --}}
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open" class="flex items-center gap-2">
                        <div class="h-8 w-8 rounded-full bg-purple-500 flex items-center justify-center text-white text-sm font-medium">
                            {{ auth()->user()->initials }}
                        </div>
                    </button>
                    <div x-show="open" @click.away="open = false" x-transition
                         class="absolute right-0 mt-2 w-48 rounded-lg bg-white py-1 shadow-lg ring-1 ring-black/5">
                        <a href="{{ route('settings.profile') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Profile</a>
                        <a href="{{ route('settings.general') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Settings</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Log out</button>
                        </form>
                    </div>
                </div>
            </header>

            {{-- Page content --}}
            <main class="flex-1 p-6 lg:p-8">
                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
