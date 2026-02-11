<x-ui.card>
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-green-100">
                <svg class="h-5 w-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold text-gray-900">Uptime Status</h3>
        </div>
        <a href="{{ route('sites.uptime', $site) }}" class="text-sm text-purple-600 hover:text-purple-700">
            View Details →
        </a>
    </div>

    {{-- Card Content --}}
    <div class="p-4">
        @if($site->uptimeMonitor)
            {{-- Current Status --}}
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <div class="text-sm text-gray-600">Current Status</div>
                    <div class="mt-1 text-2xl font-bold {{ $site->is_up ? 'text-green-600' : 'text-red-600' }}">
                        {{ $site->is_up ? 'Online' : 'Offline' }}
                    </div>
                </div>
                <div class="flex h-16 w-16 items-center justify-center rounded-full {{ $site->is_up ? 'bg-green-100' : 'bg-red-100' }}">
                    <svg class="h-8 w-8 {{ $site->is_up ? 'text-green-600' : 'text-red-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        @if($site->is_up)
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        @else
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        @endif
                    </svg>
                </div>
            </div>

            {{-- Stats Grid --}}
            <div class="grid grid-cols-2 gap-4 border-t border-gray-100 pt-4">
                {{-- Uptime Percentage --}}
                <div>
                    <div class="text-sm text-gray-600">30-Day Uptime</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900">
                        {{ number_format($site->uptimeMonitor->uptime_30d ?? 0, 2) }}%
                    </div>
                </div>

                {{-- Response Time --}}
                <div>
                    <div class="text-sm text-gray-600">Avg Response</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900">
                        {{ $site->uptimeMonitor->avg_response_time ?? 0 }}ms
                    </div>
                </div>
            </div>

            {{-- Last Check --}}
            @if($site->uptimeMonitor->last_checked_at)
            <div class="mt-4 border-t border-gray-100 pt-4 text-xs text-gray-500">
                Last checked {{ $site->uptimeMonitor->last_checked_at->diffForHumans() }}
            </div>
            @endif
        @else
            <x-ui.empty-state
                title="No uptime monitoring"
                description="Enable uptime monitoring to track site availability."
            />
        @endif
    </div>
</x-ui.card>
