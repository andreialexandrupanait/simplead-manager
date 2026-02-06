<div class="space-y-1">
    <x-sidebar.sidebar-item
        :href="route('dashboard')"
        icon="home"
        :active="request()->routeIs('dashboard')"
    >
        Dashboard
    </x-sidebar.sidebar-item>
</div>

<x-sidebar.sidebar-section title="Monitoring">
    <x-sidebar.sidebar-item
        :href="route('uptime.index')"
        icon="activity"
        :active="request()->routeIs('uptime.*')"
    >
        Uptime
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('performance.index')"
        icon="zap"
        :active="request()->routeIs('performance.*')"
    >
        Performance
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('errors.index')"
        icon="alert-triangle"
        :active="request()->routeIs('errors.*')"
    >
        Errors
    </x-sidebar.sidebar-item>
</x-sidebar.sidebar-section>

<x-sidebar.sidebar-section title="Management">
    <x-sidebar.sidebar-item
        :href="route('backups.index')"
        icon="hard-drive"
        :active="request()->routeIs('backups.*')"
    >
        Backups
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

    <x-sidebar.sidebar-item
        :href="route('status-pages.index')"
        icon="globe"
        :active="request()->routeIs('status-pages.*')"
    >
        Status Pages
    </x-sidebar.sidebar-item>
</x-sidebar.sidebar-section>
