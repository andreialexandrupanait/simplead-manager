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

    <div class="pt-6">
        <p class="px-3 text-xs font-semibold uppercase tracking-wider text-white/40">System</p>
        <div class="mt-2 space-y-1">
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
