@props(['site'])

@php
    /**
     * Rândul dens din vederea listă (SPEC §4.1).
     * Fiecare iconiță de status e mapată la un semnal REAL de pe model/relații.
     * Unde semnalul nu există încă pe Site, statusul e 'na' + TODO (SPEC §14.5).
     */
    $monitor = $site->uptimeMonitor;
    $perf = $site->performanceMonitor;

    // ── GRUP 1: Disponibilitate ──────────────────────────────────────────────
    // Uptime — Site::$is_up, doar dacă există monitor de uptime; altfel N/A.
    $uptimeStatus = $monitor ? ($site->is_up ? 'ok' : 'critical') : 'na';

    // SSL — UptimeMonitor::$check_ssl + $ssl_expires_at.
    if (! $monitor || ! $monitor->check_ssl || ! $monitor->ssl_expires_at) {
        $sslStatus = 'na';
    } else {
        $sslDays = now()->diffInDays($monitor->ssl_expires_at, false);
        $sslStatus = $sslDays < 0 ? 'critical' : ($sslDays <= 14 ? 'warning' : 'ok');
    }

    // Backup — Site::$backup_ok + $last_backup_at (stale > 7 zile → atenție).
    if (! $site->last_backup_at) {
        $backupStatus = 'na';
    } elseif (! $site->backup_ok) {
        $backupStatus = 'critical';
    } else {
        $backupStatus = $site->last_backup_at->lt(now()->subDays(7)) ? 'warning' : 'ok';
    }

    // ── GRUP 2: Sănătate ─────────────────────────────────────────────────────
    // Securitate — Site::$security_hardening_score (0-100).
    if ($site->security_hardening_score === null) {
        $securityStatus = 'na';
    } else {
        $securityStatus = $site->security_hardening_score >= 75
            ? 'ok'
            : ($site->security_hardening_score >= 50 ? 'warning' : 'critical');
    }

    // Performanță — PerformanceMonitor::$latest_mobile_score (0-100).
    $perfScore = $perf?->latest_mobile_score;
    if ($perfScore === null) {
        $perfStatus = 'na';
    } else {
        $perfStatus = $perfScore >= 90 ? 'ok' : ($perfScore >= 50 ? 'warning' : 'critical');
    }

    // Erori PHP — modelul PhpErrorLog nu are (încă) relație pe Site.
    $phpErrorStatus = 'na';

    // ── GRUP 3: Funcționare ──────────────────────────────────────────────────
    // Formulare + WooCommerce — zero semnal pe model deocamdată (SPEC §14.5).
    $formsStatus = 'na';
    $wooStatus = 'na';

    // Uptime % + bară lunară: gri dacă luna a fost curată, roșie la downtime.
    $uptimePct = $site->uptime_percentage !== null ? (float) $site->uptime_percentage : null;
    $hadDowntime = $uptimePct !== null && $uptimePct < 100;

    $updates = (int) $site->pending_updates_count;
@endphp

<div wire:key="site-row-{{ $site->id }}"
     {{ $attributes->merge(['class' => 'group flex min-h-[44px] items-center gap-3 px-3 hover:bg-gray-50 dark:hover:bg-white/5']) }}>
    {{-- Selecție multiplă --}}
    <x-ui.checkbox wire:model.live="selectedSites" value="{{ $site->id }}" class="shrink-0" />

    {{-- Favicon --}}
    <x-site-favicon :site="$site" size="sm" />

    {{-- Nume site + client --}}
    <div class="min-w-0 flex-1">
        <a href="{{ route('sites.overview', $site) }}"
           class="block truncate text-[13px] font-medium leading-tight text-gray-900 dark:text-gray-100 hover:text-accent-600 dark:hover:text-accent-400">
            {{ $site->name }}
        </a>
        @if($site->client)
            <span class="block truncate text-[11px] leading-tight text-gray-400 dark:text-gray-500">{{ $site->client->name }}</span>
        @endif
    </div>

    {{-- Badge nr. update-uri (ascuns la 0) --}}
    @if($updates > 0)
        <x-ui.badge variant="yellow" class="shrink-0 tabular-nums" title="{{ $updates }} actualizări disponibile">
            {{ $updates }}
        </x-ui.badge>
    @endif

    {{-- GRUP: Disponibilitate — „De ce nu merge site-ul?" --}}
    <div class="flex shrink-0 items-center gap-1.5 border-l border-gray-200 dark:border-gray-700 pl-3">
        <x-status-icon icon="activity" :status="$uptimeStatus" label="Uptime" />
        <x-status-icon icon="shield" :status="$sslStatus" label="SSL" />
        <x-status-icon icon="hard-drive" :status="$backupStatus" label="Backup" />
    </div>

    {{-- GRUP: Sănătate — „De ce e lent?" --}}
    <div class="flex shrink-0 items-center gap-1.5 border-l border-gray-200 dark:border-gray-700 pl-3">
        <x-status-icon icon="shield-check" :status="$securityStatus" label="Securitate" />
        <x-status-icon icon="zap" :status="$perfStatus" label="Performanță" />
        {{-- TODO: map erori PHP — Site nu expune încă relația către PhpErrorLog --}}
        <x-status-icon icon="code" :status="$phpErrorStatus" label="Erori PHP" />
    </div>

    {{-- GRUP: Funcționare — „De ce nu primesc comenzi?" --}}
    <div class="flex shrink-0 items-center gap-1.5 border-l border-gray-200 dark:border-gray-700 pl-3">
        {{-- TODO: map formulare — semnal inexistent pe model (SPEC §14.5) --}}
        <x-status-icon icon="inbox" :status="$formsStatus" label="Formulare" />
        {{-- TODO: map WooCommerce — flag de detecție inexistent pe model (SPEC §14.5) --}}
        <x-status-icon icon="target" :status="$wooStatus" label="WooCommerce" />
    </div>

    {{-- Uptime % + bară lunară --}}
    <div class="flex w-32 shrink-0 items-center gap-2 border-l border-gray-200 dark:border-gray-700 pl-3">
        <div class="h-1 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"
             title="Uptime luna curentă">
            <div class="h-full rounded-full {{ $hadDowntime ? 'bg-red-500' : 'bg-gray-300' }}"
                 style="width: {{ $uptimePct !== null ? number_format(min(100, max(0, $uptimePct)), 2, '.', '') : '0' }}%"></div>
        </div>
        <span class="w-10 text-right text-[11px] tabular-nums text-gray-500 dark:text-gray-400">
            {{ $uptimePct !== null ? number_format($uptimePct, 1) . '%' : '—' }}
        </span>
    </div>

    {{-- Meniu acțiuni rapide --}}
    {{-- TODO: metodele wire:click (openWpAdmin/runBackup/checkNow) trebuie
         implementate în componenta Livewire a listei care randează rândul --}}
    <div class="shrink-0">
        <x-ui.dropdown align="right" width="48">
            <x-slot:trigger>
                <button type="button"
                        aria-label="Acțiuni pentru {{ $site->name }}"
                        class="inline-flex h-7 w-7 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="5" r="1.75"/>
                        <circle cx="12" cy="12" r="1.75"/>
                        <circle cx="12" cy="19" r="1.75"/>
                    </svg>
                </button>
            </x-slot:trigger>

            <button type="button" wire:click="openWpAdmin({{ $site->id }})"
                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                <x-icons.globe class="h-4 w-4 shrink-0 text-gray-400" />
                <span>WP Admin</span>
            </button>

            <button type="button" wire:click="runBackup({{ $site->id }})"
                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                <x-icons.hard-drive class="h-4 w-4 shrink-0 text-gray-400" />
                <span>Backup acum</span>
            </button>

            <button type="button" wire:click="checkNow({{ $site->id }})"
                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                <x-icons.refresh-cw class="h-4 w-4 shrink-0 text-gray-400" />
                <span>Verifică acum</span>
            </button>
        </x-ui.dropdown>
    </div>
</div>
