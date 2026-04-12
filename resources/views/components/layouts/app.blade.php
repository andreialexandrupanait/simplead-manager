<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full {{ auth()->user()?->theme === 'dark' ? 'dark' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $settingsService = app(\App\Services\SettingsService::class);
        $brandingFavicon = $settingsService->get('branding.favicon');
        $brandingLogo = $settingsService->get('branding.logo');
        $accentColor = $settingsService->get('branding.accent_color');
    @endphp
    <title>{{ $settingsService->get('app_name', 'SimpleAd Manager') }}{{ isset($title) ? ' - ' . $title : '' }}</title>
    @if($brandingFavicon)
        <link rel="icon" type="image/png" href="{{ Storage::url($brandingFavicon) }}">
    @endif
    @if($accentColor)
        <style nonce="{{ csp_nonce() }}">:root { {!! \App\Helpers\AccentColorHelper::generateCssVariables($accentColor) !!} }</style>
    @endif
    {{-- Pre-Alpine: dark mode + sidebar state to prevent flash --}}
    <script nonce="{{ csp_nonce() }}">
        (function() {
            // Dark mode
            var theme = localStorage.getItem('theme') || '{{ auth()->user()?->theme ?? 'light' }}';
            if (theme === 'dark') document.documentElement.classList.add('dark');

            document.documentElement.classList.add('no-transitions');
            var open = localStorage.getItem('sidebarOpen') !== 'false';
            var w = open ? '16rem' : '4rem';
            var s = document.createElement('style');
            s.id = 'sidebar-init';
            var rules = '@media(min-width:1024px){[data-sidebar]{width:' + w + '!important}[data-main]{padding-left:' + w + '!important}';
            if (!open) rules += '[data-sidebar] span{opacity:0!important;width:0!important;overflow:hidden!important}[data-sidebar] [data-logo]{display:none!important}';
            rules += '}';
            s.textContent = rules;
            document.head.appendChild(s);
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-gray-50 dark:bg-gray-900 dark:text-gray-100 transition-colors duration-200">

    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:z-[9999] focus:bg-white focus:px-4 focus:py-2 focus:text-accent-700 focus:shadow-lg focus:rounded-md focus:top-2 focus:left-2">
        Skip to content
    </a>

    <div class="flex h-full"
         x-data="{
            sidebarOpen: localStorage.getItem('sidebarOpen') !== 'false',
            mobileSidebarOpen: false,
            darkMode: localStorage.getItem('theme') === 'dark' || {{ auth()->user()?->theme === 'dark' ? 'true' : 'false' }},
            init() {
                var el = document.getElementById('sidebar-init');
                if (el) el.remove();
                this.$nextTick(() => {
                    document.documentElement.classList.remove('no-transitions');
                });
            },
            toggleDarkMode() {
                this.darkMode = !this.darkMode;
                localStorage.setItem('theme', this.darkMode ? 'dark' : 'light');
                document.documentElement.classList.toggle('dark', this.darkMode);
                // Save to server
                fetch('/api/user/theme', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ theme: this.darkMode ? 'dark' : 'light' })
                }).catch(() => {});
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
             aria-hidden="true"
             @click="mobileSidebarOpen = false">
        </div>

        {{-- Sidebar --}}
        <aside data-sidebar
               aria-label="{{ __('Main navigation') }}"
               class="fixed inset-y-0 left-0 z-50 flex flex-col bg-[#1A1A2E] overflow-hidden transition-all duration-300 ease-in-out
                      lg:translate-x-0 w-64"
               :class="[
                   mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
                   sidebarOpen ? 'lg:w-64' : 'lg:w-16'
               ]">

            {{-- Logo area --}}
            <div class="flex h-16 items-center gap-2 px-4 border-b border-white/10"
                 :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0'">
                <a href="{{ route('dashboard') }}" data-logo class="flex items-center h-full py-2 flex-1 min-w-0 transition-all duration-300"
                   :class="sidebarOpen ? '' : 'lg:hidden'">
                    @if($brandingLogo)
                        <img src="{{ Storage::url($brandingLogo) }}" alt="{{ $settingsService->get('app_name', 'SimpleAd Manager') }}" class="w-auto object-contain" style="height: 170px; filter: brightness(0) invert(1);">
                    @else
                        <span class="text-lg font-bold text-white whitespace-nowrap">{{ $settingsService->get('app_name', 'SimpleAd Manager') }}</span>
                    @endif
                </a>
                <button @click="toggleSidebar()" aria-label="{{ __('Toggle sidebar') }}" class="ml-auto hidden lg:flex items-center justify-center text-white/50 hover:text-white transition"
                        :class="sidebarOpen ? '' : 'lg:ml-0'">
                    <x-icons.menu class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            {{-- Dynamic sidebar content --}}
            <nav aria-label="{{ __('Sidebar') }}" class="flex-1 overflow-y-auto scrollbar-thin px-2 py-2 transition-all duration-300"
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
                    {{-- Settings (admin only) --}}
                    @if(auth()->user()->isAdmin())
                    <a href="{{ route('settings.general') }}"
                       @mouseenter="showSidebarTooltip($el)"
                       @mouseleave="hideSidebarTooltip()"
                       class="flex items-center gap-3 px-3 py-1.5 text-sm font-medium text-white/70 hover:text-white hover:bg-sidebar-hover rounded-lg transition-all duration-200 {{ request()->routeIs('settings.*') && !request()->routeIs('settings.profile') ? 'bg-sidebar-hover text-white' : '' }}"
                       :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0 lg:gap-0'">
                        <x-icons.settings class="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span class="whitespace-nowrap transition-all duration-300"
                              :class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">
                            {{ __('Settings') }}
                        </span>
                    </a>
                    @endif

                    {{-- Profile --}}
                    <a href="{{ route('settings.profile') }}"
                       @mouseenter="showSidebarTooltip($el)"
                       @mouseleave="hideSidebarTooltip()"
                       class="flex items-center gap-3 px-3 py-1.5 text-sm font-medium text-white/70 hover:text-white hover:bg-sidebar-hover rounded-lg transition-all duration-200 {{ request()->routeIs('settings.profile') ? 'bg-sidebar-hover text-white' : '' }}"
                       :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0 lg:gap-0'">
                        <div class="h-5 w-5 rounded-full bg-accent-500 flex items-center justify-center text-white text-[10px] font-medium shrink-0 overflow-hidden">
                            @if(auth()->user()->avatar_path)
                                <img src="{{ Storage::url(auth()->user()->avatar_path) }}" alt="Avatar {{ auth()->user()->name }}" class="h-full w-full object-cover">
                            @else
                                {{ auth()->user()->initials }}
                            @endif
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
                                :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0 lg:gap-0'">
                            <x-icons.log-out class="h-4 w-4 shrink-0" aria-hidden="true" />
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
                             this.datetime = `${day} ${month} ${year} | ${hours}:${minutes}`;
                         }
                     }"
                     x-init="updateClock(); setInterval(() => updateClock(), 60000)">

                    {{-- When expanded --}}
                    <div x-show="sidebarOpen" class="transition-all duration-300">
                        <div class="text-xs text-white/60 font-mono" x-text="datetime"></div>
                    </div>

                    {{-- When collapsed --}}
                    <div x-show="!sidebarOpen" x-cloak
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
            <main id="main-content" class="flex-1 p-6 lg:p-8 dark:bg-gray-900">
                <div class="mx-auto {{ $maxWidth ?? 'max-w-7xl' }}">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>

    {{-- Global toast notification stack --}}
    <div x-data="toastStack"
         x-on:notify.window="notify($event.detail)"
         class="fixed bottom-4 right-4 z-50 flex flex-col gap-2 items-end"
         aria-live="polite"
         role="status">
        <template x-for="toast in toasts" :key="toast.id">
            <div x-show="toast.visible"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 translate-y-2"
                 :class="colorClass(toast.type)"
                 class="flex items-start gap-3 max-w-sm rounded-lg px-4 py-3 text-sm shadow-lg">
                <span x-text="toast.message" class="flex-1 min-w-0"></span>
                <button @click="dismiss(toast.id)"
                        class="shrink-0 opacity-60 hover:opacity-100 transition-opacity"
                        aria-label="Dismiss">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        </template>
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
