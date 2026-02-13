<div class="space-y-1">
    <x-sidebar.sidebar-item
        :href="route('dashboard')"
        icon="home"
        :active="request()->routeIs('dashboard')"
    >
        {{ __('Dashboard') }}
    </x-sidebar.sidebar-item>

</div>

<x-sidebar.sidebar-section :title="__('Monitoring')">
    <x-sidebar.sidebar-item
        :href="route('uptime.index')"
        icon="activity"
        :active="request()->routeIs('uptime.*')"
    >
        {{ __('Uptime') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('performance.index')"
        icon="zap"
        :active="request()->routeIs('performance.*')"
    >
        {{ __('Performance') }}
    </x-sidebar.sidebar-item>

</x-sidebar.sidebar-section>

<x-sidebar.sidebar-section :title="__('Management')">
    <x-sidebar.sidebar-item
        :href="route('backups.index')"
        icon="hard-drive"
        :active="request()->routeIs('backups.*')"
    >
        {{ __('Backups') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('updates.index')"
        icon="refresh-cw"
        :active="request()->routeIs('updates.*')"
    >
        {{ __('Updates') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('clients.index')"
        icon="users"
        :active="request()->routeIs('clients.*')"
    >
        {{ __('Clients') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('reports.index')"
        icon="file-text"
        :active="request()->routeIs('reports.*')"
    >
        {{ __('Reports') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('activity.index')"
        icon="inbox"
        :active="request()->routeIs('activity.*')"
    >
        {{ __('Activity') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('status-pages.index')"
        icon="globe"
        :active="request()->routeIs('status-pages.*')"
    >
        {{ __('Status Pages') }}
    </x-sidebar.sidebar-item>
</x-sidebar.sidebar-section>
