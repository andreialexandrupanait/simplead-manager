@php
    $ut = $data['uptime'] ?? [];
    $sec = $data['security'] ?? null;
    $db = $data['database'] ?? null;
    $ssl = $data['ssl'] ?? null;
    $domain = $data['domain'] ?? null;
    $email = $data['email'] ?? null;
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => __('report.section_technical_stability', [], $lang),
])

<p class="section-description">{{ __('report.technical_stability_description', [], $lang) }}</p>

@if(!($ut['available'] ?? false))
    <p style="color: #94a3b8; font-size: 8.5pt;">{{ __('report.uptime_no_monitoring', [], $lang) }}</p>
@else
    {{-- Response time line chart --}}
    @if(!empty($ut['chart_points']['line_points'] ?? ''))
        <div class="chart-container">
            <div class="chart-title">{{ __('report.tech_response_time', [], $lang) }}</div>
            <div class="text-xs text-muted mb-2">{{ __('report.uptime_response_description', [], $lang) }}</div>
            @include('reports.components.chart-line', [
                'points' => $ut['chart_points'],
                'primaryColor' => '#2563eb',
                'areaColor' => '#dbeafe',
                'yLabels' => $ut['chart_y_labels'] ?? [],
                'xLabels' => $ut['chart_x_labels'] ?? [],
                'legendLabel' => __('report.uptime_response_chart', [], $lang),
            ])
        </div>
    @endif

    {{-- 4 KPI cards --}}
    <table class="kpi-grid mb-4">
        <tr>
            <td class="kpi-card" style="width: 25%;">
                @php
                    $uptimePct = $ut['uptime_percentage'] ?? null;
                    $uptimeClass = $uptimePct === null ? '' : ($uptimePct >= 99 ? 'good' : ($uptimePct >= 95 ? 'warning' : 'bad'));
                @endphp
                <div class="kpi-value uptime-percentage {{ $uptimeClass }}" style="font-size: 16pt;">
                    {{ $uptimePct !== null ? number_format($uptimePct, 2, $lang === 'ro' ? ',' : '.', '') . '%' : '—' }}
                </div>
                <div class="kpi-label">{{ __('report.uptime_average', [], $lang) }}</div>
                <div class="card-trend">@include('reports.components.trend', ['trend' => $ut['uptime_trend'] ?? null])</div>
            </td>
            <td class="kpi-card" style="width: 25%;">
                @php $incCount = $ut['incidents_count'] ?? 0; @endphp
                <div class="kpi-value {{ $incCount == 0 ? 'value-muted' : '' }}" style="font-size: 16pt;">{{ $incCount == 0 ? '—' : $incCount }}</div>
                <div class="kpi-label">{{ __('report.uptime_incidents', [], $lang) }}</div>
                <div class="card-trend">@include('reports.components.trend', ['trend' => $ut['incidents_trend'] ?? null])</div>
            </td>
            <td class="kpi-card" style="width: 25%;">
                @php $avgResp = $ut['avg_response_time'] ?? null; @endphp
                <div class="kpi-value {{ $avgResp === null ? 'value-muted' : '' }}" style="font-size: 16pt;">{{ $avgResp ? $avgResp . 'ms' : '—' }}</div>
                <div class="kpi-label">{{ __('report.uptime_response_time', [], $lang) }}</div>
                <div class="card-trend">@include('reports.components.trend', ['trend' => $ut['response_time_trend'] ?? null])</div>
            </td>
            <td class="kpi-card" style="width: 25%;">
                <div class="kpi-value" style="font-size: 16pt;">{{ $ut['formatted_downtime'] ?? '0 min' }}</div>
                <div class="kpi-label">{{ __('report.uptime_downtime', [], $lang) }}</div>
            </td>
        </tr>
    </table>

    {{-- Incidents table (capped at 5) --}}
    @if(count($ut['incidents'] ?? []) > 0)
        @php
            $incidents = $ut['incidents'];
            $totalIncidents = count($incidents);
            $displayIncidents = array_slice($incidents, 0, 5);
        @endphp
        <h3>{{ __('report.uptime_activity_changes', [], $lang) }}</h3>
        <table class="data-table mb-4">
            <thead>
                <tr>
                    <th>{{ __('report.uptime_status', [], $lang) }}</th>
                    <th>{{ __('report.uptime_from', [], $lang) }}</th>
                    <th>{{ __('report.uptime_to', [], $lang) }}</th>
                    <th>{{ __('report.uptime_duration', [], $lang) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($displayIncidents as $incident)
                    <tr>
                        <td><span class="status-down">&#8600; {{ __('report.uptime_status_down', [], $lang) }}</span></td>
                        <td>{{ \Carbon\Carbon::parse($incident['started_at'])->format('d/m/Y H:i') }}</td>
                        <td>{{ $incident['resolved_at'] ? \Carbon\Carbon::parse($incident['resolved_at'])->format('d/m/Y H:i') : '—' }}</td>
                        <td>{{ $incident['duration'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if($totalIncidents > 5)
            <div class="table-footnote">{{ __('report.showing_of', ['shown' => 5, 'total' => $totalIncidents], $lang) }}</div>
        @endif
    @else
        <div style="padding: 8px 0; color: #10b981; font-weight: 600; font-size: 8.5pt;">
            <span class="status-up">&#8599; {{ __('report.uptime_status_up', [], $lang) }}</span>
            &mdash; {{ __('report.uptime_no_incidents', [], $lang) }}
        </div>
    @endif
@endif

{{-- Security sub-card --}}
@if($sec)
    <div class="subcard mt-4">
        <div class="subcard-title">{{ __('report.tech_security_subcard', [], $lang) }}</div>
        @if(($sec['total_issues'] ?? 0) === 0)
            <p style="color: #10b981; font-size: 8.5pt; font-weight: 600;">{{ __('report.security_no_issues', [], $lang) }}</p>
        @else
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="width: 100px; text-align: center; vertical-align: top; padding: 4px;">
                        @include('reports.components.score-circle', ['score' => $sec['score'] ?? null, 'size' => 70])
                        <div class="kpi-label" style="margin-top: 4px;">{{ __('report.security_score', [], $lang) }}</div>
                    </td>
                    <td style="vertical-align: top; padding: 4px 10px;">
                        <table class="security-summary-table">
                            @if(($sec['critical_count'] ?? 0) > 0)
                                <tr><td class="severity-critical" style="width: 30px;">{{ $sec['critical_count'] }}</td><td>{{ __('report.security_critical', [], $lang) }}</td></tr>
                            @endif
                            @if(($sec['high_count'] ?? 0) > 0)
                                <tr><td class="severity-high" style="width: 30px;">{{ $sec['high_count'] }}</td><td>{{ __('report.security_high', [], $lang) }}</td></tr>
                            @endif
                            @if(($sec['medium_count'] ?? 0) > 0)
                                <tr><td class="severity-medium" style="width: 30px;">{{ $sec['medium_count'] }}</td><td>{{ __('report.security_medium', [], $lang) }}</td></tr>
                            @endif
                            @if(($sec['low_count'] ?? 0) > 0)
                                <tr><td class="severity-low" style="width: 30px;">{{ $sec['low_count'] }}</td><td>{{ __('report.security_low', [], $lang) }}</td></tr>
                            @endif
                        </table>
                    </td>
                </tr>
            </table>
        @endif
    </div>
@endif

{{-- Database sub-card --}}
@if($db)
    <div class="subcard mt-4">
        <div class="subcard-title">{{ __('report.tech_database_subcard', [], $lang) }}</div>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 4px 10px; width: 50%;">
                    <div class="kpi-label">{{ __('report.database_saved', [], $lang) }}</div>
                    <div style="font-size: 14pt; font-weight: 700; color: #0f172a;">{{ \App\Helpers\FormatHelper::bytes($db['total_saved'] ?? 0) }}</div>
                </td>
                <td style="padding: 4px 10px; width: 50%;">
                    <div class="kpi-label">{{ __('report.database_last_cleanup', [], $lang) }}</div>
                    <div style="font-size: 10pt; font-weight: 600; color: #0f172a;">
                        {{ $db['last_cleanup_date'] ? \Carbon\Carbon::parse($db['last_cleanup_date'])->format('d/m/Y') : '—' }}
                    </div>
                </td>
            </tr>
        </table>
    </div>
@endif

