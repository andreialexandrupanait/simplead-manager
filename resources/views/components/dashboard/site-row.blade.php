@props([
    'site',
    'selectedSites' => [],
    'reordering' => false,
    'siteStatuses' => collect(),
])

@php
    // Update badge color
    $updates = $site->pending_updates_count ?? 0;
    $updateBadgeColor = $updates === 0 ? 'bg-green-500' : ($updates <= 5 ? 'bg-orange-500' : 'bg-red-500');

    // Uptime status
    $uptimeColor = 'text-gray-300';
    if ($site->uptimeMonitor) {
        if ($site->is_up === true) {
            $uptimeColor = 'text-green-500';
        } elseif ($site->is_up === false) {
            $uptimeColor = 'text-red-500';
        } else {
            $uptimeColor = 'text-yellow-500';
        }
    }

    // SSL status
    $sslColor = 'text-gray-300';
    if ($site->sslCertificate) {
        $cert = $site->sslCertificate;
        if ($cert->status === 'valid') {
            $sslColor = 'text-green-500';
        } elseif ($cert->status === 'expiring_soon') {
            $sslColor = 'text-yellow-500';
        } else {
            $sslColor = 'text-red-500';
        }
    }

    // Response time
    $responseColor = 'text-gray-300';
    if ($site->uptimeMonitor && $site->uptimeMonitor->avg_response_time) {
        $rt = $site->uptimeMonitor->avg_response_time;
        if ($rt < 500) {
            $responseColor = 'text-green-500';
        } elseif ($rt <= 2000) {
            $responseColor = 'text-yellow-500';
        } else {
            $responseColor = 'text-red-500';
        }
    }

    // Performance
    $perfColor = 'text-gray-300';
    if ($site->performanceMonitor && $site->performanceMonitor->latest_mobile_score !== null) {
        $score = $site->performanceMonitor->latest_mobile_score;
        if ($score >= 90) {
            $perfColor = 'text-green-500';
        } elseif ($score >= 50) {
            $perfColor = 'text-yellow-500';
        } else {
            $perfColor = 'text-red-500';
        }
    }

    // Links
    $linksColor = 'text-gray-300';
    if ($site->linkMonitor) {
        $broken = $site->linkMonitor->broken_links ?? 0;
        if ($broken === 0) {
            $linksColor = 'text-green-500';
        } elseif ($broken <= 5) {
            $linksColor = 'text-yellow-500';
        } else {
            $linksColor = 'text-red-500';
        }
    }

    // Domain expiry
    $domainColor = 'text-gray-300';
    if ($site->domainMonitor && $site->domainMonitor->expires_at) {
        $daysLeft = (int) now()->diffInDays($site->domainMonitor->expires_at, false);
        if ($daysLeft < 0) {
            $domainColor = 'text-red-500';
        } elseif ($daysLeft <= 30) {
            $domainColor = 'text-yellow-500';
        } else {
            $domainColor = 'text-green-500';
        }
    }

    // Plugins (update count)
    $pluginsColor = $updates === 0 ? 'text-green-500' : ($updates <= 5 ? 'text-yellow-500' : 'text-red-500');

    // Users
    $usersCount = $site->site_users_count ?? 0;
    $usersColor = $usersCount > 0 ? 'text-green-500' : 'text-gray-300';

    // WordPress connected
    $wpConnColor = $site->is_connected ? 'text-green-500' : 'text-gray-300';

    // Backup
    $backupColor = 'text-gray-300';
    if ($site->backupConfig) {
        $bc = $site->backupConfig;
        if ($bc->last_backup_status === 'failed') {
            $backupColor = 'text-red-500';
        } elseif ($bc->last_backup_at && $bc->last_backup_at->diffInDays(now()) > 2) {
            $backupColor = 'text-yellow-500';
        } elseif ($bc->last_backup_at) {
            $backupColor = 'text-green-500';
        }
    }

    // WP Version
    $wpVerColor = 'text-gray-300';
    if ($site->wp_version) {
        if ($site->core_update_version) {
            $wpVerColor = 'text-yellow-500';
        } else {
            $wpVerColor = 'text-green-500';
        }
    }

    // Health bar
    $healthScore = $site->health_score ?? 0;
    $healthWidth = max(0, min(100, $healthScore));
    $healthBarColor = $healthScore >= 90 ? 'bg-green-500' : ($healthScore >= 70 ? 'bg-yellow-500' : 'bg-red-500');

    $isSelected = in_array($site->id, $selectedSites);
@endphp

<div
    class="group flex items-center gap-3 border-b border-gray-100 px-4 py-2.5 transition hover:bg-gray-50 {{ $site->is_up === false ? 'bg-red-50/30' : '' }}"
    data-site-id="{{ $site->id }}"
    wire:key="site-{{ $site->id }}"
>
    {{-- Drag Handle --}}
    @if($reordering)
        <div class="drag-handle flex-shrink-0 cursor-grab text-gray-300 hover:text-gray-500 active:cursor-grabbing">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/>
            </svg>
        </div>
    @endif

    {{-- Checkbox --}}
    <div class="flex h-6 w-6 flex-shrink-0 items-center justify-center">
        <input
            type="checkbox"
            wire:click="toggleSiteSelection({{ $site->id }})"
            @checked($isSelected)
            class="h-4 w-4 cursor-pointer rounded border-gray-300 text-purple-600 focus:ring-purple-500 {{ $isSelected ? '' : 'opacity-0 group-hover:opacity-100' }} transition"
        />
    </div>

    {{-- Site Identity --}}
    <div class="min-w-0 flex-1 flex items-center gap-2">
        <a href="{{ route('sites.overview', $site) }}"
           class="truncate text-sm font-medium hover:opacity-80"
           style="color: {{ $site->siteStatus?->color ?? '#111827' }}"
           @if($site->siteStatus) title="{{ $site->siteStatus->name }}" @endif
        >{{ $site->domain }}</a>
    </div>

    {{-- Updates + Plugin count + Quick actions --}}
    <div class="flex flex-shrink-0 items-center gap-2">
        @if($site->is_connected && $updates > 0)
            <span class="hidden h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold leading-none text-white lg:inline-flex {{ $updateBadgeColor }}" title="{{ $updates }} updates available">
                {{ $updates }}
            </span>
            <div class="mx-0.5 hidden h-4 w-px bg-gray-200 lg:block"></div>
        @endif
        <span class="hidden text-xs text-gray-500 lg:inline" title="{{ $site->site_plugins_count }} plugins">
            {{ $site->site_plugins_count ?? 0 }}p
        </span>

        <button
            wire:click="syncSite({{ $site->id }})"
            class="hidden rounded p-1 text-gray-400 transition hover:bg-gray-100 hover:text-purple-600 lg:inline-flex"
            title="Sync site"
        >
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        </button>

        <a
            href="{{ $site->url }}"
            target="_blank"
            rel="noopener"
            class="hidden rounded p-1 text-gray-400 transition hover:bg-gray-100 hover:text-purple-600 lg:inline-flex"
            title="Open site"
        >
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
        </a>

        <div class="mx-3.5 hidden h-4 w-px bg-gray-200 lg:block"></div>
    </div>

    {{-- Status Icons (hidden below lg) --}}
    <div class="hidden items-center gap-3 lg:flex">
        {{-- 1. Uptime --}}
        <x-ui.hovercard>
            <x-slot:trigger>
                <svg class="h-5 w-5 {{ $uptimeColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </x-slot:trigger>
            <x-hovercards.uptime :site="$site" />
        </x-ui.hovercard>

        {{-- 2. SSL --}}
        <x-ui.hovercard>
            <x-slot:trigger>
                <svg class="h-5 w-5 {{ $sslColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            </x-slot:trigger>
            <x-hovercards.ssl :site="$site" />
        </x-ui.hovercard>

        {{-- 3. Response Time --}}
        <x-ui.hovercard>
            <x-slot:trigger>
                <svg class="h-5 w-5 {{ $responseColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </x-slot:trigger>
            <x-hovercards.response-time :site="$site" />
        </x-ui.hovercard>

        {{-- 4. Performance --}}
        <x-ui.hovercard>
            <x-slot:trigger>
                <svg class="h-5 w-5 {{ $perfColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </x-slot:trigger>
            <x-hovercards.analytics :site="$site" />
        </x-ui.hovercard>

        {{-- 5. Links --}}
        <x-ui.hovercard>
            <x-slot:trigger>
                <svg class="h-5 w-5 {{ $linksColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
            </x-slot:trigger>
            <x-hovercards.links :site="$site" />
        </x-ui.hovercard>

        {{-- 6. Domain --}}
        <x-ui.hovercard>
            <x-slot:trigger>
                <svg class="h-5 w-5 {{ $domainColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </x-slot:trigger>
            <x-hovercards.domain :site="$site" />
        </x-ui.hovercard>

        <div class="mx-3.5 h-4 w-px bg-gray-200"></div>

        {{-- 7. Plugins/Updates --}}
        <x-ui.hovercard>
            <x-slot:trigger>
                <svg class="h-5 w-5 {{ $pluginsColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/></svg>
            </x-slot:trigger>
            <x-hovercards.plugins :site="$site" />
        </x-ui.hovercard>

        {{-- 8. Users --}}
        <x-ui.hovercard>
            <x-slot:trigger>
                <svg class="h-5 w-5 {{ $usersColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            </x-slot:trigger>
            <x-hovercards.users :site="$site" />
        </x-ui.hovercard>

        {{-- 9. WordPress Connected --}}
        <x-ui.hovercard>
            <x-slot:trigger>
                <svg class="h-5 w-5 {{ $wpConnColor }}" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zM3.5 12c0-1.19.25-2.32.69-3.35l3.81 10.44A8.51 8.51 0 013.5 12zm8.5 8.5c-.83 0-1.64-.12-2.4-.34l2.55-7.41 2.61 7.15c.02.04.04.07.06.1-.89.32-1.84.5-2.82.5zm1.1-12.47c.51-.03.97-.08.97-.08.46-.05.4-.72-.05-.7 0 0-1.37.11-2.26.11-.83 0-2.24-.11-2.24-.11-.46-.02-.51.68-.05.7 0 0 .43.06.89.08l1.32 3.61-1.85 5.56-3.08-9.17c.51-.03.97-.08.97-.08.46-.05.4-.72-.05-.7 0 0-1.37.11-2.26.11-.16 0-.35 0-.55-.01A8.49 8.49 0 0112 3.5c2.13 0 4.07.78 5.56 2.07-.04 0-.07-.01-.11-.01-1.39 0-2.08 1.07-2.08 1.9 0 .7.38 1.29.78 2 .3.52.65 1.19.65 2.16 0 .67-.26 1.45-.6 2.53l-.79 2.63-2.86-8.75zM16.62 18.77l2.59-7.47c.48-1.21.64-2.17.64-3.03 0-.31-.02-.6-.06-.87A8.48 8.48 0 0120.5 12a8.51 8.51 0 01-3.88 6.77z"/></svg>
            </x-slot:trigger>
            <x-hovercards.wordpress :site="$site" />
        </x-ui.hovercard>

        <div class="mx-3.5 h-4 w-px bg-gray-200"></div>

        {{-- 10. Backup --}}
        <x-ui.hovercard>
            <x-slot:trigger>
                <svg class="h-5 w-5 {{ $backupColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
            </x-slot:trigger>
            <x-hovercards.backup :site="$site" />
        </x-ui.hovercard>

        {{-- 11. WP Version --}}
        <x-ui.hovercard>
            <x-slot:trigger>
                <svg class="h-5 w-5 {{ $wpVerColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </x-slot:trigger>
            <x-hovercards.wp-version :site="$site" />
        </x-ui.hovercard>

        {{-- 12. Reports --}}
        <x-ui.hovercard>
            <x-slot:trigger>
                <svg class="h-5 w-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </x-slot:trigger>
            <x-hovercards.reports :site="$site" />
        </x-ui.hovercard>
    </div>

    {{-- Health Bar --}}
    <x-ui.tooltip :text="'Health: ' . $healthScore . '/100'">
        <div class="hidden w-16 flex-shrink-0 sm:block">
            <div class="h-2 w-full rounded-full bg-gray-200">
                <div class="h-2 rounded-full {{ $healthBarColor }}" style="width: {{ $healthWidth }}%"></div>
            </div>
        </div>
    </x-ui.tooltip>

    {{-- Three-dot Dropdown --}}
    <x-ui.dropdown align="right" width="48">
        <x-slot:trigger>
            <button class="rounded p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/></svg>
            </button>
        </x-slot:trigger>

        {{-- Navigation links --}}
        <a href="{{ route('sites.overview', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Overview</a>
        <a href="{{ route('sites.plugins', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Plugins</a>
        <a href="{{ route('sites.backups', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Backups</a>
        <a href="{{ route('sites.uptime', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Uptime</a>
        <a href="{{ route('sites.performance', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Performance</a>
        <a href="{{ route('sites.settings', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Settings</a>

        <div class="my-1 border-t border-gray-100"></div>

        {{-- Action buttons --}}
        <button wire:click="runBackup({{ $site->id }})" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Run Backup</button>
        <button wire:click="checkNow({{ $site->id }})" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Check Uptime</button>
        <button wire:click="syncSite({{ $site->id }})" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Sync Site</button>

        <div class="my-1 border-t border-gray-100"></div>

        {{-- Management actions --}}
        <button wire:click="startRename({{ $site->id }}, '{{ addslashes($site->name) }}')" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Rename</button>
        <a href="{{ route('sites.settings', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Edit Settings</a>

        {{-- Status assignment --}}
        @if($siteStatuses->isNotEmpty())
            <div class="my-1 border-t border-gray-100"></div>
            <div class="px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-gray-400">Status</div>
            @foreach($siteStatuses as $status)
                <button wire:click="setSiteStatus({{ $site->id }}, {{ $status->id }})" class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                    <span class="h-2 w-2 rounded-full shrink-0" style="background-color: {{ $status->color }}"></span>
                    {{ $status->name }}
                    @if($site->site_status_id === $status->id)
                        <svg class="ml-auto h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    @endif
                </button>
            @endforeach
            <button wire:click="setSiteStatus({{ $site->id }}, null)" class="block w-full px-4 py-2 text-left text-sm text-gray-500 hover:bg-gray-50">Clear Status</button>
        @endif

        <div class="my-1 border-t border-gray-100"></div>
        <button wire:click="confirmDelete({{ $site->id }}, '{{ addslashes($site->name) }}')" class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50">Delete Site</button>
    </x-ui.dropdown>
</div>
