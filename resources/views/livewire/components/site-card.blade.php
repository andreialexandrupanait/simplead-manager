<div class="group rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 hover:shadow-md transition">
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

        {{-- Health score --}}
        <div class="flex items-center gap-1.5">
            @php
                $score = $site->health_score;
                $color = $score >= 90 ? 'text-green-500' : ($score >= 70 ? 'text-yellow-500' : 'text-red-500');
            @endphp
            <span class="text-lg font-bold {{ $color }}">{{ $score }}</span>
        </div>
    </div>

    {{-- Status icons row --}}
    <div class="mt-4 flex items-center gap-4 border-t pt-4 text-xs text-gray-500">
        {{-- Uptime --}}
        <div class="flex items-center gap-1" title="Uptime: {{ $site->uptime_percentage ?? 'N/A' }}%">
            <span class="h-2 w-2 rounded-full {{ $site->is_up ? 'bg-green-500' : 'bg-red-500' }}"></span>
            {{ $site->uptime_percentage ?? '—' }}%
        </div>

        {{-- Response time --}}
        @if($site->uptimeMonitor?->avg_response_time)
            <div class="flex items-center gap-1" title="Avg response time">
                <svg class="h-3.5 w-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                {{ $site->uptimeMonitor->avg_response_time }}ms
            </div>
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
        <div class="flex items-center gap-1" title="{{ $sslTitle }}">
            <svg class="h-3.5 w-3.5 {{ $sslColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            @if($sslCert && $sslCert->days_remaining !== null)
                <span class="{{ $sslColor }}">{{ $sslCert->days_remaining }}d</span>
            @endif
        </div>

        {{-- Updates available --}}
        @if($site->pending_updates_count > 0)
            <div class="flex items-center gap-1 text-yellow-600">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                {{ $site->pending_updates_count }}
            </div>
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
        <div class="flex items-center gap-1" title="{{ $backupTitle }}">
            <svg class="h-3.5 w-3.5 {{ $backupIconColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
            </svg>
        </div>

        {{-- WordPress connection --}}
        <div class="flex items-center gap-1" title="WordPress {{ $site->is_connected ? 'connected' : 'not connected' }}">
            <span class="h-2 w-2 rounded-full {{ $site->is_connected ? 'bg-blue-500' : 'bg-gray-300' }}"></span>
            <span class="text-xs {{ $site->is_connected ? 'text-blue-600' : 'text-gray-400' }}">WP</span>
        </div>

        <div class="flex-1"></div>

        {{-- Client tag --}}
        @if($site->client)
            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                {{ $site->client->name }}
            </span>
        @endif
    </div>
</div>
