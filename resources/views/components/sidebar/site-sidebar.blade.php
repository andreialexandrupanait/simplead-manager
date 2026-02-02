@props(['site'])

<div class="space-y-4">
    {{-- Back to sites --}}
    <a href="{{ route('sites.index') }}"
       class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-white/60 hover:text-white hover:bg-white/5 transition">
        <x-icons.arrow-left class="h-4 w-4" />
        All Sites
    </a>

    {{-- Current site info --}}
    <div class="rounded-lg bg-white/5 px-3 py-3">
        <div class="flex items-center gap-3">
            <img src="https://www.google.com/s2/favicons?domain={{ parse_url($site->url, PHP_URL_HOST) ?? $site->url }}&sz=32"
                 alt="" class="h-6 w-6 rounded">
            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-white">{{ $site->name }}</p>
                <p class="truncate text-xs text-white/50">{{ parse_url($site->url, PHP_URL_HOST) ?? $site->url }}</p>
            </div>
        </div>
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

        <x-sidebar.sidebar-item
            :href="route('sites.plugins', $site)"
            icon="puzzle"
            :active="request()->routeIs('sites.plugins')"
        >
            Plugins & Themes
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.updates', $site)"
            icon="refresh-cw"
            :active="request()->routeIs('sites.updates')"
        >
            Updates
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.security', $site)"
            icon="shield"
            :active="request()->routeIs('sites.security')"
        >
            Security
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.performance', $site)"
            icon="zap"
            :active="request()->routeIs('sites.performance')"
        >
            Performance
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.backups', $site)"
            icon="hard-drive"
            :active="request()->routeIs('sites.backups')"
        >
            Backups
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.uptime', $site)"
            icon="activity"
            :active="request()->routeIs('sites.uptime')"
        >
            Uptime
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.analytics', $site)"
            icon="bar-chart-2"
            :active="request()->routeIs('sites.analytics')"
        >
            Analytics
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.settings', $site)"
            icon="settings"
            :active="request()->routeIs('sites.settings')"
        >
            Site Settings
        </x-sidebar.sidebar-item>
    </div>
</div>
