@php
    $upTone = $site->uptimeMonitor ? ($site->is_up ? 'ok' : 'bad') : 'dark';
@endphp

<x-ui.module-card title="Uptime" icon="activity" :tone="$upTone">
    @if($site->uptimeMonitor)
        {{-- Current Status --}}
        <x-ui.stat-box label="Status">
            <span class="{{ $site->is_up ? 'mval-ok' : 'mval-bad' }}">{{ $site->is_up ? 'Online' : 'Offline' }}</span>
        </x-ui.stat-box>

        {{-- Stats --}}
        <div class="mg2">
            <x-ui.stat-box label="30-Day Uptime">{{ number_format($site->uptimeMonitor->uptime_30d ?? 0, 2) }}%</x-ui.stat-box>
            <x-ui.stat-box label="Avg Response">{{ $site->uptimeMonitor->avg_response_time ?? 0 }}ms</x-ui.stat-box>
        </div>

        @if($site->uptimeMonitor->last_checked_at)
            <p class="mt-2 text-xs text-gray-400">Checked {{ $site->uptimeMonitor->last_checked_at->diffForHumans() }}</p>
        @endif

        <a href="{{ route('sites.uptime', $site) }}" class="mact">Details →</a>
    @else
        <div class="py-2 text-center">
            <p class="text-sm text-gray-500">Not monitored</p>
            <a href="{{ route('sites.uptime', $site) }}" class="mt-1 inline-block text-xs text-accent-600 hover:text-accent-700">
                Enable Monitoring →
            </a>
        </div>
    @endif
</x-ui.module-card>
