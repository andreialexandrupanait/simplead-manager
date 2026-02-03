<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'SimpleAd Manager' }}</title>
    {{-- Pre-Alpine sidebar state to prevent flash --}}
    <script>
        (function() {
            document.documentElement.classList.add('no-transitions');
            var open = localStorage.getItem('sidebarOpen') !== 'false';
            var w = open ? '16rem' : '4rem';
            var s = document.createElement('style');
            s.id = 'sidebar-init';
            s.textContent = '@media(min-width:1024px){[data-sidebar]{width:' + w + '!important}[data-main]{padding-left:' + w + '!important}}';
            document.head.appendChild(s);
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-gray-50">

    <div class="flex h-full"
         x-data="{
            sidebarOpen: localStorage.getItem('sidebarOpen') !== 'false',
            mobileSidebarOpen: false,
            init() {
                var el = document.getElementById('sidebar-init');
                if (el) el.remove();
                this.$nextTick(() => {
                    document.documentElement.classList.remove('no-transitions');
                });
            },
            toggleSidebar() {
                this.sidebarOpen = !this.sidebarOpen;
                localStorage.setItem('sidebarOpen', this.sidebarOpen);
            }
         }">

        {{-- Mobile overlay --}}
        <div x-show="mobileSidebarOpen" x-transition.opacity
             class="fixed inset-0 z-40 bg-black/50 lg:hidden"
             @click="mobileSidebarOpen = false">
        </div>

        {{-- Sidebar --}}
        <aside data-sidebar
               class="fixed inset-y-0 left-0 z-50 flex flex-col bg-[#1A1A2E] overflow-hidden transition-all duration-300 ease-in-out
                      lg:translate-x-0 w-64"
               :class="[
                   mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
                   sidebarOpen ? 'lg:w-64' : 'lg:w-16'
               ]">

            {{-- Logo area --}}
            <div class="flex h-16 items-center px-4 gap-3">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-3 min-w-0">
                    <div class="h-8 w-8 rounded-lg bg-purple-500 flex items-center justify-center text-white text-sm font-bold shrink-0">
                        SA
                    </div>
                    <span class="text-lg font-bold text-white whitespace-nowrap transition-all duration-300"
                          :class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">
                        SimpleAd Manager
                    </span>
                </a>
            </div>

            {{-- Dynamic sidebar content --}}
            <nav class="flex-1 overflow-y-auto scrollbar-thin px-3 py-4 transition-all duration-300"
                 :class="sidebarOpen ? '' : 'lg:px-2'">
                @if(isset($siteContext) && $siteContext)
                    <x-sidebar.site-sidebar :site="$siteContext" />
                @else
                    <x-sidebar.global-sidebar />
                @endif
            </nav>

            {{-- Sidebar footer --}}
            <div class="border-t border-white/10 p-4">
                <div class="flex items-center gap-3 transition-all duration-300"
                     :class="sidebarOpen ? '' : 'lg:justify-center'">
                    <div class="h-8 w-8 rounded-full bg-purple-500 flex items-center justify-center text-white text-sm font-medium shrink-0">
                        {{ auth()->user()->initials }}
                    </div>
                    <div class="text-sm text-white/80 whitespace-nowrap transition-all duration-300"
                         :class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">
                        {{ auth()->user()->name }}
                    </div>
                </div>
            </div>
        </aside>

        {{-- Main content --}}
        <div data-main
             class="flex flex-1 flex-col transition-all duration-300 ease-in-out"
             :class="sidebarOpen ? 'lg:pl-64' : 'lg:pl-16'">

            {{-- Header --}}
            <x-header.page-header :title="$title ?? 'SimpleAd Manager'" :site-context="$siteContext ?? null" />

            {{-- Page content --}}
            <main class="flex-1 p-6 lg:p-8">
                <div class="mx-auto max-w-7xl">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
