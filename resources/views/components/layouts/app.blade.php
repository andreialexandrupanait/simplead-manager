<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php $brandingLogo = app(\App\Services\SettingsService::class)->get('branding.logo'); @endphp
    <title>{{ $title ?? 'SimpleAd Manager' }}</title>
    @if($brandingLogo)
        <link rel="icon" type="image/png" href="{{ Storage::url($brandingLogo) }}">
    @endif
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
            },
            sidebarTooltip: { show: false, text: '', left: 0, top: 0 },
            showSidebarTooltip(el) {
                if (this.sidebarOpen || window.innerWidth < 1024) return;
                const rect = el.getBoundingClientRect();
                const text = el.querySelector('span')?.textContent?.trim() || '';
                this.sidebarTooltip = { show: true, text, left: Math.round(rect.right + 8), top: Math.round(rect.top + rect.height / 2) };
            },
            hideSidebarTooltip() { this.sidebarTooltip.show = false; }
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
                    <div class="h-8 w-8 rounded-lg bg-purple-500 flex items-center justify-center text-white text-sm font-bold shrink-0 overflow-hidden">
                        @if($brandingLogo)
                            <img src="{{ Storage::url($brandingLogo) }}" alt="" class="h-full w-full object-cover">
                        @else
                            SA
                        @endif
                    </div>
                    <span class="text-lg font-bold text-white whitespace-nowrap transition-all duration-300"
                          :class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">
                        {{ app(\App\Services\SettingsService::class)->get('app_name', 'SimpleAd Manager') }}
                    </span>
                </a>
            </div>

            {{-- Dynamic sidebar content --}}
            <nav class="flex-1 overflow-y-auto scrollbar-thin px-2 py-2 transition-all duration-300"
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

    {{-- Shared sidebar tooltip --}}
    <div x-show="sidebarTooltip.show" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 translate-x-1"
         x-transition:enter-end="opacity-100 translate-x-0"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100 translate-x-0"
         x-transition:leave-end="opacity-0 translate-x-1"
         class="pointer-events-none fixed z-[10000] -translate-y-1/2 whitespace-nowrap"
         :style="`left:${sidebarTooltip.left}px;top:${sidebarTooltip.top}px`">
        <div class="relative rounded-md bg-gray-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-lg">
            <span x-text="sidebarTooltip.text"></span>
            <div class="absolute right-full top-1/2 -translate-y-1/2 border-4 border-transparent border-r-gray-900"></div>
        </div>
    </div>

    @livewireScripts
</body>
</html>
