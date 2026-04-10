<div class="space-y-1">
    <x-sidebar.sidebar-item
        :href="route('dashboard')"
        icon="home"
        :active="request()->routeIs('dashboard')"
    >
        {{ __('Dashboard') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('clients.index')"
        icon="users"
        :active="request()->routeIs('clients.*')"
    >
        {{ __('Clients') }}
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
    >
        {{ __('Security') }}
    </x-sidebar.sidebar-item>
</x-sidebar.sidebar-section>

<x-sidebar.sidebar-section :title="__('SEO')">
    <x-sidebar.sidebar-item
        :href="route('seo.index')"
        icon="target"
        :active="request()->routeIs('seo.index')"
    >
        {{ __('Dashboard') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('seo.crawler.index')"
        icon="globe"
        :active="request()->routeIs('seo.crawler.*')"
    >
        {{ __('Crawler') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('seo.content.index')"
        icon="file-text"
        :active="request()->routeIs('seo.content.*')"
    >
        {{ __('Content AI') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('seo.keywords.index')"
        icon="search"
        :active="request()->routeIs('seo.keywords.*')"
    >
        {{ __('Keywords') }}
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('seo.calendar')"
        icon="calendar"
        :active="request()->routeIs('seo.calendar')"
    >
        {{ __('Calendar') }}
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
