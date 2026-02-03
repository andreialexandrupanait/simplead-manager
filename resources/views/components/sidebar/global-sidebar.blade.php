<div class="space-y-1">
    <x-sidebar.sidebar-item
        :href="route('dashboard')"
        icon="home"
        :active="request()->routeIs('dashboard')"
    >
        Dashboard
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('sites.index')"
        icon="globe"
        :active="request()->routeIs('sites.*')"
    >
        Sites
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('uptime.index')"
        icon="activity"
        :active="request()->routeIs('uptime.*')"
    >
        Uptime
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('backups.index')"
        icon="hard-drive"
        :active="request()->routeIs('backups.*')"
    >
        Backups
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('performance.index')"
        icon="zap"
        :active="request()->routeIs('performance.*')"
    >
        Performance
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('updates.index')"
        icon="refresh-cw"
        :active="request()->routeIs('updates.*')"
    >
        Updates
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('clients.index')"
        icon="users"
        :active="request()->routeIs('clients.*')"
    >
        Clients
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('reports.index')"
        icon="file-text"
        :active="request()->routeIs('reports.*')"
    >
        Reports
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('activity.index')"
        icon="inbox"
        :active="request()->routeIs('activity.*')"
    >
        Activity
    </x-sidebar.sidebar-item>

    <div class="pt-6">
        <div class="overflow-hidden transition-all duration-300"
             :class="sidebarOpen ? '' : 'lg:max-h-0 lg:opacity-0'"
            <p class="px-3 text-xs font-semibold uppercase tracking-wider text-white/40">System</p>
        </div>
        <div class="mt-2 space-y-1"
             :class="sidebarOpen ? '' : 'lg:mt-0'">
            <x-sidebar.sidebar-item
                :href="route('settings.general')"
                icon="settings"
                :active="request()->routeIs('settings.*')"
            >
                Settings
            </x-sidebar.sidebar-item>
        </div>
    </div>
</div>
