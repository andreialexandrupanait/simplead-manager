@props(['site'])

@php
    $moduleService = app(\App\Services\ModuleConfigService::class);

    // Section-level "contains active route" helpers, used to expand the right
    // group by default and to highlight the parent. Only routes that actually
    // exist (see routes/web.php) are referenced here — never invent a route.
    $inPlugins = request()->routeIs('sites.plugins');
    $inBackups = request()->routeIs('sites.backups');
    $inUptime = request()->routeIs('sites.uptime');
    $inSecurity = request()->routeIs('sites.security*') || request()->routeIs('sites.tweaks*');
    $inPerformance = request()->routeIs('sites.performance');
    $inChecks = request()->routeIs('sites.cron') || request()->routeIs('sites.database');
    $inTraffic = request()->routeIs('sites.analytics') || request()->routeIs('sites.search-console') || request()->routeIs('sites.cloudflare');
    $inReports = request()->routeIs('sites.reports') || request()->routeIs('sites.reports.view');

    // Provided by SiteSwitcherComposer (View::composer). Guarded for safety.
    $switcherSites = $switcherSites ?? collect();
    $sidebarUpdatesCount = $sidebarUpdatesCount ?? 0;
    $sidebarSecurityCount = $sidebarSecurityCount ?? 0;
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
        <a href="{{ route('sites.index') }}"
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

    {{-- Site switcher (SPEC §3.3: the site name is a commutator, not a title).
         Clicking swaps the active site without leaving the site context. --}}
    <x-ui.dropdown align="left" width="64">
        <x-slot:trigger>
            <button type="button"
                    class="flex w-full items-center gap-3 rounded-lg bg-white/5 px-3 py-3 text-left transition-all duration-200 hover:bg-white/10"
                    :class="sidebarOpen ? '' : 'lg:justify-center lg:px-2'">
                <x-site-favicon :site="$site" />
                <div class="min-w-0 flex-1 whitespace-nowrap transition-all duration-300"
                     :class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">
                    <p class="truncate text-sm font-medium text-white">{{ $site->name }}</p>
                    <p class="truncate text-xs text-white/50">{{ parse_url($site->url, PHP_URL_HOST) ?? $site->url }}</p>
                </div>
                <x-icons.chevron-right class="h-4 w-4 shrink-0 rotate-90 text-white/40 transition-all duration-300"
                                       ::class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'" />
            </button>
        </x-slot:trigger>

        <div x-data="{ q: '' }" class="max-h-[70vh] overflow-y-auto scrollbar-thin">
            <p class="px-3 pb-1 pt-1 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                {{ __('Comută site') }}
            </p>

            @if($switcherSites->count() > 6)
                <div class="px-2 pb-1">
                    <input type="text"
                           x-model="q"
                           @click.stop
                           @keydown.stop
                           placeholder="{{ __('Caută site…') }}"
                           class="w-full rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-800 placeholder-gray-400 focus:border-accent-500 focus:outline-none focus:ring-1 focus:ring-accent-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
            @endif

            @foreach($switcherSites as $switcherSite)
                <a href="{{ route('sites.overview', $switcherSite) }}"
                   data-name="{{ Str::lower($switcherSite->name) }}"
                   x-show="q === '' || $el.dataset.name.includes(q.toLowerCase())"
                   @class([
                       'flex items-center gap-2.5 px-3 py-2 text-sm transition-colors',
                       'bg-accent-50 text-accent-700 dark:bg-accent-500/10 dark:text-accent-300' => $switcherSite->id === $site->id,
                       'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700/50' => $switcherSite->id !== $site->id,
                   ])>
                    <x-site-favicon :site="$switcherSite" size="sm" />
                    <span class="min-w-0 flex-1 truncate">{{ $switcherSite->name }}</span>
                    @if($switcherSite->id === $site->id)
                        <x-icons.check-circle class="h-4 w-4 shrink-0" />
                    @endif
                </a>
            @endforeach

            <div class="mt-1 border-t border-gray-200 dark:border-gray-700">
                <a href="{{ route('sites.index') }}"
                   class="flex items-center gap-2 px-3 py-2 text-sm text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700/50">
                    <x-icons.arrow-left class="h-4 w-4 shrink-0" />
                    <span>{{ __('Toate site-urile') }}</span>
                </a>
            </div>
        </div>
    </x-ui.dropdown>

    {{-- Overview (Prezentare) --}}
    <div class="space-y-1">
        <x-sidebar.sidebar-item
            :href="route('sites.overview', $site)"
            icon="layout-dashboard"
            :active="request()->routeIs('sites.overview')"
        >
            Prezentare
        </x-sidebar.sidebar-item>
    </div>

    {{-- ─────────────── MENTENANȚĂ ─────────────── --}}
    <x-sidebar.sidebar-section title="Mentenanță">
        {{-- Pluginuri și teme ▾ — spec sub-items: Pluginuri · Teme · Licențe premium.
             Only "Pluginuri" has a real route (sites.plugins, which is the
             "Plugins & Updates" screen, so the updates badge lives here). --}}
        <x-sidebar.sidebar-group
            title="Pluginuri și teme"
            icon="puzzle"
            key="plugins-themes"
            :active="$inPlugins"
            :count="$sidebarUpdatesCount"
            count-tone="accent"
        >
            <x-sidebar.sidebar-item
                :href="route('sites.plugins', $site)"
                icon="puzzle"
                :active="$inPlugins"
            >
                Pluginuri
            </x-sidebar.sidebar-item>
            {{-- TODO: rută inexistentă — Teme (nu există rută dedicată încă) --}}
            {{-- TODO: rută inexistentă — Licențe premium --}}
        </x-sidebar.sidebar-group>

        {{-- TODO: grup "Actualizări ▾ [contor]" (Disponibile · Reguli automate ·
             Ignorate · Istoric) — nu există rute dedicate; actualizările trăiesc
             pe ecranul sites.plugins (badge-ul de update e mutat pe grupul de mai sus). --}}

        {{-- Backupuri ▾ — spec: Listă · Restore verificat · Programare.
             Doar "Listă" are rută (sites.backups); restul sunt tab-uri interne. --}}
        <x-sidebar.sidebar-group
            title="Backupuri"
            icon="hard-drive"
            key="backups"
            :active="$inBackups"
        >
            <x-sidebar.sidebar-item
                :href="route('sites.backups', $site)"
                icon="hard-drive"
                :active="$inBackups"
            >
                Listă
            </x-sidebar.sidebar-item>
            {{-- TODO: rută inexistentă — Restore verificat (tab în sites.backups) --}}
            {{-- TODO: rută inexistentă — Programare (tab în sites.backups) --}}
        </x-sidebar.sidebar-group>
    </x-sidebar.sidebar-section>

    {{-- ─────────────── SUPRAVEGHERE ─────────────── --}}
    <x-sidebar.sidebar-section title="Supraveghere">
        {{-- Uptime ▾ — spec: Panou · Incidente · SSL și domeniu. Doar Panou are rută. --}}
        <x-sidebar.sidebar-group
            title="Uptime"
            icon="activity"
            key="uptime"
            :active="$inUptime"
        >
            <x-sidebar.sidebar-item
                :href="route('sites.uptime', $site)"
                icon="activity"
                :active="$inUptime"
            >
                Panou
            </x-sidebar.sidebar-item>
            {{-- TODO: rută inexistentă — Incidente --}}
            {{-- TODO: rută inexistentă — SSL și domeniu --}}
        </x-sidebar.sidebar-group>

        {{-- Securitate ▾ [contor] — spec: Scanare · Vulnerabilități · Integritate ·
             Utilizatori · Hardening. Real: security(Panou), scanning, users, hardening. --}}
        <x-sidebar.sidebar-group
            title="Securitate"
            icon="shield-check"
            key="security"
            :active="$inSecurity"
            :count="$sidebarSecurityCount"
            count-tone="danger"
        >
            <x-sidebar.sidebar-item
                :href="route('sites.security', $site)"
                icon="shield"
                :active="request()->routeIs('sites.security') || request()->routeIs('sites.tweaks*')"
                :inactive="!$moduleService->isModuleActive($site, 'security')"
            >
                Panou
            </x-sidebar.sidebar-item>
            <x-sidebar.sidebar-item
                :href="route('sites.security.scanning', $site)"
                icon="search"
                :active="request()->routeIs('sites.security.scanning')"
            >
                Scanare
            </x-sidebar.sidebar-item>
            {{-- TODO: rută inexistentă — Vulnerabilități --}}
            {{-- TODO: rută inexistentă — Integritate --}}
            <x-sidebar.sidebar-item
                :href="route('sites.security.users', $site)"
                icon="users"
                :active="request()->routeIs('sites.security.users')"
            >
                Utilizatori
            </x-sidebar.sidebar-item>
            <x-sidebar.sidebar-item
                :href="route('sites.security.hardening', $site)"
                icon="shield-check"
                :active="request()->routeIs('sites.security.hardening')"
            >
                Hardening
            </x-sidebar.sidebar-item>
        </x-sidebar.sidebar-group>

        {{-- Performanță ▾ — spec: PageSpeed · Core Web Vitals (ambele pe același ecran). --}}
        <x-sidebar.sidebar-group
            title="Performanță"
            icon="zap"
            key="performance"
            :active="$inPerformance"
        >
            <x-sidebar.sidebar-item
                :href="route('sites.performance', $site)"
                icon="zap"
                :active="$inPerformance"
            >
                PageSpeed
            </x-sidebar.sidebar-item>
            {{-- TODO: rută inexistentă — Core Web Vitals (secțiune în sites.performance) --}}
        </x-sidebar.sidebar-group>

        {{-- Verificări ▾ — spec: Formulare · WooCommerce · Linkuri rupte · Erori PHP ·
             Cron · Bază de date. Real: Cron (sites.cron), Bază de date (sites.database). --}}
        <x-sidebar.sidebar-group
            title="Verificări"
            icon="check-circle"
            key="checks"
            :active="$inChecks"
        >
            {{-- TODO: rută inexistentă — Formulare --}}
            {{-- TODO: rută inexistentă — WooCommerce --}}
            {{-- TODO: rută inexistentă — Linkuri rupte --}}
            {{-- TODO: rută inexistentă — Erori PHP (modul la nivel de flotă, fără rută per-site) --}}
            <x-sidebar.sidebar-item
                :href="route('sites.cron', $site)"
                icon="clock"
                :active="request()->routeIs('sites.cron')"
            >
                Cron
            </x-sidebar.sidebar-item>
            <x-sidebar.sidebar-item
                :href="route('sites.database', $site)"
                icon="database"
                :active="request()->routeIs('sites.database')"
                :inactive="!$moduleService->isModuleActive($site, 'database_cleanup')"
            >
                Bază de date
            </x-sidebar.sidebar-item>
        </x-sidebar.sidebar-group>
    </x-sidebar.sidebar-section>

    {{-- ─────────────── DATE ─────────────── --}}
    <x-sidebar.sidebar-section title="Date">
        {{-- Trafic ▾ — spec: Analytics · Search Console · Cloudflare (toate au rute). --}}
        <x-sidebar.sidebar-group
            title="Trafic"
            icon="bar-chart-2"
            key="traffic"
            :active="$inTraffic"
        >
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
                :href="route('sites.cloudflare', $site)"
                icon="cloud"
                :active="request()->routeIs('sites.cloudflare')"
                :inactive="!$moduleService->isModuleActive($site, 'cloudflare')"
            >
                Cloudflare
            </x-sidebar.sidebar-item>
        </x-sidebar.sidebar-group>

        {{-- TODO: „Sarcini" — element extern (din app.simplead.ro), fără rută locală. --}}

        {{-- Rapoarte ▾ — spec: Generate · Programare. Doar "Generate" are rută. --}}
        <x-sidebar.sidebar-group
            title="Rapoarte"
            icon="file-text"
            key="reports"
            :active="$inReports"
        >
            <x-sidebar.sidebar-item
                :href="route('sites.reports', $site)"
                icon="file-text"
                :active="$inReports"
            >
                Generate
            </x-sidebar.sidebar-item>
            {{-- TODO: rută inexistentă — Programare (tab în sites.reports) --}}
        </x-sidebar.sidebar-group>
    </x-sidebar.sidebar-section>

    {{-- Setări site --}}
    <x-sidebar.sidebar-section title="">
        <x-sidebar.sidebar-item
            :href="route('sites.settings', $site)"
            icon="settings"
            :active="request()->routeIs('sites.settings')"
        >
            Setări site
        </x-sidebar.sidebar-item>
    </x-sidebar.sidebar-section>
</div>
