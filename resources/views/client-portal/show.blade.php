<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $client->name }} — Portal</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="mx-auto max-w-5xl px-4 py-8">
        {{-- Header --}}
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $client->name }}</h1>
                <p class="text-sm text-gray-500">Site management reports and status overview</p>
            </div>
            @if($client->logo)
                <img src="{{ asset('storage/' . $client->logo) }}" alt="{{ $client->name }}" class="h-10" />
            @endif
        </div>

        {{-- Site Health Overview --}}
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Sites</h2>
        <div class="grid gap-4 sm:grid-cols-2 mb-8">
            @foreach($sites as $site)
                @php
                    $monitor = $site->uptimeMonitor;
                    $state = $monitor?->current_state?->value ?? null;
                    $dotColor = match($state) {
                        'up'       => 'bg-green-500',
                        'down'     => 'bg-red-500',
                        'degraded' => 'bg-yellow-400',
                        default    => 'bg-gray-300',
                    };
                    $incident = $monitor?->ongoingIncident;
                @endphp
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    {{-- Ongoing incident banner --}}
                    @if($incident)
                        <div class="mb-3 flex items-center gap-2 rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-700 border border-red-100">
                            <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                            <span>Incident: {{ $incident->cause ?? 'Site is currently unreachable' }}</span>
                        </div>
                    @endif

                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="font-semibold text-gray-900">{{ $site->name }}</h3>
                            <p class="text-xs text-gray-500">{{ $site->url }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            {{-- Real-time state dot --}}
                            <span class="relative flex h-2.5 w-2.5" title="{{ $state ?? 'unknown' }}">
                                @if($state === 'up')
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                @endif
                                <span class="relative inline-flex h-2.5 w-2.5 rounded-full {{ $dotColor }}"></span>
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium {{ $site->is_up ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $site->is_up ? 'Online' : 'Offline' }}
                            </span>
                        </div>
                    </div>

                    {{-- Response time row --}}
                    @if($monitor?->last_response_time)
                        <div class="mb-3 flex items-center gap-1.5 text-xs text-gray-400">
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                            Response time: <span class="font-medium text-gray-600">{{ $monitor->last_response_time }}ms</span>
                        </div>
                    @endif

                    <div class="grid grid-cols-3 gap-3 rounded-lg bg-gray-50 p-3">
                        <div class="text-center">
                            <p class="text-xs text-gray-500">Uptime (30d)</p>
                            <p class="text-sm font-bold {{ ($monitor?->uptime_30d ?? 0) >= 99 ? 'text-green-600' : 'text-amber-600' }}">
                                {{ $monitor ? number_format((float) $monitor->uptime_30d, 2) . '%' : '—' }}
                            </p>
                        </div>
                        <div class="text-center">
                            <p class="text-xs text-gray-500">Performance</p>
                            <p class="text-sm font-bold text-gray-900">
                                {{ $site->performanceMonitor?->latest_mobile_score ?? '—' }}
                            </p>
                        </div>
                        <div class="text-center">
                            <p class="text-xs text-gray-500">Last Backup</p>
                            <p class="text-sm font-bold text-gray-900">
                                {{ $site->latestCompletedBackup?->completed_at?->diffForHumans() ?? 'None' }}
                            </p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Reports --}}
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Reports</h2>
        @if($reports->isEmpty())
            <div class="rounded-xl border border-gray-200 bg-white p-8 text-center">
                <p class="text-gray-400">No reports available yet.</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach($reports as $report)
                    <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                        <div>
                            <p class="font-medium text-gray-900">{{ $report->title }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $report->site?->name }} &middot;
                                {{ $report->period_start->format('M j') }} — {{ $report->period_end->format('M j, Y') }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            @if($report->data_snapshot)
                                <a href="{{ route('client-portal.report', [$client->portal_token, $report]) }}"
                                   class="rounded-lg bg-purple-50 px-3 py-1.5 text-xs font-medium text-purple-700 hover:bg-purple-100 transition">
                                    View Online
                                </a>
                            @endif
                            <a href="{{ route('client-portal.download', [$client->portal_token, $report]) }}"
                               class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200 transition">
                                Download PDF
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <p class="mt-10 text-center text-xs text-gray-400">Powered by {{ config('app.name') }}</p>
    </div>
</body>
</html>
