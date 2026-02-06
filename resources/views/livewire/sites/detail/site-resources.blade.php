<div>
    <div class="mb-6 flex justify-end">
        <x-ui.button wire:click="checkNow" wire:loading.attr="disabled">
            <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            <span wire:loading.remove wire:target="checkNow">Check Now</span>
            <span wire:loading wire:target="checkNow">Checking...</span>
        </x-ui.button>
    </div>

    <x-ui.flash-alert type="success" key="success" />

    @if($this->latestCheck && $this->latestCheck->is_available)
        {{-- Threshold alerts --}}
        @foreach($this->thresholdViolations as $violation)
            @if(str_contains($violation, 'critical'))
                <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                    <strong>Critical:</strong>
                    {{ match($violation) {
                        'disk_space_critical' => 'Disk usage is above 90% (' . $this->latestCheck->disk_percentage . '%)',
                        'memory_critical' => 'Memory usage is above 90% (' . $this->latestCheck->memory_percentage . '%)',
                        default => 'Resource threshold exceeded',
                    } }}
                </div>
            @else
                <div class="mb-4 rounded-lg bg-yellow-50 border border-yellow-200 p-4 text-sm text-yellow-700">
                    <strong>Warning:</strong>
                    {{ match($violation) {
                        'disk_space_warning' => 'Disk usage is above 80% (' . $this->latestCheck->disk_percentage . '%)',
                        'memory_warning' => 'Memory usage is above 80% (' . $this->latestCheck->memory_percentage . '%)',
                        'cpu_warning' => 'CPU usage is above 80% (' . $this->latestCheck->cpu_usage . '%)',
                        default => 'Resource threshold exceeded',
                    } }}
                </div>
            @endif
        @endforeach

        {{-- Resource gauges --}}
        <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
            {{-- CPU --}}
            <x-ui.card>
                <div class="text-center">
                    <div class="relative mx-auto h-32 w-32">
                        <svg class="h-32 w-32 -rotate-90" viewBox="0 0 120 120">
                            <circle cx="60" cy="60" r="50" fill="none" stroke="#e5e7eb" stroke-width="10"/>
                            <circle cx="60" cy="60" r="50" fill="none"
                                    stroke="{{ ($this->latestCheck->cpu_usage ?? 0) > 80 ? '#ef4444' : (($this->latestCheck->cpu_usage ?? 0) > 60 ? '#f59e0b' : '#10b981') }}"
                                    stroke-width="10"
                                    stroke-dasharray="{{ (($this->latestCheck->cpu_usage ?? 0) / 100) * 314.16 }} 314.16"
                                    stroke-linecap="round"/>
                        </svg>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <span class="text-2xl font-bold text-gray-900">{{ $this->latestCheck->cpu_usage ?? 'N/A' }}{{ $this->latestCheck->cpu_usage !== null ? '%' : '' }}</span>
                        </div>
                    </div>
                    <p class="mt-2 text-sm font-medium text-gray-700">CPU Usage</p>
                </div>
            </x-ui.card>

            {{-- Memory --}}
            <x-ui.card>
                <div class="text-center">
                    <div class="relative mx-auto h-32 w-32">
                        <svg class="h-32 w-32 -rotate-90" viewBox="0 0 120 120">
                            <circle cx="60" cy="60" r="50" fill="none" stroke="#e5e7eb" stroke-width="10"/>
                            <circle cx="60" cy="60" r="50" fill="none"
                                    stroke="{{ ($this->latestCheck->memory_percentage ?? 0) > 90 ? '#ef4444' : (($this->latestCheck->memory_percentage ?? 0) > 80 ? '#f59e0b' : '#10b981') }}"
                                    stroke-width="10"
                                    stroke-dasharray="{{ (($this->latestCheck->memory_percentage ?? 0) / 100) * 314.16 }} 314.16"
                                    stroke-linecap="round"/>
                        </svg>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <span class="text-2xl font-bold text-gray-900">{{ $this->latestCheck->memory_percentage ?? 'N/A' }}{{ $this->latestCheck->memory_percentage !== null ? '%' : '' }}</span>
                        </div>
                    </div>
                    <p class="mt-2 text-sm font-medium text-gray-700">Memory Usage</p>
                    @if($this->latestCheck->memory_used && $this->latestCheck->memory_total)
                        <p class="text-xs text-gray-500">{{ number_format($this->latestCheck->memory_used / 1048576, 0) }} MB / {{ number_format($this->latestCheck->memory_total / 1048576, 0) }} MB</p>
                    @endif
                </div>
            </x-ui.card>

            {{-- Disk --}}
            <x-ui.card>
                <div class="text-center">
                    <div class="relative mx-auto h-32 w-32">
                        <svg class="h-32 w-32 -rotate-90" viewBox="0 0 120 120">
                            <circle cx="60" cy="60" r="50" fill="none" stroke="#e5e7eb" stroke-width="10"/>
                            <circle cx="60" cy="60" r="50" fill="none"
                                    stroke="{{ ($this->latestCheck->disk_percentage ?? 0) > 90 ? '#ef4444' : (($this->latestCheck->disk_percentage ?? 0) > 80 ? '#f59e0b' : '#10b981') }}"
                                    stroke-width="10"
                                    stroke-dasharray="{{ (($this->latestCheck->disk_percentage ?? 0) / 100) * 314.16 }} 314.16"
                                    stroke-linecap="round"/>
                        </svg>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <span class="text-2xl font-bold text-gray-900">{{ $this->latestCheck->disk_percentage ?? 'N/A' }}{{ $this->latestCheck->disk_percentage !== null ? '%' : '' }}</span>
                        </div>
                    </div>
                    <p class="mt-2 text-sm font-medium text-gray-700">Disk Usage</p>
                    @if($this->latestCheck->disk_used && $this->latestCheck->disk_total)
                        <p class="text-xs text-gray-500">{{ number_format($this->latestCheck->disk_used / 1073741824, 1) }} GB / {{ number_format($this->latestCheck->disk_total / 1073741824, 1) }} GB</p>
                    @endif
                </div>
            </x-ui.card>
        </div>

        {{-- Load Average --}}
        <x-ui.card class="mt-6">
            <h3 class="text-sm font-semibold text-gray-900 mb-3">Load Average</h3>
            <div class="grid grid-cols-3 gap-4 text-center">
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->latestCheck->load_average_1 ?? 'N/A' }}</p>
                    <p class="text-xs text-gray-500">1 min</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->latestCheck->load_average_5 ?? 'N/A' }}</p>
                    <p class="text-xs text-gray-500">5 min</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->latestCheck->load_average_15 ?? 'N/A' }}</p>
                    <p class="text-xs text-gray-500">15 min</p>
                </div>
            </div>
            @if($this->latestCheck->checked_at)
                <p class="mt-3 text-xs text-gray-400 text-center">Last checked: {{ $this->latestCheck->checked_at->diffForHumans() }}</p>
            @endif
        </x-ui.card>

        {{-- Historical data --}}
        @if($this->history->count() > 1)
            <x-ui.card class="mt-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">History (Last 30 Days)</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                            <tr>
                                <th class="px-4 py-2">Date</th>
                                <th class="px-4 py-2">CPU</th>
                                <th class="px-4 py-2">Memory</th>
                                <th class="px-4 py-2">Disk</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach($this->history->take(20) as $check)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2 text-gray-500">{{ $check->checked_at->format('M d, H:i') }}</td>
                                    <td class="px-4 py-2">{{ $check->cpu_usage !== null ? $check->cpu_usage . '%' : 'N/A' }}</td>
                                    <td class="px-4 py-2">{{ $check->memory_percentage !== null ? $check->memory_percentage . '%' : 'N/A' }}</td>
                                    <td class="px-4 py-2">{{ $check->disk_percentage !== null ? $check->disk_percentage . '%' : 'N/A' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif
    @elseif($this->latestCheck && !$this->latestCheck->is_available)
        <x-ui.card>
            <div class="p-8 text-center">
                <div class="mb-3 inline-flex rounded-full bg-gray-100 p-3">
                    <svg class="h-6 w-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-gray-900">Resource Monitoring Not Available</p>
                <p class="mt-1 text-xs text-gray-500">Server resource monitoring is not available for this hosting environment. This is common on shared hosting where system-level metrics are restricted.</p>
            </div>
        </x-ui.card>
    @else
        <x-ui.card>
            <div class="p-8 text-center">
                <div class="mb-3 inline-flex rounded-full bg-gray-100 p-3">
                    <svg class="h-6 w-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-gray-900">No Resource Data Yet</p>
                <p class="mt-1 text-xs text-gray-500">Click "Check Now" to fetch server resource usage data.</p>
            </div>
        </x-ui.card>
    @endif
</div>
