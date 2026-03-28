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
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="font-semibold text-gray-900">{{ $site->name }}</h3>
                            <p class="text-xs text-gray-500">{{ $site->url }}</p>
                        </div>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium {{ $site->is_up ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $site->is_up ? 'bg-green-500' : 'bg-red-500' }}"></span>
                            {{ $site->is_up ? 'Online' : 'Offline' }}
                        </span>
                    </div>
                    <div class="grid grid-cols-3 gap-3 rounded-lg bg-gray-50 p-3">
                        <div class="text-center">
                            <p class="text-xs text-gray-500">Uptime (30d)</p>
                            <p class="text-sm font-bold {{ ($site->uptimeMonitor?->uptime_30d ?? 0) >= 99 ? 'text-green-600' : 'text-amber-600' }}">
                                {{ $site->uptimeMonitor ? number_format((float) $site->uptimeMonitor->uptime_30d, 2) . '%' : '—' }}
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
