<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
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

            {{-- Sidebar bottom section --}}
            <div class="border-t border-white/10 mt-auto">
                {{-- Action buttons container --}}
                <div class="p-2 space-y-0.5">
                    {{-- Settings --}}
                    <a href="{{ route('settings.general') }}"
                       @mouseenter="showSidebarTooltip($el)"
                       @mouseleave="hideSidebarTooltip()"
                       class="flex items-center gap-3 px-3 py-1.5 text-sm font-medium text-white/70 hover:text-white hover:bg-sidebar-hover rounded-lg transition-all duration-200 {{ request()->routeIs('settings.*') ? 'bg-sidebar-hover text-white' : '' }}"
                       :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0'">
                        <x-icons.settings class="h-4 w-4 shrink-0" />
                        <span class="whitespace-nowrap transition-all duration-300"
                              :class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">
                            {{ __('Settings') }}
                        </span>
                    </a>

                    {{-- Profile --}}
                    <a href="{{ route('settings.profile') }}"
                       @mouseenter="showSidebarTooltip($el)"
                       @mouseleave="hideSidebarTooltip()"
                       class="flex items-center gap-3 px-3 py-1.5 text-sm font-medium text-white/70 hover:text-white hover:bg-sidebar-hover rounded-lg transition-all duration-200 {{ request()->routeIs('settings.profile') ? 'bg-sidebar-hover text-white' : '' }}"
                       :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0'">
                        <div class="h-5 w-5 rounded-full bg-purple-500 flex items-center justify-center text-white text-[10px] font-medium shrink-0">
                            {{ auth()->user()->initials }}
                        </div>
                        <span class="whitespace-nowrap transition-all duration-300"
                              :class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">
                            {{ __('Profile') }}
                        </span>
                    </a>

                    {{-- Logout --}}
                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <button type="submit"
                                @mouseenter="showSidebarTooltip($el)"
                                @mouseleave="hideSidebarTooltip()"
                                class="flex items-center gap-3 px-3 py-1.5 text-sm font-medium text-white/70 hover:text-white hover:bg-sidebar-hover rounded-lg transition-all duration-200 w-full"
                                :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0'">
                            <x-icons.log-out class="h-4 w-4 shrink-0" />
                            <span class="whitespace-nowrap transition-all duration-300"
                                  :class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">
                                {{ __('Log Out') }}
                            </span>
                        </button>
                    </form>
                </div>

                {{-- Live clock --}}
                <div class="px-3 py-2 text-center border-t border-white/10"
                     x-data="{
                         datetime: '',
                         updateClock() {
                             const now = new Date();
                             const day = String(now.getDate()).padStart(2, '0');
                             const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                             const month = months[now.getMonth()];
                             const year = now.getFullYear();
                             const hours = String(now.getHours()).padStart(2, '0');
                             const minutes = String(now.getMinutes()).padStart(2, '0');
                             const seconds = String(now.getSeconds()).padStart(2, '0');

                             this.datetime = `${day} ${month} ${year} | ${hours}:${minutes}:${seconds}`;
                         }
                     }"
                     x-init="updateClock(); setInterval(() => updateClock(), 1000)">

                    {{-- When expanded --}}
                    <div x-show="sidebarOpen" class="transition-all duration-300">
                        <div class="text-xs text-white/60 font-mono" x-text="datetime"></div>
                    </div>

                    {{-- When collapsed --}}
                    <div x-show="!sidebarOpen"
                         @mouseenter="showSidebarTooltip($el)"
                         @mouseleave="hideSidebarTooltip()"
                         class="lg:flex hidden items-center justify-center">
                        <x-icons.clock class="h-4 w-4 text-white/40" />
                        <span class="hidden" x-text="datetime"></span>
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

    {{-- Global toast notification --}}
    <div x-data="toast" x-on:notify.window="notify($event.detail)" x-show="show" x-cloak
         x-transition.duration.200ms
         class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg p-4 text-sm shadow-lg"
         :class="type === 'success' ? 'bg-green-50 text-green-800' : type === 'error' ? 'bg-red-50 text-red-800' : type === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-blue-50 text-blue-800'"
         x-text="message">
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
