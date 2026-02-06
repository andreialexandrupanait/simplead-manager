@php
    // Health score
    $score = $site->health_score;
    $scoreColor = $score >= 90 ? 'bg-green-500' : ($score >= 70 ? 'bg-yellow-500' : 'bg-red-500');
    $scoreRing = $score >= 90 ? 'ring-green-500/20' : ($score >= 70 ? 'ring-yellow-500/20' : 'ring-red-500/20');

    // SSL Certificate
    $sslCert = $site->sslCertificate;
    $sslColor = $sslCert
        ? match($sslCert->status_color) {
            'green' => 'text-green-500',
            'yellow' => 'text-yellow-500',
            'red' => 'text-red-500',
            default => 'text-gray-400',
        }
        : ($site->ssl_ok ? 'text-green-500' : 'text-red-500');
    $sslTitle = $sslCert && $sslCert->expires_at
        ? 'SSL expires: ' . $sslCert->expires_at->format('M d, Y')
        : 'SSL expires: ' . ($site->ssl_expiry?->format('M d, Y') ?? 'N/A');

    // Backup status
    $backupCfg = $site->backupConfig;
    if (!$backupCfg || !$backupCfg->is_enabled) {
        $backupIndicator = 'gray';
        $backupTitle = 'No backup configured';
    } elseif ($backupCfg->last_backup_status === 'failed') {
        $backupIndicator = 'red';
        $backupTitle = 'Last backup failed';
    } elseif ($site->backup_ok && $site->last_backup_at) {
        $maxHours = match($backupCfg->frequency) {
            'daily' => 26,
            'weekly' => 170,
            'monthly' => 745,
            default => 26,
        };
        if ($site->last_backup_at->diffInHours(now()) > $maxHours) {
            $backupIndicator = 'yellow';
            $backupTitle = 'Backup overdue — last: ' . $site->last_backup_at->diffForHumans();
        } else {
            $backupIndicator = 'green';
            $backupTitle = 'Last backup: ' . $site->last_backup_at->diffForHumans();
        }
    } else {
        $backupIndicator = 'yellow';
        $backupTitle = 'Pending first backup';
    }
@endphp

<div class="group relative flex flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 transition hover:shadow-lg dark:bg-gray-800 dark:ring-gray-700">
    {{-- Header with Site Info --}}
    <div class="relative bg-gradient-to-br from-purple-50 to-blue-50 p-4 dark:from-gray-700 dark:to-gray-800">
        {{-- Health Score Badge (top-right) --}}
        <div class="absolute right-4 top-4 flex h-12 w-12 items-center justify-center rounded-full {{ $scoreColor }} ring-4 {{ $scoreRing }}">
            <span class="text-sm font-bold text-white">{{ $score }}</span>
        </div>

        {{-- Site Identity --}}
        <a href="{{ route('sites.overview', $site) }}" class="flex items-start gap-3">
            {{-- Favicon/Thumbnail --}}
            <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white shadow-md ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-600">
                <img src="https://www.google.com/s2/favicons?domain={{ $site->domain }}&sz=64"
                     alt="{{ $site->name }}"
                     class="h-10 w-10 object-contain">
            </div>

            {{-- Site Name & Domain --}}
            <div class="min-w-0 flex-1 pt-1">
                <h3 class="truncate text-base font-semibold text-gray-900 transition-colors group-hover:text-purple-600 dark:text-gray-100 dark:group-hover:text-purple-400">
                    {{ $site->name }}
                </h3>
                <p class="mt-0.5 truncate text-sm text-gray-600 dark:text-gray-400">
                    {{ $site->domain }}
                </p>

                {{-- Status & Client --}}
                <div class="mt-2 flex items-center gap-2">
                    @if($site->siteStatus)
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                              style="background-color: {{ $site->siteStatus->color }}20; color: {{ $site->siteStatus->color }}; border-color: {{ $site->siteStatus->color }}40;">
                            {{ $site->siteStatus->name }}
                        </span>
                    @endif
                    @if($site->client)
                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                            {{ $site->client->name }}
                        </span>
                    @endif
                </div>
            </div>
        </a>
    </div>

    {{-- Main Content --}}
    <div class="flex-1 p-4">


        {{-- Key Metrics Grid --}}
        <div class="grid grid-cols-2 gap-3">
            {{-- Uptime Status --}}
            <x-ui.tooltip text="Uptime: {{ $site->uptime_percentage ?? 'N/A' }}%">
                <div class="flex items-center gap-3 rounded-lg bg-gray-50 p-3 transition hover:bg-gray-100 dark:bg-gray-700/50 dark:hover:bg-gray-700">
                    <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg {{ $site->is_up ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600' }}">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Uptime</p>
                        <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $site->uptime_percentage ?? '—' }}%</p>
                    </div>
                </div>
            </x-ui.tooltip>

            {{-- SSL Certificate --}}
            <x-ui.tooltip text="{{ $sslTitle }}">
                <div class="flex items-center gap-3 rounded-lg bg-gray-50 p-3 transition hover:bg-gray-100 dark:bg-gray-700/50 dark:hover:bg-gray-700">
                    <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg {{ $sslColor === 'text-green-500' ? 'bg-green-100 text-green-600' : ($sslColor === 'text-yellow-500' ? 'bg-yellow-100 text-yellow-600' : 'bg-gray-100 text-gray-400') }}">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">SSL</p>
                        <p class="text-sm font-bold text-gray-900 dark:text-gray-100">
                            @if($sslCert && $sslCert->days_remaining !== null)
                                {{ $sslCert->days_remaining }}d
                            @else
                                {{ $site->ssl_ok ? 'Valid' : 'N/A' }}
                            @endif
                        </p>
                    </div>
                </div>
            </x-ui.tooltip>

            {{-- Performance Score --}}
            @php
                $perfMonitor = $site->performanceMonitor;
                $perfScore = $perfMonitor?->latest_mobile_score;
                $perfColor = $perfScore === null ? 'text-gray-400' : ($perfScore >= 90 ? 'text-green-500' : ($perfScore >= 50 ? 'text-yellow-500' : 'text-red-500'));
                $perfBg = $perfScore === null ? 'bg-gray-100 text-gray-400' : ($perfScore >= 90 ? 'bg-green-100 text-green-600' : ($perfScore >= 50 ? 'bg-yellow-100 text-yellow-600' : 'bg-red-100 text-red-600'));
            @endphp
            <x-ui.tooltip text="Performance: {{ $perfScore ?? 'N/A' }}">
                <div class="flex items-center gap-3 rounded-lg bg-gray-50 p-3 transition hover:bg-gray-100 dark:bg-gray-700/50 dark:hover:bg-gray-700">
                    <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg {{ $perfBg }}">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Speed</p>
                        <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $perfScore ?? '—' }}</p>
                    </div>
                </div>
            </x-ui.tooltip>

            {{-- Backup Status --}}
            @php
                $backupBg = match($backupIndicator) {
                    'green' => 'bg-green-100 text-green-600',
                    'yellow' => 'bg-yellow-100 text-yellow-600',
                    'red' => 'bg-red-100 text-red-600',
                    default => 'bg-gray-100 text-gray-400',
                };
            @endphp
            <x-ui.tooltip text="{{ $backupTitle }}">
                <div class="flex items-center gap-3 rounded-lg bg-gray-50 p-3 transition hover:bg-gray-100 dark:bg-gray-700/50 dark:hover:bg-gray-700">
                    <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg {{ $backupBg }}">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Backup</p>
                        <p class="text-sm font-bold text-gray-900 dark:text-gray-100">
                            @if($site->last_backup_at)
                                {{ $site->last_backup_at->diffForHumans(null, true, true) }}
                            @else
                                {{ $backupIndicator === 'green' ? 'OK' : ($backupIndicator === 'red' ? 'Failed' : '—') }}
                            @endif
                        </p>
                    </div>
                </div>
            </x-ui.tooltip>
        </div>

        {{-- Additional Info --}}
        <div class="mt-3 flex items-center justify-between text-xs">
            <div class="flex items-center gap-3">
                {{-- WordPress Status --}}
                <x-ui.tooltip text="WordPress {{ $site->is_connected ? 'connected' : 'not connected' }}">
                    <div class="flex items-center gap-1.5">
                        <svg class="h-4 w-4 {{ $site->is_connected ? 'text-blue-500' : 'text-gray-400' }}" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zM3.5 12c0-1.19.25-2.32.69-3.35l3.81 10.44A8.51 8.51 0 013.5 12zm8.5 8.5c-.83 0-1.64-.12-2.4-.34l2.55-7.41 2.61 7.15c.02.04.04.07.06.1-.89.32-1.84.5-2.82.5zm1.1-12.47c.51-.03.97-.08.97-.08.46-.05.4-.72-.05-.7 0 0-1.37.11-2.26.11-.83 0-2.24-.11-2.24-.11-.46-.02-.51.68-.05.7 0 0 .43.06.89.08l1.32 3.61-1.85 5.56-3.08-9.17c.51-.03.97-.08.97-.08.46-.05.4-.72-.05-.7 0 0-1.37.11-2.26.11-.16 0-.35 0-.55-.01A8.49 8.49 0 0112 3.5c2.13 0 4.07.78 5.56 2.07-.04 0-.07-.01-.11-.01-1.39 0-2.08 1.07-2.08 1.9 0 .7.38 1.29.78 2 .3.52.65 1.19.65 2.16 0 .67-.26 1.45-.6 2.53l-.79 2.63-2.86-8.75z"/>
                        </svg>
                        <span class="{{ $site->is_connected ? 'text-blue-600' : 'text-gray-500' }}">WP</span>
                    </div>
                </x-ui.tooltip>

                {{-- Updates Badge --}}
                @if($site->pending_updates_count > 0)
                    <x-ui.tooltip text="{{ $site->pending_updates_count }} updates available">
                        <span class="inline-flex items-center gap-1 rounded-full bg-yellow-100 px-2 py-1 text-yellow-700">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            <span class="font-medium">{{ $site->pending_updates_count }}</span>
                        </span>
                    </x-ui.tooltip>
                @endif
            </div>

            {{-- Response Time --}}
            @if($site->uptimeMonitor?->avg_response_time)
                <x-ui.tooltip text="Avg response time">
                    <div class="flex items-center gap-1 text-gray-500">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>{{ $site->uptimeMonitor->avg_response_time }}ms</span>
                    </div>
                </x-ui.tooltip>
            @endif
        </div>
    </div>

    {{-- Footer with Actions --}}
    <div class="border-t border-gray-100 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/50">
        <div class="flex items-center gap-2">
            <a href="{{ route('sites.overview', $site) }}"
               class="flex-1 rounded-lg bg-purple-600 px-3 py-2 text-center text-sm font-medium text-white transition hover:bg-purple-700">
                View Details
            </a>
            <button wire:click="runBackup"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                    title="Run backup">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
                </svg>
            </button>
            <button wire:click="checkNow"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                    title="Check uptime">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
            </button>
        </div>
    </div>

</div>
