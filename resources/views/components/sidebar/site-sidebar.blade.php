@props(['site'])

@php
    $moduleService = app(\App\Services\ModuleConfigService::class);
@endphp

<div class="space-y-4">
    {{-- Back to sites --}}
    <div class="relative" x-data="{
        showTooltip: false,
        tooltipEl: null,
        reposition() {
            if (!this.tooltipEl) return;
            let rect = this.$refs.trigger.getBoundingClientRect();
            let ph = this.tooltipEl.offsetHeight;
            this.tooltipEl.style.left = Math.round(rect.right + 8) + 'px';
            this.tooltipEl.style.top = Math.round(rect.top + rect.height / 2 - ph / 2) + 'px';
        },
        init() {
            this.$watch('sidebarOpen', (val) => { if (val) this.showTooltip = false; });
        }
    }">
        <a href="{{ route('dashboard') }}"
           x-ref="trigger"
           @mouseenter="if (!sidebarOpen && window.innerWidth >= 1024) { showTooltip = true; $nextTick(() => reposition()); }"
           @mouseleave="showTooltip = false"
           class="flex items-center gap-2 px-3 rounded-lg py-2 text-sm text-white/60 hover:text-white hover:bg-white/5 transition-all duration-200"
           :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0'">
            <x-icons.arrow-left class="h-4 w-4 shrink-0" />
            <span class="whitespace-nowrap transition-all duration-300"
                  :class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">All Sites</span>
        </a>

        {{-- Tooltip --}}
        <template x-teleport="body">
            <div x-show="showTooltip"
                 x-cloak
                 x-ref="tooltip"
                 x-init="tooltipEl = $el"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 translate-x-1"
                 x-transition:enter-end="opacity-100 translate-x-0"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100 translate-x-0"
                 x-transition:leave-end="opacity-0 translate-x-1"
                 class="pointer-events-none whitespace-nowrap"
                 style="display: none; position: fixed; z-index: 10000;">
                <div class="relative rounded-md bg-gray-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-lg whitespace-nowrap">
                    All Sites
                    <div class="absolute right-full top-1/2 -translate-y-1/2 border-4 border-transparent border-r-gray-900"></div>
                </div>
            </div>
        </template>
    </div>

    {{-- Current site info --}}
    <div class="relative" x-data="{
        showTooltip: false,
        tooltipEl: null,
        reposition() {
            if (!this.tooltipEl) return;
            let rect = this.$refs.trigger.getBoundingClientRect();
            let ph = this.tooltipEl.offsetHeight;
            this.tooltipEl.style.left = Math.round(rect.right + 8) + 'px';
            this.tooltipEl.style.top = Math.round(rect.top + rect.height / 2 - ph / 2) + 'px';
        },
        init() {
            this.$watch('sidebarOpen', (val) => { if (val) this.showTooltip = false; });
        }
    }">
        <div class="rounded-lg bg-white/5 px-3 py-3 transition-all duration-300"
             x-ref="trigger"
             @mouseenter="if (!sidebarOpen && window.innerWidth >= 1024) { showTooltip = true; $nextTick(() => reposition()); }"
             @mouseleave="showTooltip = false"
             :class="sidebarOpen ? '' : 'lg:flex lg:justify-center lg:px-2'">
            <div class="flex items-center gap-3"
                 :class="sidebarOpen ? '' : 'lg:justify-center'">
                <x-site-favicon :site="$site" />
                <div class="min-w-0 whitespace-nowrap transition-all duration-300"
                     :class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">
                    <p class="truncate text-sm font-medium text-white">{{ $site->name }}</p>
                    <p class="truncate text-xs text-white/50">{{ parse_url($site->url, PHP_URL_HOST) ?? $site->url }}</p>
                </div>
            </div>
        </div>

        {{-- Tooltip --}}
        <template x-teleport="body">
            <div x-show="showTooltip"
                 x-cloak
                 x-ref="tooltip"
                 x-init="tooltipEl = $el"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 translate-x-1"
                 x-transition:enter-end="opacity-100 translate-x-0"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100 translate-x-0"
                 x-transition:leave-end="opacity-0 translate-x-1"
                 class="pointer-events-none whitespace-nowrap"
                 style="display: none; position: fixed; z-index: 10000;">
                <div class="relative rounded-md bg-gray-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-lg whitespace-nowrap">
                    {{ $site->name }}
                    <div class="absolute right-full top-1/2 -translate-y-1/2 border-4 border-transparent border-r-gray-900"></div>
                </div>
            </div>
        </template>
    </div>

    {{-- Site navigation --}}
    <div class="space-y-1">
        <x-sidebar.sidebar-item
            :href="route('sites.overview', $site)"
            icon="layout-dashboard"
            :active="request()->routeIs('sites.overview')"
        >
            Overview
        </x-sidebar.sidebar-item>
    </div>

    <x-sidebar.sidebar-section title="Management">
        <x-sidebar.sidebar-item
            :href="route('sites.plugins', $site)"
            icon="puzzle"
            :active="request()->routeIs('sites.plugins')"
        >
            Plugins & Updates
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.backups', $site)"
            icon="hard-drive"
            :active="request()->routeIs('sites.backups')"
        >
            Backups
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.database', $site)"
            icon="database"
            :active="request()->routeIs('sites.database')"
            :inactive="!$moduleService->isModuleActive($site, 'database_cleanup')"
        >
            Database
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.cron', $site)"
            icon="clock"
            :active="request()->routeIs('sites.cron')"
        >
            Cron Jobs
        </x-sidebar.sidebar-item>
    </x-sidebar.sidebar-section>

    <x-sidebar.sidebar-section title="Monitoring">
        <x-sidebar.sidebar-item
            :href="route('sites.uptime', $site)"
            icon="activity"
            :active="request()->routeIs('sites.uptime')"
        >
            Uptime
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.performance', $site)"
            icon="zap"
            :active="request()->routeIs('sites.performance')"
        >
            Performance
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.analytics', $site)"
            icon="bar-chart-2"
            :active="request()->routeIs('sites.analytics')"
            :inactive="!$moduleService->isModuleActive($site, 'analytics')"
        >
            Analytics
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.search-console', $site)"
            icon="search"
            :active="request()->routeIs('sites.search-console')"
            :inactive="!$moduleService->isModuleActive($site, 'search_console')"
        >
            Search Console
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.security', $site)"
            icon="shield"
            :active="request()->routeIs('sites.security*')"
            :inactive="!$moduleService->isModuleActive($site, 'security')"
        >
            Security
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.cloudflare', $site)"
            icon="cloud"
            :active="request()->routeIs('sites.cloudflare')"
            :inactive="!$moduleService->isModuleActive($site, 'cloudflare')"
        >
            Cloudflare
        </x-sidebar.sidebar-item>
    </x-sidebar.sidebar-section>

    <x-sidebar.sidebar-section title="System">
        <x-sidebar.sidebar-item
            :href="route('sites.reports', $site)"
            icon="file-text"
            :active="request()->routeIs('sites.reports')"
        >
            Reports
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.settings', $site)"
            icon="settings"
            :active="request()->routeIs('sites.settings')"
        >
            Configuration
        </x-sidebar.sidebar-item>
    </x-sidebar.sidebar-section>
</div>
