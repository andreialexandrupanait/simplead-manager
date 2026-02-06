@props(['site'])

@if($site->uptimeMonitor)
    <div class="flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">Uptime</span>
        @php
            $state = $site->uptimeMonitor->current_state ?? 'unknown';
            $stateVariant = match($state) {
                'up' => 'green',
                'down' => 'red',
                'degraded' => 'yellow',
                default => 'gray',
            };
        @endphp
        <x-ui.badge :variant="$stateVariant">{{ ucfirst($state) }}</x-ui.badge>
    </div>
    <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
        <div>
            <span class="text-gray-500">24h</span>
            <span class="ml-1 font-medium text-gray-900">{{ $site->uptimeMonitor->uptime_24h !== null ? number_format($site->uptimeMonitor->uptime_24h, 2) . '%' : '--' }}</span>
        </div>
        <div>
            <span class="text-gray-500">7d</span>
            <span class="ml-1 font-medium text-gray-900">{{ $site->uptimeMonitor->uptime_7d !== null ? number_format($site->uptimeMonitor->uptime_7d, 2) . '%' : '--' }}</span>
        </div>
        <div>
            <span class="text-gray-500">30d</span>
            <span class="ml-1 font-medium text-gray-900">{{ $site->uptimeMonitor->uptime_30d !== null ? number_format($site->uptimeMonitor->uptime_30d, 2) . '%' : '--' }}</span>
        </div>
        <div>
            <span class="text-gray-500">Avg</span>
            <span class="ml-1 font-medium text-gray-900">{{ $site->uptimeMonitor->avg_response_time ? $site->uptimeMonitor->avg_response_time . 'ms' : '--' }}</span>
        </div>
    </div>
    @if($site->uptimeMonitor->last_checked_at)
        <p class="mt-2 text-xs text-gray-500">Last checked {{ $site->uptimeMonitor->last_checked_at->diffForHumans() }}</p>
    @endif
    @php
        $recentIncidents = $site->uptimeMonitor->incidents->sortByDesc('started_at')->take(3);
        $incidentCount30d = $site->uptimeMonitor->incidents->where('started_at', '>=', now()->subDays(30))->count();
    @endphp
    <div class="mt-2 text-xs">
        <div class="flex items-center justify-between">
            <span class="text-gray-500">Incidents (30d)</span>
            <span class="font-medium text-gray-900">{{ $incidentCount30d }}</span>
        </div>
    </div>
    @if($recentIncidents->isNotEmpty())
        <div class="mt-3 border-t border-gray-100 pt-2">
            <p class="text-xs font-medium text-gray-700">Recent Incidents</p>
            <div class="mt-1 space-y-1">
                @foreach($recentIncidents as $incident)
                    <div class="flex items-center justify-between text-xs">
                        <span class="truncate text-gray-600">{{ \Illuminate\Support\Str::limit($incident->cause ?? 'Unknown', 30) }}</span>
                        <span class="ml-2 flex-shrink-0 text-gray-400">{{ $incident->started_at->diffForHumans() }} ({{ $incident->duration }})</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
    <div class="mt-3 flex items-center gap-2 border-t border-gray-100 pt-3">
        <button wire:click="checkNow({{ $site->id }})" class="rounded-md bg-purple-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-purple-700">Check Now</button>
        <a href="{{ route('sites.uptime', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Details</a>
    </div>
@else
    <p class="text-sm text-gray-500">No monitor configured</p>
    <a href="{{ route('sites.uptime', $site) }}" class="mt-2 inline-block text-xs font-medium text-purple-600 hover:text-purple-800">Configure Monitor</a>
@endif
