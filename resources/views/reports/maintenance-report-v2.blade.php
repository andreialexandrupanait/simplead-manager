<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('report.title', [], $lang) }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1f2937; line-height: 1.5; }
        .page { padding: 40px; }
        .header { text-align: center; margin-bottom: 40px; padding-bottom: 20px; border-bottom: 2px solid {{ $brandColor ?? '#7c3aed' }}; }
        .header img { max-height: 60px; margin-bottom: 12px; }
        .header h1 { font-size: 22px; color: {{ $brandColor ?? '#7c3aed' }}; margin-bottom: 4px; }
        .header .subtitle { font-size: 14px; color: #6b7280; }
        .meta { margin-bottom: 30px; padding: 16px; background: #f9fafb; border-radius: 6px; }
        .meta-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
        .meta-label { font-weight: bold; color: #374151; }
        .meta-value { color: #6b7280; }
        .section { margin-bottom: 24px; page-break-inside: avoid; }
        .section-title { font-size: 16px; font-weight: bold; color: {{ $brandColor ?? '#7c3aed' }}; margin-bottom: 12px; padding-bottom: 6px; border-bottom: 1px solid #e5e7eb; }
        .metric-grid { display: table; width: 100%; }
        .metric-row { display: table-row; }
        .metric-label { display: table-cell; padding: 8px 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #f3f4f6; width: 50%; }
        .metric-value { display: table-cell; padding: 8px 12px; color: #1f2937; border-bottom: 1px solid #f3f4f6; text-align: right; }
        .not-configured { color: #9ca3af; font-style: italic; }
        .notes { padding: 16px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; }
        .notes h3 { font-size: 13px; font-weight: bold; margin-bottom: 8px; color: #92400e; }
        .footer { margin-top: 40px; padding-top: 16px; border-top: 1px solid #e5e7eb; text-align: center; font-size: 10px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="page">
        {{-- Header / Cover --}}
        <div class="header">
            @if($logo)
                <img src="{{ $logo }}" alt="Logo">
            @endif
            <h1>{{ __('report.title', [], $lang) }}</h1>
            <div class="subtitle">{{ $siteName }} &mdash; {{ $periodLabel }}</div>
        </div>

        {{-- Meta --}}
        <div class="meta">
            <table style="width: 100%;">
                <tr>
                    <td><strong>{{ __('report.prepared_for', [], $lang) }}:</strong></td>
                    <td style="text-align: right;">{{ $clientName ?? $siteName }}</td>
                </tr>
                <tr>
                    <td><strong>{{ __('report.period', [], $lang) }}:</strong></td>
                    <td style="text-align: right;">{{ $periodLabel }}</td>
                </tr>
                <tr>
                    <td><strong>{{ __('report.generated_at', [], $lang) }}:</strong></td>
                    <td style="text-align: right;">{{ now()->format('d.m.Y H:i') }}</td>
                </tr>
            </table>
        </div>

        {{-- Uptime --}}
        <div class="section">
            <div class="section-title">{{ __('report.uptime', [], $lang) }}</div>
            @if($snapshot->uptime_percentage !== null)
                <div class="metric-grid">
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.uptime_percentage', [], $lang) }}</div>
                        <div class="metric-value">{{ number_format($snapshot->uptime_percentage, 2) }}%</div>
                    </div>
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.avg_response_time', [], $lang) }}</div>
                        <div class="metric-value">{{ number_format($snapshot->uptime_avg_response_ms, 0) }} {{ __('report.ms', [], $lang) }}</div>
                    </div>
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.down_checks', [], $lang) }}</div>
                        <div class="metric-value">{{ $snapshot->uptime_down_checks ?? 0 }}</div>
                    </div>
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.incidents_count', [], $lang) }}</div>
                        <div class="metric-value">{{ $snapshot->uptime_incidents_count ?? 0 }}</div>
                    </div>
                </div>
            @else
                <p class="not-configured">{{ __('report.not_configured', [], $lang) }}</p>
            @endif
        </div>

        {{-- Backups --}}
        <div class="section">
            <div class="section-title">{{ __('report.backups', [], $lang) }}</div>
            @if($snapshot->backups_total !== null)
                <div class="metric-grid">
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.backups_total', [], $lang) }}</div>
                        <div class="metric-value">{{ $snapshot->backups_total }}</div>
                    </div>
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.backups_successful', [], $lang) }}</div>
                        <div class="metric-value">{{ $snapshot->backups_successful }}</div>
                    </div>
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.backups_failed', [], $lang) }}</div>
                        <div class="metric-value">{{ $snapshot->backups_failed }}</div>
                    </div>
                </div>
            @else
                <p class="not-configured">{{ __('report.not_configured', [], $lang) }}</p>
            @endif
        </div>

        {{-- Updates --}}
        <div class="section">
            <div class="section-title">{{ __('report.updates', [], $lang) }}</div>
            @if($snapshot->updates_applied !== null)
                <div class="metric-grid">
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.updates_applied', [], $lang) }}</div>
                        <div class="metric-value">{{ $snapshot->updates_applied }}</div>
                    </div>
                </div>
            @else
                <p class="not-configured">{{ __('report.not_configured', [], $lang) }}</p>
            @endif
        </div>

        {{-- Performance --}}
        <div class="section">
            <div class="section-title">{{ __('report.performance', [], $lang) }}</div>
            @if($snapshot->performance_avg_desktop !== null || $snapshot->performance_avg_mobile !== null)
                <div class="metric-grid">
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.desktop_score', [], $lang) }}</div>
                        <div class="metric-value">{{ $snapshot->performance_avg_desktop !== null ? number_format($snapshot->performance_avg_desktop, 0) : '—' }}</div>
                    </div>
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.mobile_score', [], $lang) }}</div>
                        <div class="metric-value">{{ $snapshot->performance_avg_mobile !== null ? number_format($snapshot->performance_avg_mobile, 0) : '—' }}</div>
                    </div>
                </div>
            @else
                <p class="not-configured">{{ __('report.not_configured', [], $lang) }}</p>
            @endif
        </div>

        {{-- Analytics --}}
        <div class="section">
            <div class="section-title">{{ __('report.analytics', [], $lang) }}</div>
            @if($snapshot->analytics_users !== null)
                <div class="metric-grid">
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.users', [], $lang) }}</div>
                        <div class="metric-value">{{ number_format($snapshot->analytics_users) }}</div>
                    </div>
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.sessions', [], $lang) }}</div>
                        <div class="metric-value">{{ number_format($snapshot->analytics_sessions) }}</div>
                    </div>
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.pageviews', [], $lang) }}</div>
                        <div class="metric-value">{{ number_format($snapshot->analytics_pageviews) }}</div>
                    </div>
                </div>
            @else
                <p class="not-configured">{{ __('report.not_configured', [], $lang) }}</p>
            @endif
        </div>

        {{-- Search Console --}}
        <div class="section">
            <div class="section-title">{{ __('report.search_console', [], $lang) }}</div>
            @if($snapshot->search_console_clicks !== null)
                <div class="metric-grid">
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.clicks', [], $lang) }}</div>
                        <div class="metric-value">{{ number_format($snapshot->search_console_clicks) }}</div>
                    </div>
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.impressions', [], $lang) }}</div>
                        <div class="metric-value">{{ number_format($snapshot->search_console_impressions) }}</div>
                    </div>
                    <div class="metric-row">
                        <div class="metric-label">{{ __('report.avg_position', [], $lang) }}</div>
                        <div class="metric-value">{{ number_format($snapshot->search_console_avg_position, 1) }}</div>
                    </div>
                </div>
            @else
                <p class="not-configured">{{ __('report.not_configured', [], $lang) }}</p>
            @endif
        </div>

        {{-- Security (optional) --}}
        @if($showSecurity)
            <div class="section">
                <div class="section-title">{{ __('report.security', [], $lang) }}</div>
                @if($snapshot->security_avg_score !== null)
                    <div class="metric-grid">
                        <div class="metric-row">
                            <div class="metric-label">{{ __('report.security_score', [], $lang) }}</div>
                            <div class="metric-value">{{ number_format($snapshot->security_avg_score, 0) }}/100</div>
                        </div>
                    </div>
                @else
                    <p class="not-configured">{{ __('report.not_configured', [], $lang) }}</p>
                @endif
            </div>
        @endif

        {{-- Cloudflare (optional) --}}
        @if($showCloudflare)
            <div class="section">
                <div class="section-title">{{ __('report.cloudflare', [], $lang) }}</div>
                @if($snapshot->cloudflare_requests !== null)
                    <div class="metric-grid">
                        <div class="metric-row">
                            <div class="metric-label">{{ __('report.total_requests', [], $lang) }}</div>
                            <div class="metric-value">{{ number_format($snapshot->cloudflare_requests) }}</div>
                        </div>
                        <div class="metric-row">
                            <div class="metric-label">{{ __('report.bandwidth', [], $lang) }}</div>
                            <div class="metric-value">{{ $snapshot->cloudflare_bandwidth_bytes ? number_format($snapshot->cloudflare_bandwidth_bytes / 1048576, 1) . ' MB' : '—' }}</div>
                        </div>
                        <div class="metric-row">
                            <div class="metric-label">{{ __('report.cache_hit_ratio', [], $lang) }}</div>
                            <div class="metric-value">{{ $snapshot->cloudflare_cache_hit_ratio !== null ? number_format($snapshot->cloudflare_cache_hit_ratio, 1) . '%' : '—' }}</div>
                        </div>
                    </div>
                @else
                    <p class="not-configured">{{ __('report.not_configured', [], $lang) }}</p>
                @endif
            </div>
        @endif

        {{-- Incidents --}}
        @if($incidents->isNotEmpty())
            <div class="section">
                <div class="section-title">{{ __('report.incidents', [], $lang) }}</div>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f9fafb;">
                            <th style="padding: 8px; text-align: left; border-bottom: 1px solid #e5e7eb; font-size: 11px;">Date</th>
                            <th style="padding: 8px; text-align: left; border-bottom: 1px solid #e5e7eb; font-size: 11px;">Duration</th>
                            <th style="padding: 8px; text-align: left; border-bottom: 1px solid #e5e7eb; font-size: 11px;">Cause</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($incidents as $incident)
                            <tr>
                                <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6; font-size: 11px;">{{ $incident->started_at->format('d.m.Y H:i') }}</td>
                                <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6; font-size: 11px;">
                                    @if($incident->resolved_at)
                                        {{ $incident->started_at->diffForHumans($incident->resolved_at, true) }}
                                    @else
                                        Ongoing
                                    @endif
                                </td>
                                <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6; font-size: 11px;">{{ $incident->cause ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Custom Notes --}}
        @if($customNotes)
            <div class="notes">
                <h3>{{ __('report.custom_notes', [], $lang) }}</h3>
                <p>{{ $customNotes }}</p>
            </div>
        @endif

        {{-- Footer --}}
        <div class="footer">
            Generated by SimpleAd Manager &mdash; {{ now()->format('d.m.Y H:i') }}
        </div>
    </div>
</body>
</html>
