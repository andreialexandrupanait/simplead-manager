{{-- SPEC §3.1 — fleet context: a slim, exception-first nav (see REFERINTA-VIZUALA).
     Security, Performance, DNS and Errors stay in the site context; clients and
     plans are reached through the Sites screen. Their routes still exist — they
     are just not in the primary fleet nav.

     Backups and Uptime are the exception: they are the two things you check
     fleet-wide without a site in mind, and until now the only way in was a stat
     card on the landing page or typing the URL. --}}
<div class="space-y-1">
    <x-sidebar.sidebar-section :title="__('View')">
        <x-sidebar.sidebar-item
            :href="route('dashboard')"
            icon="home"
            :active="request()->routeIs('dashboard')"
        >
            {{ __('Dashboard') }}
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

        <x-sidebar.sidebar-item
            :href="route('backups.index')"
            icon="hard-drive"
            :active="request()->routeIs('backups.*')"
        >
            {{ __('Backups') }}
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('uptime.index')"
            icon="activity"
            :active="request()->routeIs('uptime.*')"
        >
            {{ __('Uptime') }}
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
