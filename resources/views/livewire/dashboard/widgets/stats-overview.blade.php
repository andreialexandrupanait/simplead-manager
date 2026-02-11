<x-dashboard.widget-container
    :title="$this->getTitle()"
    :widget-id="$widget->id"
    :loading="!$isLoaded"
    skeleton-type="stats"
    wire:init="loadWidget"
>
    @if($isLoaded && $this->data)
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            {{-- Total Sites --}}
            <div class="rounded-lg bg-gradient-to-br from-purple-50 to-white p-4">
                <div class="text-xs font-medium text-gray-500">Total Sites</div>
                <div class="mt-1 text-2xl font-bold text-gray-900">{{ $this->data['total_sites'] ?? 0 }}</div>
            </div>

            {{-- Sites Up --}}
            <div class="rounded-lg bg-gradient-to-br from-green-50 to-white p-4">
                <div class="text-xs font-medium text-gray-500">Sites Up</div>
                <div class="mt-1 text-2xl font-bold text-green-600">{{ $this->data['sites_up'] ?? 0 }}</div>
            </div>

            {{-- Sites Down --}}
            <div class="rounded-lg bg-gradient-to-br from-red-50 to-white p-4">
                <div class="text-xs font-medium text-gray-500">Sites Down</div>
                <div class="mt-1 text-2xl font-bold {{ ($this->data['sites_down'] ?? 0) > 0 ? 'text-red-600' : 'text-gray-900' }}">
                    {{ $this->data['sites_down'] ?? 0 }}
                </div>
            </div>

            {{-- Clients --}}
            <div class="rounded-lg bg-gradient-to-br from-blue-50 to-white p-4">
                <div class="text-xs font-medium text-gray-500">Clients</div>
                <div class="mt-1 text-2xl font-bold text-gray-900">{{ $this->data['total_clients'] ?? 0 }}</div>
            </div>

            {{-- Avg Uptime --}}
            <div class="rounded-lg bg-gradient-to-br from-indigo-50 to-white p-4">
                <div class="text-xs font-medium text-gray-500">Avg Uptime</div>
                <div class="mt-1 text-2xl font-bold text-gray-900">
                    {{ $this->data['avg_uptime'] ? number_format($this->data['avg_uptime'], 1) . '%' : '—' }}
                </div>
            </div>

            {{-- Avg Response Time --}}
            <div class="rounded-lg bg-gradient-to-br from-cyan-50 to-white p-4">
                <div class="text-xs font-medium text-gray-500">Avg Response</div>
                <div class="mt-1 text-2xl font-bold text-gray-900">
                    {{ $this->data['avg_response_time'] ? number_format($this->data['avg_response_time']) . 'ms' : '—' }}
                </div>
            </div>
        </div>
    @endif
</x-dashboard.widget-container>
