@props(['site'])

@if($site->uptimeMonitor && $site->uptimeMonitor->avg_response_time)
    @php
        $rt = $site->uptimeMonitor->avg_response_time;
        $rtVariant = $rt < 500 ? 'green' : ($rt <= 2000 ? 'yellow' : 'red');
        $rtLabel = $rt < 500 ? 'Fast' : ($rt <= 2000 ? 'Moderate' : 'Slow');
    @endphp
    <div class="flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">Response Time</span>
        <x-ui.badge :variant="$rtVariant">{{ $rtLabel }}</x-ui.badge>
    </div>
    <div class="mt-3 space-y-1.5 text-xs">
        <div class="flex items-center justify-between">
            <span class="text-gray-500">Average response</span>
            <span class="font-medium text-gray-900">{{ $site->uptimeMonitor->avg_response_time }}ms</span>
        </div>
        @if($site->uptimeMonitor->last_response_time)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Last response</span>
                <span class="font-medium text-gray-900">{{ $site->uptimeMonitor->last_response_time }}ms</span>
            </div>
        @endif
        @if($site->uptimeMonitor->uptime_30d !== null)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Uptime (30d)</span>
                <span class="font-medium text-gray-900">{{ number_format($site->uptimeMonitor->uptime_30d, 2) }}%</span>
            </div>
        @endif
        @if($site->uptimeMonitor->check_interval)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Check interval</span>
                <span class="font-medium text-gray-900">{{ $site->uptimeMonitor->check_interval }}m</span>
            </div>
        @endif
        @if($site->uptimeMonitor->last_checked_at)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Last checked</span>
                <span class="font-medium text-gray-900">{{ $site->uptimeMonitor->last_checked_at->diffForHumans() }}</span>
            </div>
        @endif
    </div>
    <div class="mt-3 border-t border-gray-100 pt-3">
        <a href="{{ route('sites.uptime', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Uptime</a>
    </div>
@else
    <p class="text-sm text-gray-500">No data</p>
    <a href="{{ route('sites.uptime', $site) }}" class="mt-2 inline-block text-xs font-medium text-purple-600 hover:text-purple-800">View Uptime</a>
@endif
