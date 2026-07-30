{{-- The five fleet numbers, lifted out of the classic dashboard so the landing
     page can lead with them instead of with a wall of alerts. Expects $stats and
     $trends — both are computed properties on the component using it. --}}
    {{-- Section 1: Mini Stat Cards --}}
    @php
        $stats = $this->stats;
        $trends = $this->trends;

        $uptimeColor = 'text-green-600';
        $uptimeBg = 'bg-green-50';
        $uptimeIcon = 'text-green-500';
        if ($stats['avg_uptime'] !== null) {
            if ($stats['avg_uptime'] < 95) { $uptimeColor = 'text-red-600'; $uptimeBg = 'bg-red-50'; $uptimeIcon = 'text-red-500'; }
            elseif ($stats['avg_uptime'] < 99) { $uptimeColor = 'text-yellow-600'; $uptimeBg = 'bg-yellow-50'; $uptimeIcon = 'text-yellow-500'; }
        }

        $bytes = $stats['backup_storage_bytes'] ?? 0;
        if ($bytes >= 1073741824) {
            $storageLabel = round($bytes / 1073741824, 1) . ' GB';
        } elseif ($bytes >= 1048576) {
            $storageLabel = round($bytes / 1048576, 0) . ' MB';
        } else {
            $storageLabel = '0 MB';
        }

        // Helper: trend arrow HTML (inline, safe to use with {!! !!})
        $trendArrow = function (string $direction, bool $invertColors = false): string {
            if ($direction === 'up') {
                $color = $invertColors ? 'text-red-500' : 'text-green-500';
                return '<svg class="inline-block h-3 w-3 ' . $color . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7"/></svg>';
            }
            if ($direction === 'down') {
                $color = $invertColors ? 'text-green-500' : 'text-red-500';
                return '<svg class="inline-block h-3 w-3 ' . $color . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>';
            }
            return '<svg class="inline-block h-3 w-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14"/></svg>';
        };
    @endphp
    @php
        // Smart link for Alerts card — route to the page that explains the dominant alert source
        if ($stats['sites_down'] > 0) {
            $alertsLink = route('uptime.index', ['filter' => 'down']);
        } elseif ($stats['failed_backups'] > 0) {
            $alertsLink = route('backups.index', ['filter' => 'failed']);
        } elseif (($stats['stale_backups'] ?? 0) > 0) {
            $alertsLink = route('backups.index', ['filter' => 'stale']);
        } else {
            $alertsLink = route('uptime.index');
        }
    @endphp
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        {{-- Sites --}}
        <a href="#sites" class="block h-full">
            <x-ui.card :padding="false" class="p-4 h-full transition hover:ring-accent-200">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $stats['sites_down'] > 0 ? 'bg-red-50' : 'bg-green-50' }}">
                        <x-icons.globe class="h-5 w-5 {{ $stats['sites_down'] > 0 ? 'text-red-500' : 'text-green-500' }}" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-base font-semibold text-gray-900">{{ $stats['total_sites'] }}</div>
                        <div class="text-xs text-gray-500">{{ __('Sites') }}</div>
                        <div class="mt-0.5 text-xs font-medium {{ $stats['sites_down'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                            {{ $stats['sites_down'] === 0 ? __('all operational') : $stats['sites_down'] . ' ' . __('down') }}
                        </div>
                        @if(($stats['disconnected_sites'] ?? 0) > 0)
                            <div class="mt-0.5 text-xs font-medium text-amber-600">
                                {{ $stats['disconnected_sites'] . ' ' . __('disconnected') }}
                            </div>
                        @endif
                    </div>
                </div>
            </x-ui.card>
        </a>

        {{-- Uptime --}}
        <a href="{{ route('uptime.index') }}" class="block h-full">
            <x-ui.card :padding="false" class="p-4 h-full transition hover:ring-accent-200">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $uptimeBg }}">
                        <x-icons.trending-up class="h-5 w-5 {{ $uptimeIcon }}" />
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-1">
                            <span class="text-base font-semibold {{ $uptimeColor }}">{{ $stats['avg_uptime'] !== null ? $stats['avg_uptime'] . '%' : '—' }}</span>
                            {!! $trendArrow($trends['uptime']['direction']) !!}
                        </div>
                        <div class="text-xs text-gray-500">{{ __('Uptime') }}</div>
                        <div class="mt-0.5 text-xs text-gray-400">{{ __('last 30 days') }}</div>
                    </div>
                </div>
            </x-ui.card>
        </a>

        {{-- Backup Storage --}}
        @php
            $hasFailed = $stats['failed_backups'] > 0;
            $hasStale = ($stats['stale_backups'] ?? 0) > 0;
            $backupAlert = $hasFailed || $hasStale;
            $iconBg = $backupAlert ? 'bg-red-50' : 'bg-accent-50';
            $iconColor = $backupAlert ? 'text-red-500' : 'text-accent-500';
            $valueColor = $backupAlert ? 'text-red-600' : 'text-accent-600';
        @endphp
        <a href="{{ route('backups.index') }}" class="block h-full">
            <x-ui.card :padding="false" class="p-4 h-full transition hover:ring-accent-200">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $iconBg }}">
                        <x-icons.hard-drive class="h-5 w-5 {{ $iconColor }}" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-base font-semibold {{ $valueColor }}">{{ $storageLabel }}</div>
                        <div class="text-xs text-gray-500">{{ __('Backup Storage') }}</div>
                        <div class="mt-0.5 flex items-center gap-1 text-xs {{ $backupAlert ? 'text-red-500 font-medium' : 'text-gray-400' }}">
                            @if($backupAlert)
                                @php
                                    $parts = [];
                                    if ($hasFailed) $parts[] = $stats['failed_backups'] . ' ' . __('failed');
                                    if ($hasStale) $parts[] = $stats['stale_backups'] . ' ' . __('stale');
                                @endphp
                                {{ implode(', ', $parts) }}
                            @else
                                {{ __('all healthy') }}
                            @endif
                            {!! $trendArrow($trends['failed_backups']['direction'], true) !!}
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </a>

        {{-- Backups Today --}}
        <a href="{{ route('backups.index') }}" class="block h-full">
            <x-ui.card :padding="false" class="p-4 h-full transition hover:ring-accent-200">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50">
                        <x-icons.check-circle class="h-5 w-5 text-blue-500" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-base font-semibold text-blue-600">{{ $stats['backups_today'] }}</div>
                        <div class="text-xs text-gray-500">{{ __('Backups Today') }}</div>
                        <div class="mt-0.5 text-xs text-gray-400">{{ __('completed') }}</div>
                    </div>
                </div>
            </x-ui.card>
        </a>

        {{-- Alerts --}}
        <a href="{{ $alertsLink }}" class="block h-full">
            <x-ui.card :padding="false" class="p-4 h-full transition hover:ring-accent-200">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $stats['total_alerts'] > 0 ? 'bg-red-50' : 'bg-green-50' }}">
                        @if($stats['total_alerts'] > 0)
                            <x-icons.alert-triangle class="h-5 w-5 text-red-500" />
                        @else
                            <x-icons.shield class="h-5 w-5 text-green-500" />
                        @endif
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-1">
                            <span class="text-base font-semibold {{ $stats['total_alerts'] > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $stats['total_alerts'] }}</span>
                            {{-- Alerts up = bad (invertColors) --}}
                            {!! $trendArrow($trends['pending_updates']['direction'], true) !!}
                        </div>
                        <div class="text-xs text-gray-500">{{ __('Alerts') }}</div>
                        <div class="mt-0.5 text-xs {{ $stats['total_alerts'] > 0 ? 'text-red-500 font-medium' : 'text-gray-400' }}">
                            @if($stats['total_alerts'] === 0)
                                {{ __('all clear') }}
                            @else
                                @php
                                    $parts = [];
                                    if ($stats['sites_down'] > 0) $parts[] = $stats['sites_down'] . ' ' . __('down');
                                    if ($stats['failed_backups'] > 0) $parts[] = $stats['failed_backups'] . ' ' . __('backup');
                                    if (($stats['stale_backups'] ?? 0) > 0) $parts[] = $stats['stale_backups'] . ' ' . __('stale');
                                @endphp
                                {{ implode(', ', $parts) }}
                            @endif
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </a>
    </div>
