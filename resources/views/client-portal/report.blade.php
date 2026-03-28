<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $report->title }} — {{ $client->name }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="mx-auto max-w-5xl px-4 py-8">
        {{-- Header --}}
        <div class="mb-6 flex items-center justify-between">
            <div>
                <a href="{{ route('client-portal.show', $client->portal_token) }}" class="text-sm text-purple-600 hover:text-purple-800">&larr; Back to portal</a>
                <h1 class="text-xl font-bold text-gray-900 mt-1">{{ $report->title }}</h1>
                <p class="text-sm text-gray-500">{{ $report->period_start->format('M j, Y') }} — {{ $report->period_end->format('M j, Y') }}</p>
            </div>
            @if($report->file_path)
                <a href="{{ route('client-portal.download', [$client->portal_token, $report]) }}"
                   class="rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 transition">
                    Download PDF
                </a>
            @endif
        </div>

        @php $snapshot = $report->data_snapshot ?? []; @endphp

        {{-- Overview --}}
        @if(!empty($snapshot['overview']))
            @php $ov = $snapshot['overview']; @endphp
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4 mb-6">
                @if(isset($ov['updates']))
                    <div class="rounded-xl border border-gray-200 bg-white p-4 text-center">
                        <p class="text-xs text-gray-500">Updates</p>
                        <p class="text-2xl font-bold text-gray-900">{{ $ov['updates']['count'] ?? 0 }}</p>
                    </div>
                @endif
                @if(isset($ov['uptime']))
                    <div class="rounded-xl border border-gray-200 bg-white p-4 text-center">
                        <p class="text-xs text-gray-500">Uptime</p>
                        <p class="text-2xl font-bold {{ ($ov['uptime']['percentage'] ?? 0) >= 99 ? 'text-green-600' : 'text-amber-600' }}">
                            {{ isset($ov['uptime']['percentage']) ? number_format((float) $ov['uptime']['percentage'], 2) . '%' : '—' }}
                        </p>
                    </div>
                @endif
                @if(isset($ov['backups']))
                    <div class="rounded-xl border border-gray-200 bg-white p-4 text-center">
                        <p class="text-xs text-gray-500">Backups</p>
                        <p class="text-2xl font-bold text-gray-900">{{ $ov['backups']['successful'] ?? 0 }}</p>
                    </div>
                @endif
                @if(isset($ov['security']))
                    <div class="rounded-xl border border-gray-200 bg-white p-4 text-center">
                        <p class="text-xs text-gray-500">Security</p>
                        <p class="text-2xl font-bold {{ ($ov['security']['score'] ?? 0) >= 80 ? 'text-green-600' : 'text-amber-600' }}">
                            {{ $ov['security']['score'] ?? '—' }}
                        </p>
                    </div>
                @endif
            </div>
        @endif

        {{-- Uptime --}}
        @if(!empty($snapshot['uptime']) && ($snapshot['uptime']['available'] ?? false))
            @php $ut = $snapshot['uptime']; @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-5 mb-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-3">Uptime & Availability</h2>
                <div class="grid grid-cols-3 gap-4 mb-4">
                    <div><p class="text-xs text-gray-500">Uptime</p><p class="text-xl font-bold text-green-600">{{ number_format((float) ($ut['uptime_percentage'] ?? 0), 3) }}%</p></div>
                    <div><p class="text-xs text-gray-500">Incidents</p><p class="text-xl font-bold text-gray-900">{{ $ut['incidents_count'] ?? 0 }}</p></div>
                    <div><p class="text-xs text-gray-500">Avg Response</p><p class="text-xl font-bold text-gray-900">{{ $ut['avg_response_time'] ?? '—' }}ms</p></div>
                </div>
                @if(!empty($ut['incidents']))
                    <div class="border-t pt-3">
                        <h3 class="text-sm font-medium text-gray-700 mb-2">Incidents</h3>
                        @foreach(array_slice($ut['incidents'], 0, 10) as $inc)
                            <div class="flex items-center justify-between py-1.5 text-sm border-b border-gray-50">
                                <span class="text-gray-700">{{ $inc['cause'] ?? 'Unknown' }}</span>
                                <span class="text-gray-500">{{ $inc['duration'] ?? '' }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Updates --}}
        @if(!empty($snapshot['updates']))
            @php $upd = $snapshot['updates']; @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-5 mb-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-3">Updates Applied</h2>
                <div class="grid grid-cols-4 gap-3 mb-4">
                    <div><p class="text-xs text-gray-500">Total</p><p class="text-xl font-bold">{{ $upd['total_count'] ?? 0 }}</p></div>
                    <div><p class="text-xs text-gray-500">Plugins</p><p class="text-xl font-bold">{{ $upd['plugin_count'] ?? 0 }}</p></div>
                    <div><p class="text-xs text-gray-500">Themes</p><p class="text-xl font-bold">{{ $upd['theme_count'] ?? 0 }}</p></div>
                    <div><p class="text-xs text-gray-500">Failed</p><p class="text-xl font-bold {{ ($upd['failed_count'] ?? 0) > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $upd['failed_count'] ?? 0 }}</p></div>
                </div>
                @if(!empty($upd['all_updates']))
                    <div class="border-t pt-3 max-h-60 overflow-y-auto">
                        @foreach($upd['all_updates'] as $u)
                            <div class="flex items-center justify-between py-1.5 text-sm">
                                <span class="text-gray-700">{{ $u['name'] ?? '' }} <span class="text-xs text-gray-400">({{ $u['type'] ?? '' }})</span></span>
                                <span class="text-gray-500 font-mono text-xs">{{ $u['from_version'] ?? '' }} → {{ $u['to_version'] ?? '' }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Backups --}}
        @if(!empty($snapshot['backups']))
            @php $bk = $snapshot['backups']; @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-5 mb-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-3">Backups</h2>
                <div class="grid grid-cols-3 gap-4">
                    <div><p class="text-xs text-gray-500">Successful</p><p class="text-xl font-bold text-green-600">{{ $bk['count'] ?? 0 }}</p></div>
                    <div><p class="text-xs text-gray-500">Failed</p><p class="text-xl font-bold {{ ($bk['failed_count'] ?? 0) > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $bk['failed_count'] ?? 0 }}</p></div>
                    <div><p class="text-xs text-gray-500">Total Size</p><p class="text-xl font-bold text-gray-900">{{ $bk['total_size'] ?? '—' }}</p></div>
                </div>
            </div>
        @endif

        {{-- Security --}}
        @if(!empty($snapshot['security']))
            @php $sec = $snapshot['security']; @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-5 mb-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-3">Security</h2>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div><p class="text-xs text-gray-500">Score</p><p class="text-2xl font-bold {{ ($sec['score'] ?? 0) >= 80 ? 'text-green-600' : 'text-amber-600' }}">{{ $sec['score'] ?? '—' }}/100</p></div>
                    <div><p class="text-xs text-gray-500">Issues</p><p class="text-2xl font-bold text-gray-900">{{ $sec['total_issues'] ?? 0 }}</p></div>
                </div>
                @if(!empty($sec['active_issues']))
                    <div class="border-t pt-3">
                        @foreach($sec['active_issues'] as $issue)
                            <div class="flex items-center gap-2 py-1.5">
                                <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ ($issue['severity'] ?? '') === 'critical' ? 'bg-red-100 text-red-700' : (($issue['severity'] ?? '') === 'high' ? 'bg-orange-100 text-orange-700' : 'bg-yellow-100 text-yellow-700') }}">
                                    {{ ucfirst($issue['severity'] ?? 'medium') }}
                                </span>
                                <span class="text-sm text-gray-700">{{ $issue['title'] ?? '' }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Analytics --}}
        @if(!empty($snapshot['analytics']))
            @php $an = $snapshot['analytics']; @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-5 mb-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-3">Analytics</h2>
                <div class="grid grid-cols-4 gap-4">
                    <div><p class="text-xs text-gray-500">Pageviews</p><p class="text-xl font-bold text-gray-900">{{ number_format($an['total_pageviews'] ?? 0) }}</p></div>
                    <div><p class="text-xs text-gray-500">Users</p><p class="text-xl font-bold text-gray-900">{{ number_format($an['total_users'] ?? 0) }}</p></div>
                    <div><p class="text-xs text-gray-500">Sessions</p><p class="text-xl font-bold text-gray-900">{{ number_format($an['sessions'] ?? 0) }}</p></div>
                    <div><p class="text-xs text-gray-500">Bounce Rate</p><p class="text-xl font-bold text-gray-900">{{ number_format((float) ($an['bounce_rate'] ?? 0), 1) }}%</p></div>
                </div>
            </div>
        @endif

        <p class="mt-10 text-center text-xs text-gray-400">Powered by {{ config('app.name') }}</p>
    </div>
</body>
</html>
