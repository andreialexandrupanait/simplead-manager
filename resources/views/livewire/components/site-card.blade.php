<div class="group relative rounded-xl p-5 shadow-sm transition hover:shadow-md {{ !$site->is_up ? 'bg-red-50/30 ring-1 ring-red-300' : 'bg-white ring-1 ring-gray-950/5' }}">
    <div class="flex items-start justify-between">
        {{-- Site info --}}
        <a href="{{ route('sites.overview', $site) }}" class="flex items-center gap-3 min-w-0">
            <img src="https://www.google.com/s2/favicons?domain={{ $site->domain }}&sz=32"
                 alt="" class="h-8 w-8 rounded-lg ring-1 ring-gray-200">
            <div class="min-w-0">
                <h3 class="truncate text-sm font-semibold text-gray-900 group-hover:text-purple-600 transition">
                    {{ $site->name }}
                </h3>
                <p class="truncate text-xs text-gray-500">{{ $site->domain }}</p>
            </div>
        </a>

        {{-- Health score + warning badge --}}
        <div class="flex items-center gap-1.5">
            @php
                $score = $site->health_score;
                $color = $score >= 90 ? 'text-green-500' : ($score >= 70 ? 'text-yellow-500' : 'text-red-500');
            @endphp
            @if($score < 70)
                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-red-100" title="Low health score">
                    <svg class="h-3 w-3 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                </span>
            @endif
            <span class="text-lg font-bold {{ $color }}">{{ $score }}</span>
        </div>
    </div>

    {{-- Status icons row --}}
    <div class="mt-4 flex items-center gap-4 border-t pt-4 text-xs text-gray-500">
        {{-- Uptime --}}
        <x-ui.tooltip text="Uptime: {{ $site->uptime_percentage ?? 'N/A' }}%">
            <div class="flex items-center gap-1">
                <span class="h-2 w-2 rounded-full {{ $site->is_up ? 'bg-green-500' : 'bg-red-500' }}"></span>
                {{ $site->uptime_percentage ?? '—' }}%
            </div>
        </x-ui.tooltip>

        {{-- Response time --}}
        @if($site->uptimeMonitor?->avg_response_time)
            <x-ui.tooltip text="Avg response time: {{ $site->uptimeMonitor->avg_response_time }}ms">
                <div class="flex items-center gap-1">
                    <svg class="h-3.5 w-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    {{ $site->uptimeMonitor->avg_response_time }}ms
                </div>
            </x-ui.tooltip>
        @endif

        {{-- SSL --}}
        @php
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
        @endphp
        <x-ui.tooltip text="{{ $sslTitle }}">
            <div class="flex items-center gap-1">
                <svg class="h-3.5 w-3.5 {{ $sslColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
                @if($sslCert && $sslCert->days_remaining !== null)
                    <span class="{{ $sslColor }}">{{ $sslCert->days_remaining }}d</span>
                @endif
            </div>
        </x-ui.tooltip>

        {{-- Updates available --}}
        @if($site->pending_updates_count > 0)
            <x-ui.tooltip text="{{ $site->pending_updates_count }} updates available">
                <div class="flex items-center gap-1 text-yellow-600">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    {{ $site->pending_updates_count }}
                </div>
            </x-ui.tooltip>
        @endif

        {{-- Performance score --}}
        @php
            $perfMonitor = $site->performanceMonitor;
            $perfScore = $perfMonitor?->latest_mobile_score;
            $perfColor = $perfScore === null ? 'text-gray-400' : ($perfScore >= 90 ? 'text-green-500' : ($perfScore >= 50 ? 'text-yellow-500' : 'text-red-500'));
        @endphp
        <x-ui.tooltip text="Performance: {{ $perfScore ?? 'N/A' }}">
            <div class="flex items-center gap-1">
                <svg class="h-3.5 w-3.5 {{ $perfColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                @if($perfScore !== null)
                    <span class="{{ $perfColor }}">{{ $perfScore }}</span>
                @endif
            </div>
        </x-ui.tooltip>

        {{-- Broken links --}}
        @php
            $linkMon = $site->linkMonitor;
            $brokenCount = $linkMon?->broken_links;
            $linkColor = $brokenCount === null ? 'text-gray-400' : ($brokenCount === 0 ? 'text-green-500' : ($brokenCount <= 5 ? 'text-yellow-500' : 'text-red-500'));
        @endphp
        @if($linkMon && $linkMon->last_scan_at)
            <x-ui.tooltip text="Broken links: {{ $brokenCount ?? 0 }}">
                <div class="flex items-center gap-1">
                    <svg class="h-3.5 w-3.5 {{ $linkColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                    </svg>
                    @if($brokenCount !== null)
                        <span class="{{ $linkColor }}">{{ $brokenCount }}</span>
                    @endif
                </div>
            </x-ui.tooltip>
        @endif

        {{-- Backup status --}}
        @php
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
            $backupIconColor = match($backupIndicator) {
                'green' => 'text-green-500',
                'yellow' => 'text-yellow-500',
                'red' => 'text-red-500',
                default => 'text-gray-400',
            };
        @endphp
        <x-ui.tooltip text="{{ $backupTitle }}">
            <div class="flex items-center gap-1">
                <svg class="h-3.5 w-3.5 {{ $backupIconColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
                </svg>
            </div>
        </x-ui.tooltip>

        {{-- WordPress connection --}}
        <x-ui.tooltip text="WordPress {{ $site->is_connected ? 'connected' : 'not connected' }}">
            <div class="flex items-center gap-1">
                <span class="h-2 w-2 rounded-full {{ $site->is_connected ? 'bg-blue-500' : 'bg-gray-300' }}"></span>
                <span class="text-xs {{ $site->is_connected ? 'text-blue-600' : 'text-gray-400' }}">WP</span>
            </div>
        </x-ui.tooltip>

        <div class="flex-1"></div>

        {{-- Client tag --}}
        @if($site->client)
            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                {{ $site->client->name }}
            </span>
        @endif
    </div>

    {{-- Quick actions overlay on hover --}}
    <div class="pointer-events-none absolute inset-x-0 bottom-0 flex items-center justify-center gap-2 rounded-b-xl bg-gradient-to-t from-white/95 via-white/80 to-transparent px-4 pb-3 pt-8 opacity-0 transition group-hover:pointer-events-auto group-hover:opacity-100">
        <a
            href="{{ route('sites.overview', $site) }}"
            class="rounded-md bg-purple-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-purple-700 transition"
        >
            View
        </a>
        <button
            wire:click="runBackup"
            class="rounded-md bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50 transition"
        >
            Backup
        </button>
        <button
            wire:click="checkNow"
            class="rounded-md bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50 transition"
        >
            Check
        </button>
    </div>
</div>
