{{-- SPEC §3.1 — fleet context: a slim, exception-first nav (see REFERINTA-VIZUALA).
     Per-site modules (Uptime, Security, Performance, Backups, DNS, Errors) live in
     the site context; clients and plans are reached through the Sites screen. Their
     routes still exist — they are just not in the primary fleet nav. --}}
<div class="space-y-1">
    <x-sidebar.sidebar-section :title="__('View')">
        <x-sidebar.sidebar-item
            :href="route('dashboard')"
            icon="home"
            :active="request()->routeIs('dashboard')"
        >
            {{ __('Panou') }}
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('alerts.index')"
            icon="bell"
            :active="request()->routeIs('alerts.index')"
            :count="$alertsCount ?? 0"
            count-tone="danger"
        >
            {{ __('Alerts') }}
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
    </x-sidebar.sidebar-section>

    <x-sidebar.sidebar-section :title="__('Records')">
        <x-sidebar.sidebar-item
            :href="route('reports.index')"
            icon="file-text"
            :active="request()->routeIs('reports.*')"
        >
            {{ __('Reports') }}
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('activity.index')"
            icon="clock"
            :active="request()->routeIs('activity.*')"
        >
            {{ __('Activity') }}
        </x-sidebar.sidebar-item>
    </x-sidebar.sidebar-section>

    
</div>
