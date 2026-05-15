<x-layouts.status-page :title="$data['title'] . ' — Status'" :primaryColor="$data['primary_color']">
    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        {{-- Header --}}
        <div class="mb-8 text-center">
            @if($data['logo_url'])
                <img src="{{ $data['logo_url'] }}" alt="{{ $data['title'] }}" class="mx-auto mb-4 h-12">
            @endif
            <h1 class="text-2xl font-semibold text-gray-900">{{ $data['title'] }}</h1>
            @if($data['description'])
                <p class="mt-1 text-sm text-gray-500">{{ $data['description'] }}</p>
            @endif
        </div>

        {{-- Overall Status Banner --}}
        @php
            $statusConfig = match($data['overall_status']) {
                'operational' => ['bg' => 'bg-green-500', 'text' => 'All Systems Operational'],
                'degraded' => ['bg' => 'bg-yellow-500', 'text' => 'Degraded Performance'],
                'outage' => ['bg' => 'bg-red-500', 'text' => 'System Outage'],
                default => ['bg' => 'bg-gray-500', 'text' => 'Unknown'],
            };
        @endphp
        <div class="{{ $statusConfig['bg'] }} mb-8 rounded-xl px-6 py-4 text-center text-white shadow-sm">
            <p class="text-lg font-semibold">{{ $statusConfig['text'] }}</p>
        </div>

        {{-- Active Incidents --}}
        @if($data['active_incidents']->isNotEmpty())
            <div class="mb-8">
                <h2 class="mb-4 text-lg font-semibold text-gray-900">Active Incidents</h2>
                <div class="space-y-4">
                    @foreach($data['active_incidents'] as $incident)
                        <div class="rounded-xl border border-{{ $incident->severity_color }}-200 bg-{{ $incident->severity_color }}-50 p-4">
                            <div class="flex items-start justify-between">
                                <div>
                                    <h3 class="font-medium text-gray-900">{{ $incident->title }}</h3>
                                    <p class="mt-0.5 text-sm text-gray-600">
                                        <x-ui.badge :variant="$incident->severity_color">{{ $incident->status_label }}</x-ui.badge>
                                        &middot; Started {{ $incident->started_at?->diffForHumans() }}
                                    </p>
                                </div>
                            </div>
                            @if($incident->updates->isNotEmpty())
                                <div class="mt-3 space-y-2 border-t border-{{ $incident->severity_color }}-200 pt-3">
                                    @foreach($incident->updates as $update)
                                        <div class="text-sm">
                                            <span class="font-medium text-gray-700">{{ ucfirst($update->status) }}</span>
                                            <span class="text-gray-500">&mdash; {{ $update->message }}</span>
                                            <span class="text-xs text-gray-400 ml-1">{{ $update->created_at->diffForHumans() }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Sites --}}
        <div class="mb-8">
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 divide-y divide-gray-100">
                @foreach($data['sites'] as $site)
                    <div class="flex items-center justify-between px-5 py-4">
                        <div class="flex items-center gap-3">
                            @php
                                $dotColor = match($site['status']) {
                                    'operational' => 'bg-green-500',
                                    'degraded' => 'bg-yellow-500',
                                    'down' => 'bg-red-500',
                                    default => 'bg-gray-400',
                                };
                            @endphp
                            <span class="h-2.5 w-2.5 rounded-full {{ $dotColor }}"></span>
                            <span class="text-sm font-medium text-gray-900">{{ $site['name'] }}</span>
                        </div>
                        <div class="flex items-center gap-4 text-sm text-gray-500">
                            @if($data['show_uptime_percentage'] && $site['uptime_percentage'] !== null)
                                <span>{{ number_format($site['uptime_percentage'], 2) }}%</span>
                            @endif
                            @if($data['show_response_time'] && $site['response_time'])
                                <span>{{ $site['response_time'] }}ms</span>
                            @endif
                            <span class="capitalize text-xs">{{ str_replace('_', ' ', $site['status']) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- SLA Compliance --}}
        @if(!empty($data['sla']))
            <div class="mb-8">
                <h2 class="mb-4 text-lg font-semibold text-gray-900">SLA Compliance</h2>
                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <p class="text-sm text-gray-500">Current Month</p>
                            <p class="text-3xl font-semibold {{ $data['sla']['met'] ? 'text-green-600' : 'text-red-600' }}">
                                {{ $data['sla']['current'] !== null ? number_format($data['sla']['current'], 3) . '%' : 'N/A' }}
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500">Target</p>
                            <p class="text-xl font-semibold text-gray-700">{{ number_format($data['sla']['target'], 2) }}%</p>
                        </div>
                    </div>
                    @if(!empty($data['sla']['history']))
                        <div class="border-t border-gray-100 pt-3 mt-3">
                            <p class="text-xs font-medium text-gray-500 uppercase mb-2">Previous Months</p>
                            <div class="grid grid-cols-3 gap-3">
                                @foreach($data['sla']['history'] as $month)
                                    <div class="text-center">
                                        <p class="text-xs text-gray-500">{{ $month['month'] }}</p>
                                        <p class="text-sm font-semibold {{ $month['met'] ? 'text-green-600' : ($month['met'] === false ? 'text-red-600' : 'text-gray-400') }}">
                                            {{ $month['uptime'] !== null ? number_format($month['uptime'], 3) . '%' : 'N/A' }}
                                        </p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Scheduled Maintenance --}}
        @if($data['scheduled_maintenance']->isNotEmpty())
            <div class="mb-8">
                <h2 class="mb-4 text-lg font-semibold text-gray-900">Scheduled Maintenance</h2>
                <div class="space-y-3">
                    @foreach($data['scheduled_maintenance'] as $maintenance)
                        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4">
                            <h3 class="font-medium text-gray-900">{{ $maintenance->title }}</h3>
                            <p class="mt-0.5 text-sm text-gray-600">
                                @if($maintenance->scheduled_start_at)
                                    {{ $maintenance->scheduled_start_at->format('M d, Y H:i') }}
                                    @if($maintenance->scheduled_end_at)
                                        — {{ $maintenance->scheduled_end_at->format('M d, Y H:i') }}
                                    @endif
                                @endif
                            </p>
                            @if($maintenance->description)
                                <p class="mt-2 text-sm text-gray-500">{{ $maintenance->description }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Incident History --}}
        @if($data['show_incident_history'] && $data['recent_incidents']->isNotEmpty())
            <div class="mb-8">
                <h2 class="mb-4 text-lg font-semibold text-gray-900">Incident History</h2>
                @php
                    $grouped = $data['recent_incidents']->groupBy(fn ($i) => $i->started_at?->format('Y-m-d') ?? $i->created_at->format('Y-m-d'));
                @endphp
                <div class="space-y-6">
                    @foreach($grouped as $date => $incidents)
                        <div>
                            <h3 class="mb-2 text-sm font-semibold text-gray-700">{{ \Carbon\Carbon::parse($date)->format('F d, Y') }}</h3>
                            <div class="space-y-3">
                                @foreach($incidents as $incident)
                                    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5">
                                        <div class="flex items-start justify-between">
                                            <div>
                                                <h4 class="text-sm font-medium text-gray-900">{{ $incident->title }}</h4>
                                                <p class="mt-0.5 text-xs text-gray-500">
                                                    Duration: {{ $incident->duration }}
                                                    &middot; {{ ucfirst($incident->severity) }}
                                                </p>
                                            </div>
                                            <x-ui.badge variant="green">Resolved</x-ui.badge>
                                        </div>
                                        @if($incident->updates->isNotEmpty())
                                            <div class="mt-3 space-y-1.5 border-t border-gray-100 pt-3">
                                                @foreach($incident->updates as $update)
                                                    <div class="text-xs text-gray-500">
                                                        <span class="font-medium text-gray-600">{{ ucfirst($update->status) }}</span> &mdash; {{ $update->message }}
                                                        <span class="text-gray-400 ml-1">{{ $update->created_at->format('H:i') }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Footer --}}
        <div class="text-center text-xs text-gray-400 py-8">
            Powered by SimpleAd Manager
        </div>
    </div>
</x-layouts.status-page>
