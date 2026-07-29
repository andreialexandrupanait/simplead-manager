<div class="space-y-1">
    {{-- SPEC §3.1 VEDERE — Panou + Alerte --}}
    <x-sidebar.sidebar-item
        :href="route('dashboard')"
        icon="home"
        :active="request()->routeIs('dashboard') && ! request()->has('tab')"
    >
        {{ __('Panou') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('dashboard', ['tab' => 'alerts'])"
        icon="alert-triangle"
        :active="request()->query('tab') === 'alerts'"
        :count="$alertsCount ?? 0"
        count-tone="danger"
    >
        {{ __('Alerts') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('sites.index')"
        icon="globe"
        :active="request()->routeIs('sites.index')"
        :count="$sitesCount ?? 0"
        count-tone="accent"
    >
        {{ __('Sites') }}
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

    <x-sidebar.sidebar-item
        :href="route('security.index')"
        icon="shield-check"
        :active="request()->routeIs('security.*')"
        :count="$securityCount ?? 0"
        count-tone="danger"
    >
        {{ __('Security') }}
    </x-sidebar.sidebar-item>

</x-sidebar.sidebar-section>

<x-sidebar.sidebar-section :title="__('Operations')">
    <x-sidebar.sidebar-item
        :href="route('updates.index')"
        icon="refresh-cw"
        :active="request()->routeIs('updates.*')"
        :count="$updatesCount ?? 0"
        count-tone="accent"
    >
        {{ __('Updates') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('backups.index')"
        icon="hard-drive"
        :active="request()->routeIs('backups.*')"
    >
        {{ __('Backups') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('reports.index')"
        icon="file-text"
        :active="request()->routeIs('reports.*')"
    >
        {{ __('Reports') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('maintenance-plans')"
        icon="layers"
        :active="request()->routeIs('maintenance-plans')"
    >
        {{ __('Maintenance Plans') }}
    </x-sidebar.sidebar-item>
</x-sidebar.sidebar-section>

<x-sidebar.sidebar-section :title="__('Insights')">
    <x-sidebar.sidebar-item
        :href="route('activity.index')"
        icon="clock"
        :active="request()->routeIs('activity.*')"
    >
        {{ __('Activity') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('error-logs.index')"
        icon="alert-triangle"
        :active="request()->routeIs('error-logs.*')"
        :count="$errorLogsCount ?? 0"
        count-tone="warning"
    >
        {{ __('Error Logs') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('dns.index')"
        icon="globe"
        :active="request()->routeIs('dns.*')"
    >
        {{ __('DNS') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('clients.index')"
        icon="users"
        :active="request()->routeIs('clients.*')"
    >
        {{ __('Clients') }}
    </x-sidebar.sidebar-item>
</x-sidebar.sidebar-section>
