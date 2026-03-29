<div {!! $hasRunningJobs ? 'wire:poll.3s="checkJobProgress"' : '' !!}>
    {{-- Header actions --}}
    <div class="mb-6 flex justify-end">
        <div class="flex items-center gap-2">
            @if($this->monitor)
                @if($this->monitor->status === 'paused')
                    <x-ui.button variant="secondary" wire:click="resumeMonitor">Resume</x-ui.button>
                @else
                    <x-ui.button variant="secondary" wire:click="pauseMonitor">Pause</x-ui.button>
                @endif
                <x-ui.button variant="secondary" wire:click="testNow">Test Now</x-ui.button>
                <x-ui.button wire:click="$dispatch('open-configure-monitor', { monitorId: {{ $this->monitor->id }} })">
                    <x-icons.settings class="mr-1.5 h-4 w-4" />
                    Configure
                </x-ui.button>
            @else
                <x-ui.button wire:click="$dispatch('open-configure-monitor', { siteId: {{ $site->id }} })">
                    <x-icons.plus class="mr-1.5 h-4 w-4" />
                    Enable Monitoring
                </x-ui.button>
            @endif
        </div>
    </div>

    {{-- Job Progress --}}
    <x-ui.job-progress job-key="uptime" :jobs="$trackedJobs" title="Checking uptime..." />

    @if(!$this->monitor)
        {{-- Empty state: no monitor configured --}}
        <x-ui.card>
            <x-ui.empty-state
                title="Uptime monitoring not configured"
                description="Enable monitoring to start tracking this site's availability."
                icon="activity"
            >
                <x-slot:action>
                    <x-ui.button wire:click="$dispatch('open-configure-monitor', { siteId: {{ $site->id }} })">
                        <x-icons.plus class="mr-1.5 h-4 w-4" />
                        Enable Monitoring
                    </x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>
    @elseif($this->checkCount <= 1)
        {{-- Just created: minimal first-result view --}}
        @php $monitor = $this->monitor; @endphp

        {{-- First check result --}}
        <div class="mb-6 grid grid-cols-2 gap-4">
            {{-- Current Status --}}
            <x-ui.card>
                <h4 class="text-xs font-medium uppercase tracking-wider text-gray-500">Status</h4>
                <div class="mt-2 flex items-center gap-2">
                    @php
                        $stateColor = match($monitor->current_state) {
                            \App\Enums\MonitorState::Up => 'bg-green-500',
                            \App\Enums\MonitorState::Down => 'bg-red-500',
                            \App\Enums\MonitorState::Degraded => 'bg-yellow-500',
                            default => 'bg-gray-400',
                        };
                        $stateLabel = match($monitor->current_state) {
                            \App\Enums\MonitorState::Up => 'Online',
                            \App\Enums\MonitorState::Down => 'Down',
                            \App\Enums\MonitorState::Degraded => 'Degraded',
                            default => 'Unknown',
                        };
                    @endphp
                    <span class="h-3 w-3 rounded-full {{ $stateColor }}"></span>
                    <span class="text-lg font-bold text-gray-900">{{ $stateLabel }}</span>
                </div>
                @if($monitor->status === \App\Enums\MonitorStatus::Paused)
                    <p class="mt-1 text-xs text-yellow-600">Monitoring paused</p>
                @endif
            </x-ui.card>

            {{-- Last Check --}}
            <x-ui.card>
                <h4 class="text-xs font-medium uppercase tracking-wider text-gray-500">Last Check</h4>
                <div class="mt-2 text-lg font-bold text-gray-900">
                    {{ $monitor->last_response_time ? $monitor->last_response_time . 'ms' : '—' }}
                </div>
                <p class="mt-1 text-xs text-gray-400">{{ $monitor->last_checked_at?->diffForHumans() ?? 'Never' }}</p>
            </x-ui.card>
        </div>

        <x-ui.card>
            <p class="py-4 text-center text-sm text-gray-500">Monitoring is active. More data will appear as checks accumulate.</p>
        </x-ui.card>
    @else
        {{-- Full dashboard: monitor with history --}}
        @php $monitor = $this->monitor; @endphp

        {{-- Current status + Stats grid --}}
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-6">
            {{-- Current Status --}}
            <x-ui.card>
                <h4 class="text-xs font-medium uppercase tracking-wider text-gray-500">Status</h4>
                <div class="mt-2 flex items-center gap-2">
                    @php
                        $stateColor = match($monitor->current_state) {
                            \App\Enums\MonitorState::Up => 'bg-green-500',
                            \App\Enums\MonitorState::Down => 'bg-red-500',
                            \App\Enums\MonitorState::Degraded => 'bg-yellow-500',
                            default => 'bg-gray-400',
                        };
                        $stateLabel = match($monitor->current_state) {
                            \App\Enums\MonitorState::Up => 'Online',
                            \App\Enums\MonitorState::Down => 'Down',
                            \App\Enums\MonitorState::Degraded => 'Degraded',
                            default => 'Unknown',
                        };
                    @endphp
                    <span class="h-3 w-3 rounded-full {{ $stateColor }}"></span>
                    <span class="text-lg font-bold text-gray-900">{{ $stateLabel }}</span>
                </div>
                @if($monitor->status === \App\Enums\MonitorStatus::Paused)
                    <p class="mt-1 text-xs text-yellow-600">Monitoring paused</p>
                @endif
            </x-ui.card>

            {{-- Last Check --}}
            <x-ui.card>
                <h4 class="text-xs font-medium uppercase tracking-wider text-gray-500">Last Check</h4>
                <div class="mt-2 text-lg font-bold text-gray-900">
                    {{ $monitor->last_response_time ? $monitor->last_response_time . 'ms' : '—' }}
                </div>
                <p class="mt-1 text-xs text-gray-400">{{ $monitor->last_checked_at?->diffForHumans() ?? 'Never' }}</p>
            </x-ui.card>

            {{-- 24h --}}
            <livewire:components.uptime-stats-card label="24 Hours" :monitor="$monitor" period="24h" :key="'stats-24h-'.$monitor->id" />

            {{-- 7d --}}
            <livewire:components.uptime-stats-card label="7 Days" :monitor="$monitor" period="7d" :key="'stats-7d-'.$monitor->id" />

            {{-- 30d --}}
            <livewire:components.uptime-stats-card label="30 Days" :monitor="$monitor" period="30d" :key="'stats-30d-'.$monitor->id" />

            {{-- 365d --}}
            <livewire:components.uptime-stats-card label="1 Year" :monitor="$monitor" period="365d" :key="'stats-365d-'.$monitor->id" />
        </div>

        {{-- Uptime bar --}}
        <div class="mb-6">
            <x-ui.card>
                <h3 class="mb-3 text-sm font-medium text-gray-900">Last 24 Hours</h3>
                <livewire:components.uptime-bar :monitor="$monitor" :key="'bar-'.$monitor->id" />
                <div class="mt-2 flex justify-between text-xs text-gray-400">
                    <span>24h ago</span>
                    <span>Now</span>
                </div>
            </x-ui.card>
        </div>

        {{-- Response Time Chart --}}
        <div class="mb-6">
            <livewire:components.response-time-chart :monitor="$monitor" :key="'chart-'.$monitor->id" />
        </div>

        {{-- Incidents table --}}
        <x-ui.card>
            <h3 class="mb-4 text-sm font-medium text-gray-900">Recent Incidents</h3>

            @if($this->incidents->isEmpty())
                <p class="py-6 text-center text-sm text-gray-500">No incidents recorded.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <x-ui.th>Status</x-ui.th>
                                <x-ui.th>Cause</x-ui.th>
                                <x-ui.th>Started</x-ui.th>
                                <x-ui.th>Duration</x-ui.th>
                                <x-ui.th>Notified Via</x-ui.th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach($this->incidents as $incident)
                                <tr>
                                    <x-ui.td>
                                        <x-ui.badge :variant="$incident->status === 'ongoing' ? 'red' : 'green'">
                                            {{ ucfirst($incident->status) }}
                                        </x-ui.badge>
                                    </x-ui.td>
                                    <x-ui.td>{{ $incident->cause ?? '—' }}</x-ui.td>
                                    <x-ui.td>{{ $incident->started_at->format('M d, H:i') }}</x-ui.td>
                                    <x-ui.td>{{ $incident->duration }}</x-ui.td>
                                    <x-ui.td>
                                        @if($incident->notified_via)
                                            @foreach($incident->notified_via as $via)
                                                <x-ui.badge variant="gray">{{ $via }}</x-ui.badge>
                                            @endforeach
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </x-ui.td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endif

    {{-- Configure Monitor Modal --}}
    <livewire:uptime.configure-monitor />
</div>
