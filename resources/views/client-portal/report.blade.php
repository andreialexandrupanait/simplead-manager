<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $report->title }} — {{ $client->name }}</title>
    @vite(['resources/css/app.css'])
    <style>
        html { scroll-behavior: smooth; }
        .section-card { @apply rounded-xl border border-gray-200 bg-white p-5 mb-6 shadow-sm; }
        .metric-card { @apply rounded-lg border border-gray-100 bg-gray-50 p-4 text-center; }
        .data-table { @apply min-w-full text-sm; }
        .data-table th { @apply py-2 px-3 text-left text-xs font-medium uppercase text-gray-500 bg-gray-50 border-b; }
        .data-table td { @apply py-2 px-3 border-b border-gray-50; }
        .badge-critical { @apply bg-red-100 text-red-700; }
        .badge-high { @apply bg-orange-100 text-orange-700; }
        .badge-medium { @apply bg-yellow-100 text-yellow-700; }
        .badge-low { @apply bg-blue-100 text-blue-700; }
        .badge-good { @apply bg-green-100 text-green-700; }
        .badge { @apply inline-flex rounded-full px-2 py-0.5 text-xs font-medium; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    @php $s = $report->data_snapshot ?? []; @endphp

    {{-- Sticky Header --}}
    <div class="sticky top-0 z-10 border-b border-gray-200 bg-white/95 backdrop-blur">
        <div class="mx-auto max-w-6xl px-4 py-3 flex items-center justify-between">
            <div>
                <a href="{{ route('client-portal.show', $client->portal_token) }}" class="text-xs text-purple-600 hover:text-purple-800">&larr; Back to portal</a>
                <h1 class="text-lg font-bold text-gray-900">{{ $report->title }}</h1>
                <p class="text-xs text-gray-500">{{ $report->period_start->format('M j, Y') }} — {{ $report->period_end->format('M j, Y') }}</p>
            </div>
            @if($report->file_path)
                <a href="{{ route('client-portal.download', [$client->portal_token, $report]) }}"
                   class="rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 transition flex-shrink-0">
                    Download PDF
                </a>
            @endif
        </div>
    </div>

    <div class="mx-auto max-w-6xl px-4 py-6 flex gap-6">
        {{-- Sidebar Navigation --}}
        <nav class="hidden lg:block w-52 flex-shrink-0">
            <div class="sticky top-20 space-y-0.5">
                @php
                    $nav = array_filter([
                        'overview' => isset($s['executive_snapshot']) || isset($s['overview']) ? 'Overview' : null,
                        'uptime' => isset($s['uptime']) ? 'Uptime' : null,
                        'security' => isset($s['security']) ? 'Security' : null,
                        'updates' => isset($s['updates']) ? 'Updates' : null,
                        'backups' => isset($s['backups']) ? 'Backups' : null,
                        'analytics' => !empty($s['analytics']) ? 'Analytics' : null,
                        'search_console' => !empty($s['search_console']) ? 'Search Console' : null,
                        'performance' => !empty($s['performance']) ? 'Performance' : null,
                        'plugins' => isset($s['plugin_inventory']) ? 'Plugins & Themes' : null,
                        'database' => !empty($s['database_health']) ? 'Database' : null,
                        'cloudflare' => !empty($s['cloudflare']) ? 'Cloudflare' : null,
                        'users' => !empty($s['wp_users']) ? 'WP Users' : null,
                        'email' => !empty($s['email']) ? 'Email Health' : null,
                        'recommendations' => isset($s['recommendations']) ? 'Recommendations' : null,
                    ]);
                @endphp
                @foreach($nav as $id => $label)
                    <a href="#{{ $id }}" class="block rounded-lg px-3 py-1.5 text-sm text-gray-600 hover:bg-purple-50 hover:text-purple-700 transition">{{ $label }}</a>
                @endforeach
            </div>
        </nav>

        {{-- Main Content --}}
        <div class="flex-1 min-w-0">

            {{-- ═══ OVERVIEW ═══ --}}
            @if(isset($s['overview']) || isset($s['executive_snapshot']))
            <div id="overview" class="section-card">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Overview</h2>
                @php $ov = $s['overview'] ?? []; @endphp
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @if(isset($ov['uptime']))
                        <div class="metric-card">
                            <p class="text-xs text-gray-500">Uptime</p>
                            <p class="text-2xl font-bold {{ ($ov['uptime']['percentage'] ?? 0) >= 99 ? 'text-green-600' : 'text-amber-600' }}">{{ isset($ov['uptime']['percentage']) ? number_format((float)$ov['uptime']['percentage'], 2).'%' : '—' }}</p>
                        </div>
                    @endif
                    @if(isset($ov['updates']))
                        <div class="metric-card">
                            <p class="text-xs text-gray-500">Updates Applied</p>
                            <p class="text-2xl font-bold text-gray-900">{{ $ov['updates']['count'] ?? 0 }}</p>
                        </div>
                    @endif
                    @if(isset($ov['backups']))
                        <div class="metric-card">
                            <p class="text-xs text-gray-500">Successful Backups</p>
                            <p class="text-2xl font-bold text-green-600">{{ $ov['backups']['successful'] ?? 0 }}</p>
                        </div>
                    @endif
                    @if(isset($ov['security']))
                        <div class="metric-card">
                            <p class="text-xs text-gray-500">Security Score</p>
                            <p class="text-2xl font-bold {{ ($ov['security']['score'] ?? 0) >= 80 ? 'text-green-600' : 'text-amber-600' }}">{{ $ov['security']['score'] ?? '—' }}</p>
                        </div>
                    @endif
                    @if(isset($ov['performance']))
                        <div class="metric-card">
                            <p class="text-xs text-gray-500">Mobile Score</p>
                            <p class="text-2xl font-bold text-gray-900">{{ $ov['performance']['mobile'] ?? '—' }}</p>
                        </div>
                        <div class="metric-card">
                            <p class="text-xs text-gray-500">Desktop Score</p>
                            <p class="text-2xl font-bold text-gray-900">{{ $ov['performance']['desktop'] ?? '—' }}</p>
                        </div>
                    @endif
                    @if(isset($ov['analytics']))
                        <div class="metric-card">
                            <p class="text-xs text-gray-500">Pageviews</p>
                            <p class="text-2xl font-bold text-gray-900">{{ number_format($ov['analytics']['pageviews'] ?? 0) }}</p>
                        </div>
                    @endif
                    @if(isset($ov['search_console']))
                        <div class="metric-card">
                            <p class="text-xs text-gray-500">Search Clicks</p>
                            <p class="text-2xl font-bold text-gray-900">{{ number_format($ov['search_console']['clicks'] ?? 0) }}</p>
                        </div>
                    @endif
                </div>
            </div>
            @endif

            {{-- ═══ UPTIME ═══ --}}
            @if(isset($s['uptime']) && ($s['uptime']['available'] ?? false))
            @php $ut = $s['uptime']; @endphp
            <div id="uptime" class="section-card">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Uptime & Availability</h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-4">
                    <div class="metric-card"><p class="text-xs text-gray-500">Uptime</p><p class="text-2xl font-bold text-green-600">{{ number_format((float)($ut['uptime_percentage'] ?? 0), 3) }}%</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Avg Response</p><p class="text-2xl font-bold text-gray-900">{{ $ut['avg_response_time'] ?? '—' }}ms</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Incidents</p><p class="text-2xl font-bold {{ ($ut['incidents_count'] ?? 0) > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $ut['incidents_count'] ?? 0 }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Total Downtime</p><p class="text-2xl font-bold text-gray-900">{{ $ut['formatted_downtime'] ?? 'None' }}</p></div>
                </div>
                @if(!empty($ut['incidents']))
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Incidents</h3>
                    <table class="data-table"><thead><tr><th>Status</th><th>Cause</th><th>Started</th><th>Duration</th></tr></thead><tbody>
                    @foreach($ut['incidents'] as $inc)
                        <tr>
                            <td><span class="badge {{ ($inc['status'] ?? '') === 'resolved' ? 'badge-good' : 'badge-critical' }}">{{ ucfirst($inc['status'] ?? 'unknown') }}</span></td>
                            <td class="text-gray-700">{{ $inc['cause'] ?? '—' }}</td>
                            <td class="text-gray-500">{{ $inc['started_at'] ?? '' }}</td>
                            <td class="text-gray-500">{{ $inc['duration'] ?? '' }}</td>
                        </tr>
                    @endforeach
                    </tbody></table>
                @endif
            </div>
            @endif

            {{-- ═══ SECURITY ═══ --}}
            @if(isset($s['security']))
            @php $sec = $s['security']; @endphp
            <div id="security" class="section-card">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Security</h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5 mb-4">
                    <div class="metric-card"><p class="text-xs text-gray-500">Score</p><p class="text-2xl font-bold {{ ($sec['score'] ?? 0) >= 80 ? 'text-green-600' : 'text-amber-600' }}">{{ $sec['score'] ?? '—' }}/100</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Critical</p><p class="text-xl font-bold text-red-600">{{ $sec['critical_count'] ?? 0 }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">High</p><p class="text-xl font-bold text-orange-600">{{ $sec['high_count'] ?? 0 }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Medium</p><p class="text-xl font-bold text-yellow-600">{{ $sec['medium_count'] ?? 0 }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Low</p><p class="text-xl font-bold text-blue-600">{{ $sec['low_count'] ?? 0 }}</p></div>
                </div>
                @if(!empty($sec['active_issues']))
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Active Issues</h3>
                    <table class="data-table"><thead><tr><th>Severity</th><th>Issue</th><th>Recommendation</th></tr></thead><tbody>
                    @foreach($sec['active_issues'] as $issue)
                        <tr>
                            <td><span class="badge badge-{{ $issue['severity'] ?? 'medium' }}">{{ ucfirst($issue['severity'] ?? 'medium') }}</span></td>
                            <td class="text-gray-700">{{ $issue['title'] ?? '' }}</td>
                            <td class="text-gray-500 text-xs">{{ $issue['recommendation'] ?? '' }}</td>
                        </tr>
                    @endforeach
                    </tbody></table>
                @endif
                @if(!empty($sec['vulnerabilities']))
                    <h3 class="text-sm font-semibold text-gray-700 mt-4 mb-2">Vulnerabilities</h3>
                    <table class="data-table"><thead><tr><th>Severity</th><th>Title</th><th>Plugin/Theme</th><th>Fixed In</th></tr></thead><tbody>
                    @foreach($sec['vulnerabilities'] as $vuln)
                        <tr>
                            <td><span class="badge badge-{{ $vuln['severity'] ?? 'medium' }}">{{ ucfirst($vuln['severity'] ?? '') }}</span></td>
                            <td class="text-gray-700">{{ $vuln['title'] ?? '' }}</td>
                            <td class="text-gray-500">{{ $vuln['software_slug'] ?? '' }} {{ $vuln['installed_version'] ?? '' }}</td>
                            <td class="text-gray-500">{{ $vuln['fixed_in_version'] ?? 'N/A' }}</td>
                        </tr>
                    @endforeach
                    </tbody></table>
                @endif
            </div>
            @endif

            {{-- ═══ UPDATES ═══ --}}
            @if(isset($s['updates']))
            @php $upd = $s['updates']; @endphp
            <div id="updates" class="section-card">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Updates Applied</h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5 mb-4">
                    <div class="metric-card"><p class="text-xs text-gray-500">Total</p><p class="text-2xl font-bold text-gray-900">{{ $upd['total_count'] ?? 0 }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Plugins</p><p class="text-xl font-bold text-gray-900">{{ $upd['plugin_count'] ?? 0 }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Themes</p><p class="text-xl font-bold text-gray-900">{{ $upd['theme_count'] ?? 0 }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Core</p><p class="text-xl font-bold text-gray-900">{{ $upd['core_count'] ?? 0 }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Failed</p><p class="text-xl font-bold {{ ($upd['failed_count'] ?? 0) > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $upd['failed_count'] ?? 0 }}</p></div>
                </div>
                @if(!empty($upd['all_updates']))
                    <table class="data-table"><thead><tr><th>Name</th><th>Type</th><th>From</th><th>To</th><th>Date</th><th>Status</th></tr></thead><tbody>
                    @foreach($upd['all_updates'] as $u)
                        <tr>
                            <td class="text-gray-700 font-medium">{{ $u['name'] ?? '' }}</td>
                            <td><span class="badge bg-gray-100 text-gray-600">{{ $u['type'] ?? '' }}</span></td>
                            <td class="text-gray-500 font-mono text-xs">{{ $u['from_version'] ?? '' }}</td>
                            <td class="text-gray-500 font-mono text-xs">{{ $u['to_version'] ?? '' }}</td>
                            <td class="text-gray-500 text-xs">{{ $u['performed_at'] ?? '' }}</td>
                            <td>@if(($u['success'] ?? true))<span class="badge badge-good">OK</span>@else<span class="badge badge-critical">Failed</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody></table>
                @endif
            </div>
            @endif

            {{-- ═══ BACKUPS ═══ --}}
            @if(isset($s['backups']))
            @php $bk = $s['backups']; @endphp
            <div id="backups" class="section-card">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Backups</h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-4">
                    <div class="metric-card"><p class="text-xs text-gray-500">Successful</p><p class="text-2xl font-bold text-green-600">{{ $bk['count'] ?? 0 }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Failed</p><p class="text-2xl font-bold {{ ($bk['failed_count'] ?? 0) > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $bk['failed_count'] ?? 0 }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Total Size</p><p class="text-2xl font-bold text-gray-900">{{ $bk['total_size'] ?? '—' }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Schedule</p><p class="text-sm font-bold text-gray-900">{{ ($bk['schedule_enabled'] ?? false) ? ucfirst($bk['frequency'] ?? 'daily') : 'Disabled' }}</p></div>
                </div>
                @if(!empty($bk['backups']))
                    <table class="data-table"><thead><tr><th>Type</th><th>Status</th><th>Size</th><th>Trigger</th><th>Date</th></tr></thead><tbody>
                    @foreach($bk['backups'] as $b)
                        <tr>
                            <td class="text-gray-700">{{ $b['type'] ?? '' }}</td>
                            <td><span class="badge {{ ($b['status'] ?? '') === 'completed' ? 'badge-good' : 'badge-critical' }}">{{ ucfirst($b['status'] ?? '') }}</span></td>
                            <td class="text-gray-500">{{ $b['file_size'] ?? '—' }}</td>
                            <td class="text-gray-500">{{ $b['trigger'] ?? '' }}</td>
                            <td class="text-gray-500 text-xs">{{ $b['created_at'] ?? '' }}</td>
                        </tr>
                    @endforeach
                    </tbody></table>
                @endif
            </div>
            @endif

            {{-- ═══ ANALYTICS ═══ --}}
            @if(!empty($s['analytics']))
            @php $an = $s['analytics']; @endphp
            <div id="analytics" class="section-card">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Analytics</h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-4">
                    <div class="metric-card"><p class="text-xs text-gray-500">Pageviews</p><p class="text-2xl font-bold text-gray-900">{{ number_format($an['total_pageviews'] ?? 0) }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Users</p><p class="text-2xl font-bold text-gray-900">{{ number_format($an['total_users'] ?? 0) }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Sessions</p><p class="text-2xl font-bold text-gray-900">{{ number_format($an['sessions'] ?? 0) }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Bounce Rate</p><p class="text-2xl font-bold text-gray-900">{{ number_format((float)($an['bounce_rate'] ?? 0), 1) }}%</p></div>
                </div>
                <div class="grid gap-6 sm:grid-cols-2">
                    @if(!empty($an['traffic_sources']))
                        <div>
                            <h3 class="text-sm font-semibold text-gray-700 mb-2">Traffic Sources</h3>
                            <table class="data-table"><thead><tr><th>Source</th><th>Users</th><th>Sessions</th></tr></thead><tbody>
                            @foreach(array_slice($an['traffic_sources'], 0, 10) as $src)
                                <tr><td class="text-gray-700">{{ $src['source'] ?? $src['channel'] ?? '' }}</td><td class="text-gray-500">{{ number_format($src['users'] ?? 0) }}</td><td class="text-gray-500">{{ number_format($src['sessions'] ?? 0) }}</td></tr>
                            @endforeach
                            </tbody></table>
                        </div>
                    @endif
                    @if(!empty($an['top_pages']))
                        <div>
                            <h3 class="text-sm font-semibold text-gray-700 mb-2">Top Pages</h3>
                            <table class="data-table"><thead><tr><th>Page</th><th>Views</th></tr></thead><tbody>
                            @foreach(array_slice($an['top_pages'], 0, 10) as $pg)
                                <tr><td class="text-gray-700 truncate max-w-[200px]">{{ $pg['page'] ?? $pg['path'] ?? '' }}</td><td class="text-gray-500">{{ number_format($pg['pageviews'] ?? $pg['views'] ?? 0) }}</td></tr>
                            @endforeach
                            </tbody></table>
                        </div>
                    @endif
                </div>
                @if(!empty($an['devices']))
                    <h3 class="text-sm font-semibold text-gray-700 mt-4 mb-2">Devices</h3>
                    <div class="flex gap-4">
                        @foreach($an['devices'] as $dev)
                            <div class="metric-card flex-1"><p class="text-xs text-gray-500">{{ ucfirst($dev['device'] ?? '') }}</p><p class="text-lg font-bold text-gray-900">{{ number_format($dev['sessions'] ?? $dev['users'] ?? 0) }}</p></div>
                        @endforeach
                    </div>
                @endif
            </div>
            @endif

            {{-- ═══ SEARCH CONSOLE ═══ --}}
            @if(!empty($s['search_console']))
            @php $sc = $s['search_console']; $scOv = $sc['overview'] ?? $sc; @endphp
            <div id="search_console" class="section-card">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Search Console</h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-4">
                    <div class="metric-card"><p class="text-xs text-gray-500">Clicks</p><p class="text-2xl font-bold text-gray-900">{{ number_format($scOv['total_clicks'] ?? 0) }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Impressions</p><p class="text-2xl font-bold text-gray-900">{{ number_format($scOv['total_impressions'] ?? 0) }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Avg CTR</p><p class="text-2xl font-bold text-gray-900">{{ number_format((float)($scOv['avg_ctr'] ?? 0), 2) }}%</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Avg Position</p><p class="text-2xl font-bold text-gray-900">{{ number_format((float)($scOv['avg_position'] ?? 0), 1) }}</p></div>
                </div>
                <div class="grid gap-6 sm:grid-cols-2">
                    @if(!empty($sc['queries']))
                        <div>
                            <h3 class="text-sm font-semibold text-gray-700 mb-2">Top Queries</h3>
                            <table class="data-table"><thead><tr><th>Query</th><th>Clicks</th><th>Impressions</th><th>Position</th></tr></thead><tbody>
                            @foreach(array_slice($sc['queries'], 0, 10) as $q)
                                <tr><td class="text-gray-700">{{ $q['query'] ?? '' }}</td><td class="text-gray-500">{{ $q['clicks'] ?? 0 }}</td><td class="text-gray-500">{{ $q['impressions'] ?? 0 }}</td><td class="text-gray-500">{{ number_format((float)($q['position'] ?? 0), 1) }}</td></tr>
                            @endforeach
                            </tbody></table>
                        </div>
                    @endif
                    @if(!empty($sc['pages']))
                        <div>
                            <h3 class="text-sm font-semibold text-gray-700 mb-2">Top Pages</h3>
                            <table class="data-table"><thead><tr><th>Page</th><th>Clicks</th><th>Impressions</th></tr></thead><tbody>
                            @foreach(array_slice($sc['pages'], 0, 10) as $pg)
                                <tr><td class="text-gray-700 truncate max-w-[200px]">{{ $pg['page'] ?? $pg['path'] ?? '' }}</td><td class="text-gray-500">{{ $pg['clicks'] ?? 0 }}</td><td class="text-gray-500">{{ $pg['impressions'] ?? 0 }}</td></tr>
                            @endforeach
                            </tbody></table>
                        </div>
                    @endif
                </div>
            </div>
            @endif

            {{-- ═══ PERFORMANCE ═══ --}}
            @if(!empty($s['performance']))
            @php $perf = $s['performance']; @endphp
            <div id="performance" class="section-card">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Performance</h2>
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div class="metric-card"><p class="text-xs text-gray-500">Mobile Score</p><p class="text-3xl font-bold {{ ($perf['mobile_score'] ?? 0) >= 90 ? 'text-green-600' : (($perf['mobile_score'] ?? 0) >= 50 ? 'text-amber-600' : 'text-red-600') }}">{{ $perf['mobile_score'] ?? '—' }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Desktop Score</p><p class="text-3xl font-bold {{ ($perf['desktop_score'] ?? 0) >= 90 ? 'text-green-600' : (($perf['desktop_score'] ?? 0) >= 50 ? 'text-amber-600' : 'text-red-600') }}">{{ $perf['desktop_score'] ?? '—' }}</p></div>
                </div>
                @foreach(['mobile', 'desktop'] as $device)
                    @if(isset($perf[$device]))
                    @php $d = $perf[$device]; @endphp
                    <h3 class="text-sm font-semibold text-gray-700 mt-4 mb-2">{{ ucfirst($device) }} — Core Web Vitals</h3>
                    <div class="grid grid-cols-5 gap-2">
                        @foreach(['fcp' => 'FCP', 'lcp' => 'LCP', 'cls' => 'CLS', 'tbt' => 'TBT', 'si' => 'SI'] as $key => $label)
                            <div class="rounded-lg border p-2 text-center {{ ($d[$key.'_color'] ?? '') === 'green' ? 'border-green-200 bg-green-50' : (($d[$key.'_color'] ?? '') === 'red' ? 'border-red-200 bg-red-50' : 'border-yellow-200 bg-yellow-50') }}">
                                <p class="text-[10px] text-gray-500">{{ $label }}</p>
                                <p class="text-sm font-bold text-gray-900">{{ $d[$key] ?? '—' }}</p>
                            </div>
                        @endforeach
                    </div>
                    @endif
                @endforeach
            </div>
            @endif

            {{-- ═══ PLUGIN INVENTORY ═══ --}}
            @if(isset($s['plugin_inventory']))
            @php $inv = $s['plugin_inventory']; @endphp
            <div id="plugins" class="section-card">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Plugins & Themes</h2>
                @if(!empty($inv['plugins']))
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Plugins ({{ count($inv['plugins']) }})</h3>
                    <div class="overflow-x-auto mb-4">
                    <table class="data-table"><thead><tr><th>Plugin</th><th>Version</th><th>Status</th><th>Update</th></tr></thead><tbody>
                    @foreach($inv['plugins'] as $p)
                        <tr>
                            <td class="text-gray-700 font-medium">{{ $p['name'] ?? '' }}</td>
                            <td class="text-gray-500 font-mono text-xs">{{ $p['version'] ?? '' }}</td>
                            <td><span class="badge {{ ($p['is_active'] ?? false) ? 'badge-good' : 'bg-gray-100 text-gray-600' }}">{{ ($p['is_active'] ?? false) ? 'Active' : 'Inactive' }}</span></td>
                            <td>@if($p['has_update'] ?? false)<span class="badge badge-medium">{{ $p['update_version'] ?? 'Available' }}</span>@else<span class="text-xs text-gray-400">—</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody></table>
                    </div>
                @endif
                @if(!empty($inv['themes']))
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Themes ({{ count($inv['themes']) }})</h3>
                    <table class="data-table"><thead><tr><th>Theme</th><th>Version</th><th>Status</th><th>Update</th></tr></thead><tbody>
                    @foreach($inv['themes'] as $t)
                        <tr>
                            <td class="text-gray-700 font-medium">{{ $t['name'] ?? '' }}</td>
                            <td class="text-gray-500 font-mono text-xs">{{ $t['version'] ?? '' }}</td>
                            <td><span class="badge {{ ($t['is_active'] ?? false) ? 'badge-good' : 'bg-gray-100 text-gray-600' }}">{{ ($t['is_active'] ?? false) ? 'Active' : 'Inactive' }}</span></td>
                            <td>@if($t['has_update'] ?? false)<span class="badge badge-medium">{{ $t['update_version'] ?? 'Available' }}</span>@else<span class="text-xs text-gray-400">—</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody></table>
                @endif
            </div>
            @endif

            {{-- ═══ DATABASE HEALTH ═══ --}}
            @if(!empty($s['database_health']))
            @php $dbh = $s['database_health']; @endphp
            <div id="database" class="section-card">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Database Health</h2>
                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div class="metric-card"><p class="text-xs text-gray-500">Database Size</p><p class="text-xl font-bold text-gray-900">{{ $dbh['size'] ?? '—' }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Tables</p><p class="text-xl font-bold text-gray-900">{{ $dbh['table_count'] ?? '—' }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Overhead</p><p class="text-xl font-bold {{ ($dbh['overhead'] ?? '0') !== '0' ? 'text-amber-600' : 'text-green-600' }}">{{ $dbh['overhead'] ?? '0 B' }}</p></div>
                </div>
            </div>
            @endif

            {{-- ═══ CLOUDFLARE ═══ --}}
            @if(!empty($s['cloudflare']))
            @php $cf = $s['cloudflare']; @endphp
            <div id="cloudflare" class="section-card">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Cloudflare / CDN</h2>
                <div class="grid grid-cols-3 gap-3">
                    <div class="metric-card"><p class="text-xs text-gray-500">Total Requests</p><p class="text-xl font-bold text-gray-900">{{ number_format($cf['total_requests'] ?? 0) }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Bandwidth</p><p class="text-xl font-bold text-gray-900">{{ $cf['bandwidth'] ?? '—' }}</p></div>
                    <div class="metric-card"><p class="text-xs text-gray-500">Cache Hit Ratio</p><p class="text-xl font-bold text-green-600">{{ isset($cf['cache_hit_ratio']) ? number_format((float)$cf['cache_hit_ratio'], 1).'%' : '—' }}</p></div>
                </div>
            </div>
            @endif

            {{-- ═══ WP USERS ═══ --}}
            @if(!empty($s['wp_users']))
            @php $wpu = $s['wp_users']; @endphp
            <div id="users" class="section-card">
                <h2 class="text-lg font-bold text-gray-900 mb-4">WordPress Users</h2>
                <table class="data-table"><thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Last Login</th></tr></thead><tbody>
                @foreach($wpu['users'] ?? $wpu as $user)
                    <tr>
                        <td class="text-gray-700 font-medium">{{ $user['username'] ?? $user['display_name'] ?? '' }}</td>
                        <td class="text-gray-500">{{ $user['email'] ?? '' }}</td>
                        <td><span class="badge bg-gray-100 text-gray-600">{{ ucfirst($user['role'] ?? '') }}</span></td>
                        <td class="text-gray-500 text-xs">{{ $user['last_login'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody></table>
            </div>
            @endif

            {{-- ═══ EMAIL HEALTH ═══ --}}
            @if(!empty($s['email']))
            @php $em = $s['email']; @endphp
            <div id="email" class="section-card">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Email Deliverability</h2>
                <div class="grid grid-cols-3 gap-3 mb-4">
                    @foreach(['spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC'] as $key => $label)
                        @php $exists = $em[$key.'_exists'] ?? false; $status = $em[$key.'_status'] ?? null; @endphp
                        <div class="metric-card">
                            <p class="text-xs text-gray-500">{{ $label }}</p>
                            <span class="badge {{ $exists && $status === 'valid' ? 'badge-good' : ($exists ? 'badge-medium' : 'badge-critical') }}">
                                {{ $exists ? ($status === 'valid' ? 'Valid' : 'Issues') : 'Missing' }}
                            </span>
                        </div>
                    @endforeach
                </div>
                @if(isset($em['score']))
                    <div class="metric-card inline-block"><p class="text-xs text-gray-500">Email Score</p><p class="text-2xl font-bold {{ ($em['score'] ?? 0) >= 80 ? 'text-green-600' : 'text-amber-600' }}">{{ $em['score'] }}/100</p></div>
                @endif
            </div>
            @endif

            {{-- ═══ SECURITY CHECKS ═══ --}}
            @if(!empty($s['security_checks']))
            @php $schk = $s['security_checks']; @endphp
            <div class="section-card">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Security Checks</h2>
                @foreach($schk['categories'] ?? $schk as $cat)
                    @if(is_array($cat) && isset($cat['name']))
                        <div class="mb-3">
                            <h3 class="text-sm font-semibold text-gray-700 mb-1">{{ $cat['name'] ?? '' }}</h3>
                            @foreach($cat['checks'] ?? [] as $check)
                                <div class="flex items-center gap-2 py-1">
                                    <span class="badge {{ ($check['status'] ?? '') === 'passed' ? 'badge-good' : 'badge-critical' }}">{{ ($check['status'] ?? '') === 'passed' ? 'Pass' : 'Fail' }}</span>
                                    <span class="text-sm text-gray-700">{{ $check['title'] ?? $check['label'] ?? '' }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </div>
            @endif

            {{-- ═══ RECOMMENDATIONS ═══ --}}
            @if(!empty($s['recommendations']))
            @php $recs = $s['recommendations']; @endphp
            <div id="recommendations" class="section-card">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Recommendations</h2>
                @foreach($recs['items'] ?? $recs as $rec)
                    @if(is_array($rec) && isset($rec['title']))
                        <div class="flex items-start gap-3 py-2 border-b border-gray-50">
                            <span class="badge mt-0.5 {{ ($rec['priority'] ?? '') === 'high' ? 'badge-critical' : (($rec['priority'] ?? '') === 'medium' ? 'badge-medium' : 'badge-low') }}">
                                {{ ucfirst($rec['priority'] ?? 'medium') }}
                            </span>
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $rec['title'] }}</p>
                                @if($rec['description'] ?? null)<p class="text-xs text-gray-500 mt-0.5">{{ $rec['description'] }}</p>@endif
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
            @endif

        </div>
    </div>

    <p class="py-8 text-center text-xs text-gray-400">Powered by {{ config('app.name') }}</p>
</body>
</html>
