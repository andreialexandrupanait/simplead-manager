<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $report->title }} — {{ $client->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/report.js'])
    <style>
        html { scroll-behavior: smooth; }
        [id] { scroll-margin-top: 5.5rem; }

        /* Flat metric — no nested card */
        .metric {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            border-radius: 0.75rem;
            border: 1px solid rgb(0 0 0 / 0.04);
        }
        .metric-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.5rem;
            flex-shrink: 0;
        }
        .metric-value { font-size: 1.25rem; font-weight: 700; color: #111827; line-height: 1.2; }
        .metric-label { font-size: 0.6875rem; color: #6b7280; margin-top: 1px; }

        /* Hero metric — bigger for overview */
        .metric-hero .metric-value { font-size: 1.75rem; }

        /* Chart wrapper — light border instead of card */
        .chart-wrap {
            border: 1px solid rgb(0 0 0 / 0.05);
            border-radius: 0.75rem;
            padding: 1rem;
            background: #fff;
        }

        /* Sub-heading */
        .sub-heading {
            font-size: 0.8125rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.75rem;
        }

        /* Recommendation card */
        .rec-card { transition: background-color 0.15s; }
        .rec-card:hover { background-color: rgb(249 250 251); }

        /* Print */
        @media print {
            body { background: #fff !important; }
            nav, .no-print, .sticky { display: none !important; position: static !important; }
            .print-break { page-break-before: always; }
            .chart-wrap, canvas { max-height: 250px !important; }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen" x-data="reportNav()">
    @php
        $s = $report->data_snapshot ?? [];
        $enabledSections = $s['_meta']['sections'] ?? array_keys($s);
    @endphp

    {{-- ── Fixed Header (72px) ─────────────────────────────────────────────── --}}
    <header class="sticky top-0 z-20 h-[72px] border-b border-gray-200 bg-white/95 backdrop-blur flex items-center">
        <div class="mx-auto w-full max-w-6xl px-4 flex items-center justify-between">
            <div class="min-w-0">
                <a href="{{ route('client-portal.show', $client->portal_token) }}" class="text-xs text-purple-600 hover:text-purple-800 font-medium">&larr; Back to portal</a>
                <h1 class="text-base font-bold text-gray-900 truncate">{{ $report->title }}</h1>
                <p class="text-[11px] text-gray-500">{{ $report->period_start->format('M j, Y') }} — {{ $report->period_end->format('M j, Y') }}</p>
            </div>
            @if($report->file_path)
                <a href="{{ route('client-portal.download', [$client->portal_token, $report]) }}"
                   class="no-print ml-4 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 transition flex-shrink-0">
                    Download PDF
                </a>
            @endif
        </div>
    </header>

    {{-- ── Mobile nav (horizontal scroll) ──────────────────────────────────── --}}
    @php
        $on = array_flip($enabledSections);
        $nav = array_filter([
            'overview' => isset($on['overview']) && (isset($s['executive_snapshot']) || isset($s['overview'])) ? 'Overview' : null,
            'uptime' => isset($on['uptime']) && isset($s['uptime']) ? 'Uptime' : null,
            'security' => isset($on['security']) && isset($s['security']) ? 'Security' : null,
            'updates' => isset($on['updates']) && isset($s['updates']) ? 'Updates' : null,
            'backups' => isset($on['backups']) && isset($s['backups']) ? 'Backups' : null,
            'analytics' => isset($on['analytics']) && !empty($s['analytics']) ? 'Analytics' : null,
            'search_console' => isset($on['search_console']) && !empty($s['search_console']) ? 'Search Console' : null,
            'performance' => isset($on['performance']) && !empty($s['performance']) ? 'Performance' : null,
            'plugins' => isset($on['plugin_inventory']) && isset($s['plugin_inventory']) ? 'Plugins' : null,
            'database' => isset($on['database_health']) && !empty($s['database_health']) ? 'Database' : null,
            'cloudflare' => isset($on['cloudflare']) && !empty($s['cloudflare']) ? 'Cloudflare' : null,
            'users' => isset($on['wp_users']) && !empty($s['wp_users']) ? 'WP Users' : null,
            'email' => isset($on['infrastructure']) && !empty($s['email']) ? 'Email' : null,
            'recommendations' => isset($on['overview']) && isset($s['recommendations']) ? 'Actions' : null,
        ]);
    @endphp
    <div class="lg:hidden sticky top-[72px] z-10 bg-white border-b border-gray-100 no-print">
        <div class="flex gap-1 px-4 py-2 overflow-x-auto scrollbar-hide">
            @foreach($nav as $id => $label)
                <a href="#{{ $id }}"
                   class="flex-shrink-0 rounded-full px-3 py-1 text-xs font-medium transition"
                   :class="active === '{{ $id }}' ? 'bg-purple-100 text-purple-700' : 'text-gray-500 hover:bg-gray-100'">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    <div class="mx-auto max-w-6xl px-4 py-6 lg:flex lg:gap-6">
        {{-- ── Desktop Sidebar ─────────────────────────────────────────────── --}}
        <nav class="hidden lg:block w-48 flex-shrink-0 no-print">
            <div class="sticky top-[90px] rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-3 space-y-0.5">
                <p class="px-2 pb-2 text-[10px] font-bold uppercase tracking-wider text-gray-400">Sections</p>
                @foreach($nav as $id => $label)
                    <a href="#{{ $id }}"
                       class="block rounded-lg px-2.5 py-1.5 text-[13px] font-medium transition"
                       :class="active === '{{ $id }}' ? 'bg-purple-50 text-purple-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'">{{ $label }}</a>
                @endforeach
            </div>
        </nav>

        {{-- ── Main Content ────────────────────────────────────────────────── --}}
        <div class="flex-1 min-w-0 space-y-6">

            {{-- ═══ OVERVIEW (hero) ═══ --}}
            @if(isset($on['overview']) && (isset($s['overview']) || isset($s['executive_snapshot'])))
            @php $ov = $s['overview'] ?? []; @endphp
            <section id="overview" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6">
                <div class="flex items-center gap-2 mb-5">
                    <x-icons.layout-dashboard class="h-5 w-5 text-purple-600" />
                    <h2 class="text-lg font-bold text-gray-900">Overview</h2>
                </div>

                {{-- Hero row — big numbers --}}
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-4">
                    @if(isset($ov['uptime']))
                        <div class="metric metric-hero bg-green-50/60">
                            <div class="metric-icon bg-green-100"><x-icons.activity class="h-4 w-4 text-green-600" /></div>
                            <div>
                                <p class="metric-value {{ ($ov['uptime']['percentage'] ?? 0) >= 99 ? 'text-green-600' : 'text-amber-600' }}">{{ isset($ov['uptime']['percentage']) ? number_format((float)$ov['uptime']['percentage'], 2).'%' : '—' }}</p>
                                <p class="metric-label">Uptime</p>
                            </div>
                        </div>
                    @endif
                    @if(isset($ov['security']))
                        <div class="metric metric-hero bg-blue-50/60">
                            <div class="metric-icon bg-blue-100"><x-icons.shield class="h-4 w-4 text-blue-600" /></div>
                            <div>
                                <p class="metric-value {{ ($ov['security']['score'] ?? 0) >= 80 ? 'text-blue-600' : 'text-amber-600' }}">{{ ($ov['security']['score'] ?? '—') }}<span class="text-sm font-normal text-gray-400">/100</span></p>
                                <p class="metric-label">Security Score</p>
                            </div>
                        </div>
                    @endif
                    @if(isset($ov['performance']))
                        <div class="metric metric-hero bg-purple-50/60">
                            <div class="metric-icon bg-purple-100"><x-icons.zap class="h-4 w-4 text-purple-600" /></div>
                            <div>
                                <p class="metric-value text-purple-600">{{ $ov['performance']['mobile'] ?? '—' }} <span class="text-sm font-normal text-gray-400">/ {{ $ov['performance']['desktop'] ?? '—' }}</span></p>
                                <p class="metric-label">Mobile / Desktop</p>
                            </div>
                        </div>
                    @endif
                    @if(isset($ov['updates']))
                        <div class="metric metric-hero bg-gray-50">
                            <div class="metric-icon bg-gray-100"><x-icons.refresh-cw class="h-4 w-4 text-gray-600" /></div>
                            <div>
                                <p class="metric-value">{{ $ov['updates']['count'] ?? 0 }}</p>
                                <p class="metric-label">Updates Applied</p>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Secondary row --}}
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @if(isset($ov['backups']))
                        <div class="metric bg-gray-50/50">
                            <div><p class="metric-value text-green-600" style="font-size:1.1rem">{{ $ov['backups']['successful'] ?? 0 }}</p><p class="metric-label">Backups OK</p></div>
                        </div>
                    @endif
                    @if(isset($ov['analytics']))
                        <div class="metric bg-gray-50/50">
                            <div><p class="metric-value" style="font-size:1.1rem">{{ number_format((int)($ov['analytics']['pageviews'] ?? 0)) }}</p><p class="metric-label">Pageviews</p></div>
                        </div>
                    @endif
                    @if(isset($ov['search_console']))
                        <div class="metric bg-gray-50/50">
                            <div><p class="metric-value" style="font-size:1.1rem">{{ number_format((int)($ov['search_console']['clicks'] ?? 0)) }}</p><p class="metric-label">Search Clicks</p></div>
                        </div>
                    @endif
                </div>
            </section>
            @endif

            {{-- ═══ UPTIME ═══ --}}
            @if(isset($on['uptime']) && isset($s['uptime']) && ($s['uptime']['available'] ?? false))
            @php $ut = $s['uptime']; @endphp
            <section id="uptime" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6">
                <div class="flex items-center gap-2 mb-5">
                    <x-icons.activity class="h-5 w-5 text-green-600" />
                    <h2 class="text-lg font-bold text-gray-900">Uptime & Availability</h2>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="metric bg-green-50/50"><div><p class="metric-value text-green-600">{{ number_format((float)($ut['uptime_percentage'] ?? 0), 3) }}%</p><p class="metric-label">Uptime</p></div></div>
                    <div class="metric bg-purple-50/50"><div><p class="metric-value">{{ $ut['avg_response_time'] ?? '—' }}<span class="text-sm font-normal text-gray-400">ms</span></p><p class="metric-label">Avg Response</p></div></div>
                    <div class="metric {{ ($ut['incidents_count'] ?? 0) > 0 ? 'bg-red-50/50' : 'bg-green-50/50' }}"><div><p class="metric-value {{ ($ut['incidents_count'] ?? 0) > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $ut['incidents_count'] ?? 0 }}</p><p class="metric-label">Incidents</p></div></div>
                    <div class="metric bg-gray-50/50"><div><p class="metric-value" style="font-size:1rem">{{ $ut['formatted_downtime'] ?? 'None' }}</p><p class="metric-label">Total Downtime</p></div></div>
                </div>

                @if(!empty($ut['response_time_chart']))
                    <div class="mt-6">
                        <p class="sub-heading">Response Time Trend</p>
                        <div class="chart-wrap">
                            <x-charts.line-chart
                                :labels="array_map(fn($r) => date('d M', strtotime($r['date'] ?? '')), $ut['response_time_chart'])"
                                :datasets="[['label' => 'Response Time (ms)', 'data' => array_map(fn($r) => round((float)($r['avg_response_time'] ?? 0)), $ut['response_time_chart']), 'color' => '#8D5CF5']]"
                                height="200px"
                            />
                        </div>
                    </div>
                @endif

                @if(!empty($ut['incidents']))
                    <div class="mt-6">
                        <p class="sub-heading">Incidents</p>
                        <x-ui.table>
                            <x-slot:head>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cause</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Started</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Duration</th>
                            </x-slot:head>
                            @foreach($ut['incidents'] as $inc)
                                <tr>
                                    <td class="px-4 py-3"><x-ui.badge :variant="($inc['status'] ?? '') === 'resolved' ? 'green' : 'red'">{{ ucfirst($inc['status'] ?? 'unknown') }}</x-ui.badge></td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $inc['cause'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ isset($inc['started_at']) ? \Carbon\Carbon::parse($inc['started_at'])->format('d M Y, H:i') : '' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $inc['duration'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </div>
                @endif
            </section>
            @endif

            {{-- ═══ SECURITY ═══ --}}
            @if(isset($on['security']) && isset($s['security']))
            @php $sec = $s['security']; @endphp
            <section id="security" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6">
                <div class="flex items-center gap-2 mb-5">
                    <x-icons.shield class="h-5 w-5 text-blue-600" />
                    <h2 class="text-lg font-bold text-gray-900">Security</h2>
                </div>

                <div class="flex flex-col sm:flex-row gap-6 items-start">
                    {{-- Score gauge --}}
                    <div class="flex-shrink-0 flex flex-col items-center">
                        <x-performance.score-gauge :score="isset($sec['score']) ? (int)$sec['score'] : null" label="Security Score" size="lg" />
                    </div>
                    {{-- Severity counts --}}
                    <div class="flex-1 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="metric {{ ($sec['critical_count'] ?? 0) > 0 ? 'bg-red-50/60' : 'bg-green-50/50' }}"><div><p class="metric-value {{ ($sec['critical_count'] ?? 0) > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $sec['critical_count'] ?? 0 }}</p><p class="metric-label">Critical</p></div></div>
                        <div class="metric {{ ($sec['high_count'] ?? 0) > 0 ? 'bg-orange-50/60' : 'bg-green-50/50' }}"><div><p class="metric-value {{ ($sec['high_count'] ?? 0) > 0 ? 'text-orange-600' : 'text-green-600' }}">{{ $sec['high_count'] ?? 0 }}</p><p class="metric-label">High</p></div></div>
                        <div class="metric bg-yellow-50/50"><div><p class="metric-value text-yellow-600">{{ $sec['medium_count'] ?? 0 }}</p><p class="metric-label">Medium</p></div></div>
                        <div class="metric bg-blue-50/50"><div><p class="metric-value text-blue-600">{{ $sec['low_count'] ?? 0 }}</p><p class="metric-label">Low</p></div></div>
                    </div>
                </div>

                @if(!empty($sec['active_issues']))
                    <div class="mt-6">
                        <p class="sub-heading">Active Issues</p>
                        <x-ui.table>
                            <x-slot:head>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Severity</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Issue</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Recommendation</th>
                            </x-slot:head>
                            @foreach($sec['active_issues'] as $issue)
                                <tr>
                                    <td class="px-4 py-3">@php $sev = $issue['severity'] ?? 'medium'; @endphp<x-ui.badge :variant="match($sev) { 'critical' => 'red', 'high' => 'orange', 'medium' => 'yellow', default => 'blue' }">{{ ucfirst($sev) }}</x-ui.badge></td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $issue['title'] ?? '' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $issue['recommendation'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </div>
                @endif

                @if(!empty($sec['vulnerabilities']))
                    <div class="mt-6">
                        <p class="sub-heading">Vulnerabilities</p>
                        <div class="max-h-80 overflow-y-auto rounded-xl">
                            <x-ui.table>
                                <x-slot:head>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Severity</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Title</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plugin/Theme</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fixed In</th>
                                </x-slot:head>
                                @foreach($sec['vulnerabilities'] as $vuln)
                                    <tr>
                                        <td class="px-4 py-3">@php $sev = $vuln['severity'] ?? 'medium'; @endphp<x-ui.badge :variant="match($sev) { 'critical' => 'red', 'high' => 'orange', 'medium' => 'yellow', default => 'blue' }">{{ ucfirst($sev) }}</x-ui.badge></td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ $vuln['title'] ?? '' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-500">{{ $vuln['software_slug'] ?? '' }} {{ $vuln['installed_version'] ?? '' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-500">{{ $vuln['fixed_in_version'] ?? 'N/A' }}</td>
                                    </tr>
                                @endforeach
                            </x-ui.table>
                        </div>
                    </div>
                @endif
            </section>
            @endif

            {{-- ═══ UPDATES ═══ --}}
            @if(isset($on['updates']) && isset($s['updates']))
            @php $upd = $s['updates']; @endphp
            <section id="updates" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6">
                <div class="flex items-center gap-2 mb-5">
                    <x-icons.refresh-cw class="h-5 w-5 text-blue-600" />
                    <h2 class="text-lg font-bold text-gray-900">Updates Applied</h2>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <div class="metric bg-purple-50/50"><div><p class="metric-value">{{ $upd['total_count'] ?? 0 }}</p><p class="metric-label">Total</p></div></div>
                    <div class="metric bg-blue-50/50"><div><p class="metric-value">{{ $upd['plugin_count'] ?? 0 }}</p><p class="metric-label">Plugins</p></div></div>
                    <div class="metric bg-blue-50/50"><div><p class="metric-value">{{ $upd['theme_count'] ?? 0 }}</p><p class="metric-label">Themes</p></div></div>
                    <div class="metric bg-blue-50/50"><div><p class="metric-value">{{ $upd['core_count'] ?? 0 }}</p><p class="metric-label">Core</p></div></div>
                    <div class="metric {{ ($upd['failed_count'] ?? 0) > 0 ? 'bg-red-50/50' : 'bg-green-50/50' }}"><div><p class="metric-value {{ ($upd['failed_count'] ?? 0) > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $upd['failed_count'] ?? 0 }}</p><p class="metric-label">Failed</p></div></div>
                </div>
                @if(!empty($upd['all_updates']))
                    <div class="mt-6 max-h-96 overflow-y-auto rounded-xl">
                        <x-ui.table>
                            <x-slot:head>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">From</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">To</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            </x-slot:head>
                            @foreach($upd['all_updates'] as $u)
                                <tr>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-700">{{ $u['name'] ?? '' }}</td>
                                    <td class="px-4 py-3"><x-ui.badge variant="gray">{{ $u['type'] ?? '' }}</x-ui.badge></td>
                                    <td class="px-4 py-3 text-sm text-gray-500 font-mono">{{ $u['from_version'] ?? '' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500 font-mono">{{ $u['to_version'] ?? '' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ isset($u['performed_at']) ? \Carbon\Carbon::parse($u['performed_at'])->format('d M Y') : '' }}</td>
                                    <td class="px-4 py-3">@if($u['success'] ?? true)<x-ui.badge variant="green">OK</x-ui.badge>@else<x-ui.badge variant="red">Failed</x-ui.badge>@endif</td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </div>
                @endif
            </section>
            @endif

            {{-- ═══ BACKUPS ═══ --}}
            @if(isset($on['backups']) && isset($s['backups']))
            @php $bk = $s['backups']; $bkSuccess = (int)($bk['count'] ?? 0); $bkFailed = (int)($bk['failed_count'] ?? 0); @endphp
            <section id="backups" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6">
                <div class="flex items-center gap-2 mb-5">
                    <x-icons.hard-drive class="h-5 w-5 text-green-600" />
                    <h2 class="text-lg font-bold text-gray-900">Backups</h2>
                </div>
                <div class="flex flex-col sm:flex-row gap-6 items-start">
                    @if($bkSuccess + $bkFailed > 0)
                        <div class="flex-shrink-0" style="width: 180px;">
                            <x-charts.donut-chart
                                :labels="['Successful', 'Failed']"
                                :data="[$bkSuccess, $bkFailed]"
                                :colors="['#22c55e', '#ef4444']"
                                :centerText="$bkSuccess . '/' . ($bkSuccess + $bkFailed)"
                                height="180px"
                            />
                        </div>
                    @endif
                    <div class="flex-1 grid grid-cols-2 gap-3">
                        <div class="metric bg-green-50/50"><div><p class="metric-value text-green-600">{{ $bkSuccess }}</p><p class="metric-label">Successful</p></div></div>
                        <div class="metric {{ $bkFailed > 0 ? 'bg-red-50/50' : 'bg-green-50/50' }}"><div><p class="metric-value {{ $bkFailed > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $bkFailed }}</p><p class="metric-label">Failed</p></div></div>
                        <div class="metric bg-purple-50/50"><div><p class="metric-value" style="font-size:1rem">{{ $bk['total_size'] ?? '—' }}</p><p class="metric-label">Total Size</p></div></div>
                        <div class="metric bg-gray-50/50"><div><p class="metric-value" style="font-size:1rem">{{ ($bk['schedule_enabled'] ?? false) ? ucfirst($bk['frequency'] ?? 'daily') : 'Disabled' }}</p><p class="metric-label">Schedule</p></div></div>
                    </div>
                </div>

                @if(!empty($bk['backups']))
                    <div class="mt-6 max-h-72 overflow-y-auto rounded-xl">
                        <x-ui.table>
                            <x-slot:head>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Size</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Trigger</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            </x-slot:head>
                            @foreach($bk['backups'] as $b)
                                <tr>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $b['type'] ?? '' }}</td>
                                    <td class="px-4 py-3"><x-ui.badge :variant="($b['status'] ?? '') === 'completed' ? 'green' : 'red'">{{ ucfirst($b['status'] ?? '') }}</x-ui.badge></td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $b['file_size'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $b['trigger'] ?? '' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ isset($b['created_at']) ? \Carbon\Carbon::parse($b['created_at'])->format('d M Y, H:i') : '' }}</td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </div>
                @endif
            </section>
            @endif

            {{-- ═══ ANALYTICS ═══ --}}
            @if(isset($on['analytics']) && !empty($s['analytics']))
            @php $an = $s['analytics']; @endphp
            <section id="analytics" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6">
                <div class="flex items-center gap-2 mb-5">
                    <x-icons.bar-chart-2 class="h-5 w-5 text-purple-600" />
                    <h2 class="text-lg font-bold text-gray-900">Analytics</h2>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="metric bg-purple-50/50"><div><p class="metric-value">{{ number_format((int)($an['total_pageviews'] ?? 0)) }}</p><p class="metric-label">Pageviews</p></div></div>
                    <div class="metric bg-blue-50/50"><div><p class="metric-value">{{ number_format((int)($an['total_users'] ?? 0)) }}</p><p class="metric-label">Users</p></div></div>
                    <div class="metric bg-blue-50/50"><div><p class="metric-value">{{ number_format((int)($an['sessions'] ?? 0)) }}</p><p class="metric-label">Sessions</p></div></div>
                    <div class="metric bg-yellow-50/50"><div><p class="metric-value">{{ number_format((float)($an['bounce_rate'] ?? 0), 1) }}%</p><p class="metric-label">Bounce Rate</p></div></div>
                </div>

                @if(!empty($an['daily_users']))
                    <div class="mt-6">
                        <p class="sub-heading">Daily Users</p>
                        <div class="chart-wrap">
                            <x-charts.line-chart
                                :labels="array_map(fn($d) => date('d M', strtotime($d['date'] ?? '')), $an['daily_users'])"
                                :datasets="[['label' => 'Users', 'data' => array_map(fn($d) => (int)($d['users'] ?? 0), $an['daily_users']), 'color' => '#8D5CF5']]"
                                height="200px"
                            />
                        </div>
                    </div>
                @endif

                <div class="mt-6 grid gap-6 sm:grid-cols-2">
                    @if(!empty($an['traffic_sources']))
                        <div>
                            <p class="sub-heading">Traffic Sources</p>
                            <div class="chart-wrap">
                                <x-charts.bar-chart
                                    :labels="array_map(fn($src) => $src['source'] ?? $src['channel'] ?? '', array_slice($an['traffic_sources'], 0, 6))"
                                    :data="array_map(fn($src) => (int)($src['users'] ?? 0), array_slice($an['traffic_sources'], 0, 6))"
                                    color="#8D5CF5"
                                    :horizontal="true"
                                    height="200px"
                                />
                            </div>
                        </div>
                    @endif
                    @if(!empty($an['devices']))
                        <div>
                            <p class="sub-heading">Devices</p>
                            <div class="chart-wrap">
                                <x-charts.donut-chart
                                    :labels="array_map(fn($d) => ucfirst($d['device'] ?? ''), $an['devices'])"
                                    :data="array_map(fn($d) => (int)($d['sessions'] ?? $d['users'] ?? 0), $an['devices'])"
                                    :colors="['#8D5CF5', '#06b6d4', '#f59e0b', '#ef4444', '#10b981']"
                                    height="200px"
                                />
                            </div>
                        </div>
                    @endif
                </div>

                @if(!empty($an['top_pages']))
                    <div class="mt-6">
                        <p class="sub-heading">Top Pages</p>
                        <div class="max-h-72 overflow-y-auto rounded-xl">
                            <x-ui.table>
                                <x-slot:head>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Page</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Views</th>
                                </x-slot:head>
                                @foreach(array_slice($an['top_pages'], 0, 10) as $pg)
                                    <tr>
                                        <td class="px-4 py-3 text-sm text-gray-700 truncate max-w-[300px]">{{ $pg['page'] ?? $pg['path'] ?? '' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-500 text-right">{{ number_format((int)($pg['pageviews'] ?? $pg['views'] ?? 0)) }}</td>
                                    </tr>
                                @endforeach
                            </x-ui.table>
                        </div>
                    </div>
                @endif
            </section>
            @endif

            {{-- ═══ SEARCH CONSOLE ═══ --}}
            @if(isset($on['search_console']) && !empty($s['search_console']))
            @php $sc = $s['search_console']; $scOv = $sc['overview'] ?? $sc; @endphp
            <section id="search_console" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6">
                <div class="flex items-center gap-2 mb-5">
                    <x-icons.search class="h-5 w-5 text-green-600" />
                    <h2 class="text-lg font-bold text-gray-900">Search Console</h2>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="metric bg-green-50/50"><div><p class="metric-value text-green-600">{{ number_format((int)($scOv['total_clicks'] ?? 0)) }}</p><p class="metric-label">Clicks</p></div></div>
                    <div class="metric bg-blue-50/50"><div><p class="metric-value">{{ number_format((int)($scOv['total_impressions'] ?? 0)) }}</p><p class="metric-label">Impressions</p></div></div>
                    <div class="metric bg-purple-50/50"><div><p class="metric-value">{{ number_format((float)($scOv['avg_ctr'] ?? 0), 2) }}%</p><p class="metric-label">Avg CTR</p></div></div>
                    <div class="metric bg-purple-50/50"><div><p class="metric-value">{{ number_format((float)($scOv['avg_position'] ?? 0), 1) }}</p><p class="metric-label">Avg Position</p></div></div>
                </div>

                @if(!empty($sc['queries']))
                    <div class="mt-6">
                        <p class="sub-heading">Top Queries by Clicks</p>
                        <div class="chart-wrap">
                            <x-charts.bar-chart
                                :labels="array_map(fn($q) => \Illuminate\Support\Str::limit($q['query'] ?? '', 30), array_slice($sc['queries'], 0, 8))"
                                :data="array_map(fn($q) => (int)($q['clicks'] ?? 0), array_slice($sc['queries'], 0, 8))"
                                color="#8D5CF5"
                                :horizontal="true"
                                height="240px"
                            />
                        </div>
                    </div>
                @endif

                <div class="mt-6 grid gap-6 sm:grid-cols-2">
                    @if(!empty($sc['queries']))
                        <div>
                            <p class="sub-heading">Query Details</p>
                            <div class="max-h-72 overflow-y-auto rounded-xl">
                                <x-ui.table>
                                    <x-slot:head>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Query</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Clicks</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Pos</th>
                                    </x-slot:head>
                                    @foreach(array_slice($sc['queries'], 0, 10) as $q)
                                        <tr>
                                            <td class="px-4 py-3 text-sm text-gray-700">{{ $q['query'] ?? '' }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-500 text-right">{{ $q['clicks'] ?? 0 }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-500 text-right">{{ number_format((float)($q['position'] ?? 0), 1) }}</td>
                                        </tr>
                                    @endforeach
                                </x-ui.table>
                            </div>
                        </div>
                    @endif
                    @if(!empty($sc['pages']))
                        <div>
                            <p class="sub-heading">Top Pages</p>
                            <div class="max-h-72 overflow-y-auto rounded-xl">
                                <x-ui.table>
                                    <x-slot:head>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Page</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Clicks</th>
                                    </x-slot:head>
                                    @foreach(array_slice($sc['pages'], 0, 10) as $pg)
                                        <tr>
                                            <td class="px-4 py-3 text-sm text-gray-700 truncate max-w-[200px]">{{ $pg['page'] ?? $pg['path'] ?? '' }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-500 text-right">{{ $pg['clicks'] ?? 0 }}</td>
                                        </tr>
                                    @endforeach
                                </x-ui.table>
                            </div>
                        </div>
                    @endif
                </div>
            </section>
            @endif

            {{-- ═══ PERFORMANCE ═══ --}}
            @if(isset($on['performance']) && !empty($s['performance']))
            @php $perf = $s['performance']; @endphp
            <section id="performance" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6">
                <div class="flex items-center gap-2 mb-5">
                    <x-icons.zap class="h-5 w-5 text-yellow-600" />
                    <h2 class="text-lg font-bold text-gray-900">Performance</h2>
                </div>
                <div class="flex justify-center gap-16 mb-6 py-4">
                    <x-performance.score-gauge :score="isset($perf['mobile_score']) ? (int)$perf['mobile_score'] : null" label="Mobile" size="lg" />
                    <x-performance.score-gauge :score="isset($perf['desktop_score']) ? (int)$perf['desktop_score'] : null" label="Desktop" size="lg" />
                </div>
                @foreach(['mobile', 'desktop'] as $device)
                    @if(isset($perf[$device]))
                    @php $d = $perf[$device]; @endphp
                    <div class="mt-4">
                        <p class="sub-heading">{{ ucfirst($device) }} — Core Web Vitals</p>
                        <div class="grid grid-cols-5 gap-3">
                            @foreach(['fcp' => 'FCP', 'lcp' => 'LCP', 'cls' => 'CLS', 'tbt' => 'TBT', 'si' => 'SI'] as $key => $label)
                                @php $clr = $d[$key.'_color'] ?? 'gray'; @endphp
                                <div class="rounded-lg border p-3 text-center {{ match($clr) { 'green' => 'border-green-200 bg-green-50', 'red' => 'border-red-200 bg-red-50', default => 'border-yellow-200 bg-yellow-50' } }}">
                                    <p class="text-[10px] font-medium text-gray-500 uppercase">{{ $label }}</p>
                                    <p class="text-sm font-bold text-gray-900 mt-1">{{ $d[$key] ?? '—' }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                @endforeach
            </section>
            @endif

            {{-- ═══ SECONDARY SECTIONS (lighter visual weight) ═══ --}}
            <div class="space-y-4">

                {{-- ═══ PLUGIN INVENTORY ═══ --}}
                @if(isset($on['plugin_inventory']) && isset($s['plugin_inventory']))
                @php $inv = $s['plugin_inventory']; @endphp
                <section id="plugins" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-5">
                    <div class="flex items-center gap-2 mb-4">
                        <x-icons.puzzle class="h-4 w-4 text-purple-500" />
                        <h2 class="text-base font-bold text-gray-900">Plugins & Themes</h2>
                    </div>
                    @if(!empty($inv['plugins']))
                        <p class="sub-heading">Plugins ({{ count($inv['plugins']) }})</p>
                        <div class="max-h-72 overflow-y-auto rounded-xl">
                            <x-ui.table>
                                <x-slot:head>
                                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Plugin</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Version</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Update</th>
                                </x-slot:head>
                                @foreach($inv['plugins'] as $p)
                                    <tr>
                                        <td class="px-4 py-2.5 text-sm font-medium text-gray-700">{{ $p['name'] ?? '' }}</td>
                                        <td class="px-4 py-2.5 text-sm text-gray-500 font-mono">{{ $p['version'] ?? '' }}</td>
                                        <td class="px-4 py-2.5"><x-ui.badge :variant="($p['is_active'] ?? false) ? 'green' : 'gray'">{{ ($p['is_active'] ?? false) ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                                        <td class="px-4 py-2.5">@if($p['has_update'] ?? false)<x-ui.badge variant="yellow">{{ $p['update_version'] ?? 'Available' }}</x-ui.badge>@else<span class="text-xs text-gray-400">—</span>@endif</td>
                                    </tr>
                                @endforeach
                            </x-ui.table>
                        </div>
                    @endif
                    @if(!empty($inv['themes']))
                        <p class="sub-heading mt-5">Themes ({{ count($inv['themes']) }})</p>
                        <x-ui.table>
                            <x-slot:head>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Theme</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Version</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Update</th>
                            </x-slot:head>
                            @foreach($inv['themes'] as $t)
                                <tr>
                                    <td class="px-4 py-2.5 text-sm font-medium text-gray-700">{{ $t['name'] ?? '' }}</td>
                                    <td class="px-4 py-2.5 text-sm text-gray-500 font-mono">{{ $t['version'] ?? '' }}</td>
                                    <td class="px-4 py-2.5"><x-ui.badge :variant="($t['is_active'] ?? false) ? 'green' : 'gray'">{{ ($t['is_active'] ?? false) ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                                    <td class="px-4 py-2.5">@if($t['has_update'] ?? false)<x-ui.badge variant="yellow">{{ $t['update_version'] ?? 'Available' }}</x-ui.badge>@else<span class="text-xs text-gray-400">—</span>@endif</td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    @endif
                </section>
                @endif

                {{-- ═══ INFRASTRUCTURE ROW (Database + Cloudflare + Email) ═══ --}}
                <div class="grid gap-4 sm:grid-cols-3">
                    @if(isset($on['database_health']) && !empty($s['database_health']))
                    @php $dbh = $s['database_health']; @endphp
                    <section id="database" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <x-icons.database class="h-4 w-4 text-blue-500" />
                            <h2 class="text-sm font-bold text-gray-900">Database Health</h2>
                        </div>
                        <div class="space-y-2">
                            <div class="flex justify-between"><span class="text-xs text-gray-500">Size</span><span class="text-sm font-semibold text-gray-900">{{ $dbh['size'] ?? '—' }}</span></div>
                            <div class="flex justify-between"><span class="text-xs text-gray-500">Tables</span><span class="text-sm font-semibold text-gray-900">{{ $dbh['table_count'] ?? '—' }}</span></div>
                            <div class="flex justify-between"><span class="text-xs text-gray-500">Overhead</span><span class="text-sm font-semibold {{ ($dbh['overhead'] ?? '0') !== '0' && ($dbh['overhead'] ?? '0') !== '0 B' ? 'text-amber-600' : 'text-green-600' }}">{{ $dbh['overhead'] ?? '0 B' }}</span></div>
                        </div>
                    </section>
                    @endif

                    @if(isset($on['cloudflare']) && !empty($s['cloudflare']))
                    @php $cf = $s['cloudflare']; @endphp
                    <section id="cloudflare" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <x-icons.cloud class="h-4 w-4 text-orange-500" />
                            <h2 class="text-sm font-bold text-gray-900">Cloudflare / CDN</h2>
                        </div>
                        <div class="space-y-2">
                            <div class="flex justify-between"><span class="text-xs text-gray-500">Requests</span><span class="text-sm font-semibold text-gray-900">{{ number_format((int)($cf['total_requests'] ?? 0)) }}</span></div>
                            <div class="flex justify-between"><span class="text-xs text-gray-500">Bandwidth</span><span class="text-sm font-semibold text-gray-900">{{ $cf['bandwidth'] ?? '—' }}</span></div>
                            <div class="flex justify-between"><span class="text-xs text-gray-500">Cache Hit</span><span class="text-sm font-semibold text-green-600">{{ isset($cf['cache_hit_ratio']) ? number_format((float)$cf['cache_hit_ratio'], 1).'%' : '—' }}</span></div>
                        </div>
                    </section>
                    @endif

                    @if(isset($on['infrastructure']) && !empty($s['email']))
                    @php $em = $s['email']; @endphp
                    <section id="email" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <x-icons.mail class="h-4 w-4 text-blue-500" />
                            <h2 class="text-sm font-bold text-gray-900">Email Health</h2>
                        </div>
                        <div class="space-y-2">
                            @foreach(['spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC'] as $key => $label)
                                @php $exists = $em[$key.'_exists'] ?? false; $status = $em[$key.'_status'] ?? null; @endphp
                                <div class="flex justify-between items-center">
                                    <span class="text-xs text-gray-500">{{ $label }}</span>
                                    <x-ui.badge :variant="$exists && $status === 'valid' ? 'green' : ($exists ? 'yellow' : 'red')">{{ $exists ? ($status === 'valid' ? 'Valid' : 'Issues') : 'Missing' }}</x-ui.badge>
                                </div>
                            @endforeach
                            @if(isset($em['score']))
                                <div class="flex justify-between pt-1 border-t border-gray-100"><span class="text-xs text-gray-500">Score</span><span class="text-sm font-semibold {{ ($em['score'] ?? 0) >= 80 ? 'text-green-600' : 'text-amber-600' }}">{{ $em['score'] }}/100</span></div>
                            @endif
                        </div>
                    </section>
                    @endif
                </div>

                {{-- ═══ WP USERS ═══ --}}
                @if(isset($on['wp_users']) && !empty($s['wp_users']))
                @php $wpu = $s['wp_users']; @endphp
                <section id="users" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-5">
                    <div class="flex items-center gap-2 mb-4">
                        <x-icons.users class="h-4 w-4 text-purple-500" />
                        <h2 class="text-base font-bold text-gray-900">WordPress Users</h2>
                    </div>
                    <div class="max-h-72 overflow-y-auto rounded-xl">
                        <x-ui.table>
                            <x-slot:head>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Username</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Last Login</th>
                            </x-slot:head>
                            @foreach($wpu['user_list'] ?? [] as $user)
                                <tr>
                                    <td class="px-4 py-2.5 text-sm font-medium text-gray-700">{{ $user['username'] ?? $user['display_name'] ?? '' }}</td>
                                    <td class="px-4 py-2.5 text-sm text-gray-500">{{ $user['email'] ?? '' }}</td>
                                    <td class="px-4 py-2.5"><x-ui.badge variant="gray">{{ ucfirst($user['role'] ?? '') }}</x-ui.badge></td>
                                    <td class="px-4 py-2.5 text-sm text-gray-500">{{ $user['last_login_at'] ?? 'Never' }}</td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </div>
                </section>
                @endif

                {{-- ═══ SECURITY CHECKS ═══ --}}
                @if(isset($on['security_checks']) && !empty($s['security_checks']))
                @php $schk = $s['security_checks']; @endphp
                <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-5">
                    <div class="flex items-center gap-2 mb-4">
                        <x-icons.shield-alert class="h-4 w-4 text-red-500" />
                        <h2 class="text-base font-bold text-gray-900">Security Checks</h2>
                    </div>
                    <div class="max-h-80 overflow-y-auto space-y-4">
                        @foreach($schk['categories'] ?? $schk as $cat)
                            @if(is_array($cat) && isset($cat['name']))
                                <div>
                                    <p class="sub-heading">{{ $cat['name'] ?? '' }}</p>
                                    <div class="space-y-1">
                                        @foreach($cat['checks'] ?? [] as $check)
                                            <div class="flex items-center gap-2.5 py-1">
                                                <x-ui.badge :variant="($check['status'] ?? '') === 'passed' ? 'green' : 'red'">{{ ($check['status'] ?? '') === 'passed' ? 'Pass' : 'Fail' }}</x-ui.badge>
                                                <span class="text-sm text-gray-700">{{ $check['title'] ?? $check['label'] ?? '' }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </section>
                @endif

            </div>{{-- end secondary sections --}}

            {{-- ═══ RECOMMENDATIONS (standalone, prominent) ═══ --}}
            @if(isset($on['overview']) && !empty($s['recommendations']))
            @php $recs = $s['recommendations']; @endphp
            <section id="recommendations" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6">
                <div class="flex items-center gap-2 mb-5">
                    <x-icons.check-circle class="h-5 w-5 text-green-600" />
                    <h2 class="text-lg font-bold text-gray-900">Recommended Actions</h2>
                </div>
                <div class="space-y-3">
                    @foreach($recs['items'] ?? $recs as $rec)
                        @if(is_array($rec) && isset($rec['title']))
                            @php $pri = $rec['priority'] ?? 'medium'; @endphp
                            <div class="rec-card flex items-start gap-3 rounded-lg p-4 border-l-4 {{ match($pri) {
                                'high' => 'border-l-red-500 bg-red-50/40',
                                'medium' => 'border-l-yellow-500 bg-yellow-50/40',
                                default => 'border-l-blue-500 bg-blue-50/40',
                            } }}">
                                <x-ui.badge :variant="match($pri) { 'high' => 'red', 'medium' => 'yellow', default => 'blue' }" class="mt-0.5 flex-shrink-0">
                                    {{ ucfirst($pri) }}
                                </x-ui.badge>
                                <div>
                                    <p class="text-sm font-semibold text-gray-900">{{ $rec['title'] }}</p>
                                    @if($rec['description'] ?? null)
                                        <p class="text-xs text-gray-600 mt-1 leading-relaxed">{{ $rec['description'] }}</p>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </section>
            @endif

        </div>
    </div>

    {{-- ── Footer ──────────────────────────────────────────────────────────── --}}
    <footer class="border-t border-gray-200 mt-8 no-print">
        <div class="mx-auto max-w-6xl px-4 py-6 flex items-center justify-between">
            <p class="text-xs text-gray-400">Generated by <span class="font-medium text-gray-500">{{ config('app.name') }}</span></p>
            <p class="text-xs text-gray-400">{{ $report->generated_at?->format('M j, Y \a\t H:i') }}</p>
        </div>
    </footer>

    {{-- ── Scroll-spy ──────────────────────────────────────────────────────── --}}
    <script>
        function reportNav() {
            return {
                active: '{{ array_key_first($nav) }}',
                init() {
                    const sections = document.querySelectorAll('section[id]');
                    if (!sections.length) return;
                    const observer = new IntersectionObserver((entries) => {
                        entries.forEach(entry => {
                            if (entry.isIntersecting) {
                                this.active = entry.target.id;
                            }
                        });
                    }, { rootMargin: '-100px 0px -60% 0px', threshold: 0 });
                    sections.forEach(s => observer.observe(s));
                }
            };
        }
    </script>
</body>
</html>
