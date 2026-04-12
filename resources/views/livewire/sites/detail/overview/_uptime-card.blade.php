<x-ui.card :padding="false" class="flex flex-col">
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-3 py-2.5">
        <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-green-100">
                <svg aria-hidden="true" class="h-4 w-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900">Uptime</h3>
        </div>
        <a href="{{ route('sites.uptime', $site) }}" class="text-xs text-accent-600 hover:text-accent-700">
            Details →
        </a>
    </div>

    {{-- Card Content --}}
    <div class="flex flex-1 flex-col p-3">
        @if($site->uptimeMonitor)
            {{-- Current Status --}}
            <div class="mb-3 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="inline-block h-2.5 w-2.5 rounded-full {{ $site->is_up ? 'bg-green-500' : 'bg-red-500' }}"></span>
                    <span class="text-sm font-medium {{ $site->is_up ? 'text-green-700' : 'text-red-700' }}">
                        {{ $site->is_up ? 'Online' : 'Offline' }}
                    </span>
                </div>
            </div>

            {{-- Stats --}}
            <div class="flex-1 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">30-Day Uptime</span>
                    <span class="text-sm font-medium text-gray-900">
                        {{ number_format($site->uptimeMonitor->uptime_30d ?? 0, 2) }}%
                    </span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">Avg Response</span>
                    <span class="text-sm font-medium text-gray-900">
                        {{ $site->uptimeMonitor->avg_response_time ?? 0 }}ms
                    </span>
                </div>
            </div>

            @if($site->uptimeMonitor->last_checked_at)
            <div class="mt-2 border-t border-gray-100 pt-2 text-xs text-gray-400">
                Checked {{ $site->uptimeMonitor->last_checked_at->diffForHumans() }}
            </div>
            @endif
        @else
            <div class="py-2 text-center">
                <p class="text-sm text-gray-500">Not monitored</p>
                <a href="{{ route('sites.uptime', $site) }}" class="mt-1 inline-block text-xs text-accent-600 hover:text-accent-700">
                    Enable Monitoring →
                </a>
            </div>
        @endif
    </div>
</x-ui.card>
