@php
    $ut = $data['uptime'];
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => __('report.section_uptime', [], $lang),
])

<p class="section-description">{{ __('report.uptime_description', [], $lang) }}</p>

@if(!($ut['available'] ?? false))
    <div style="text-align: center; padding: 30px; color: #6b7280;">
        {{ __('report.uptime_no_monitoring', [], $lang) }}
    </div>
@else
    {{-- Big uptime percentage --}}
    <div class="highlight-box mb-4">
        <div class="text-xs text-muted mb-2">{{ __('report.uptime_average', [], $lang) }}</div>
        @php
            $uptimePct = $ut['uptime_percentage'] ?? null;
            $uptimeClass = $uptimePct === null ? '' : ($uptimePct >= 99 ? 'good' : ($uptimePct >= 95 ? 'warning' : 'bad'));
        @endphp
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="vertical-align: middle;">
                    <div class="uptime-percentage {{ $uptimeClass }}">
                        {{ $uptimePct !== null ? number_format($uptimePct, 2, $lang === 'ro' ? ',' : '.', '') . '%' : __('report.not_available', [], $lang) }}
                    </div>
                </td>
                <td style="vertical-align: middle; padding-left: 14px;">
                    @include('reports.components.trend', ['trend' => $ut['uptime_trend'] ?? null, 'showLabel' => true])
                </td>
            </tr>
        </table>

        {{-- SVG Response time chart --}}
        @if(!empty($ut['chart_points']['line_points'] ?? ''))
            <div style="margin-top: 10px;">
                <div class="text-xs text-muted mb-2">{{ __('report.uptime_response_chart', [], $lang) }}</div>
                <div class="text-xs text-muted mb-2">{{ __('report.uptime_response_description', [], $lang) }}</div>
                @include('reports.components.chart-line', [
                    'points' => $ut['chart_points'],
                    'primaryColor' => '#2563eb',
                    'areaColor' => '#dbeafe',
                    'yLabels' => $ut['chart_y_labels'] ?? [],
                ])
            </div>
        @endif

        {{-- Uptime bar --}}
        @if($uptimePct !== null)
            <div class="uptime-bar" style="margin-top: 10px;">
                <div class="uptime-segment up" style="width: {{ min(100, $uptimePct) }}%;"></div>
                @if($uptimePct < 100)
                    <div class="uptime-segment down" style="width: {{ 100 - $uptimePct }}%;"></div>
                @endif
            </div>
        @endif
    </div>

    {{-- Summary cards --}}
    <table class="kpi-grid mb-4">
        <tr>
            <td class="kpi-card" style="width: 33%;">
                <div class="kpi-value" style="font-size: 18pt;">{{ $ut['avg_response_time'] ? $ut['avg_response_time'] . 'ms' : '—' }}</div>
                <div class="kpi-label">{{ __('report.uptime_response_time', [], $lang) }}</div>
                <div class="card-trend">@include('reports.components.trend', ['trend' => $ut['response_time_trend'] ?? null])</div>
            </td>
            <td class="kpi-card" style="width: 33%;">
                <div class="kpi-value" style="font-size: 18pt;">{{ $ut['total_downtime_minutes'] ?? 0 }}m</div>
                <div class="kpi-label">{{ __('report.uptime_downtime', [], $lang) }}</div>
            </td>
            <td class="kpi-card" style="width: 33%;">
                <div class="kpi-value" style="font-size: 18pt;">{{ $ut['incidents_count'] ?? 0 }}</div>
                <div class="kpi-label">{{ __('report.uptime_incidents', [], $lang) }}</div>
                <div class="card-trend">@include('reports.components.trend', ['trend' => $ut['incidents_trend'] ?? null])</div>
            </td>
        </tr>
    </table>

    {{-- Activity table --}}
    <h3>{{ __('report.uptime_activity_changes', [], $lang) }}</h3>
    @if(count($ut['incidents'] ?? []) > 0)
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('report.uptime_status', [], $lang) }}</th>
                    <th>{{ __('report.uptime_from', [], $lang) }}</th>
                    <th>{{ __('report.uptime_to', [], $lang) }}</th>
                    <th>{{ __('report.uptime_duration', [], $lang) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($ut['incidents'] as $incident)
                    <tr>
                        <td><span class="status-down">&#8600; {{ __('report.uptime_status_down', [], $lang) }}</span></td>
                        <td>{{ \Carbon\Carbon::parse($incident['started_at'])->format('d/m/Y H:i') }}</td>
                        <td>{{ $incident['resolved_at'] ? \Carbon\Carbon::parse($incident['resolved_at'])->format('d/m/Y H:i') : '—' }}</td>
                        <td>{{ $incident['duration'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div style="text-align: center; padding: 16px; color: #10b981; font-weight: 600;">
            <span class="status-up">&#8599; {{ __('report.uptime_status_up', [], $lang) }}</span>
            &mdash; {{ __('report.uptime_no_incidents', [], $lang) }}
        </div>
    @endif
@endif
