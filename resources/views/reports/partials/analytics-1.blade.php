@php
    $a = $data['analytics'];
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['analytics']['title'] ?? __('report.section_analytics', [], $lang),
    'number' => $sectionNumber ?? null,
])

<p class="section-description">{{ $sectionOverrides['analytics']['description'] ?? __('report.analytics_description', [], $lang) }}</p>

{{-- Daily users SVG chart --}}
@if(($sectionOptions['analytics']['show_daily_chart'] ?? true) && !empty($a['chart_points']['line_points'] ?? ''))
    <div class="chart-container">
        <div class="chart-title">{{ __('report.analytics_daily_users', [], $lang) }}</div>
        @include('reports.components.chart-line', [
            'points' => $a['chart_points'],
            'primaryColor' => $primaryColor ?? '#7C3AED',
            'areaColor' => '#EDE9FE',
            'yLabels' => $a['chart_y_labels'] ?? [],
            'xLabels' => $a['chart_x_labels'] ?? [],
            'legendLabel' => __('report.analytics_users', [], $lang),
        ])
    </div>
@endif

{{-- KPI cards row 1 (4 columns, flex row) --}}
@php
    $pv = $a['total_pageviews'] ?? 0;
    $users = $a['total_users'] ?? 0;
    $br = $a['bounce_rate'] ?? 0;
    $dur = $a['avg_session_duration'] ?? 0;
@endphp
<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-value {{ $pv == 0 ? 'value-muted' : '' }}">{{ $pv == 0 ? '—' : number_format($pv) }}</div>
        <div class="kpi-label">{{ __('report.analytics_pageviews', [], $lang) }}</div>
        <div class="card-trend">@include('reports.components.trend', ['trend' => $a['pageviews_trend'] ?? null])</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value {{ $users == 0 ? 'value-muted' : '' }}">{{ $users == 0 ? '—' : number_format($users) }}</div>
        <div class="kpi-label">{{ __('report.analytics_users', [], $lang) }}</div>
        <div class="card-trend">@include('reports.components.trend', ['trend' => $a['users_trend'] ?? null])</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value {{ $br == 0 ? 'value-muted' : '' }}">{{ $br == 0 ? '—' : number_format($br, 1, $lang === 'ro' ? ',' : '.', '') . '%' }}</div>
        <div class="kpi-label">{{ __('report.analytics_bounce_rate', [], $lang) }}</div>
        <div class="card-trend">@include('reports.components.trend', ['trend' => $a['bounce_rate_trend'] ?? null])</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value {{ $dur == 0 ? 'value-muted' : '' }}">{{ $dur == 0 ? '—' : gmdate('i:s', (int) $dur) }}</div>
        <div class="kpi-label">{{ __('report.analytics_session_duration', [], $lang) }}</div>
        <div class="card-trend">@include('reports.components.trend', ['trend' => $a['duration_trend'] ?? null])</div>
    </div>
</div>

{{-- KPI cards row 2: Sessions, New Users, Returning Users, Engagement Rate --}}
@php
    $sessions = $a['sessions'] ?? 0;
    $newUsers = $a['new_users'] ?? 0;
    $returning = $a['returning_users'] ?? 0;
    $engagement = $a['engagement_rate'] ?? 0;
@endphp
@if($sessions > 0 || $newUsers > 0 || $returning > 0 || $engagement > 0)
<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-value {{ $sessions == 0 ? 'value-muted' : '' }}">{{ $sessions == 0 ? '—' : number_format($sessions) }}</div>
        <div class="kpi-label">{{ __('report.analytics_sessions', [], $lang) }}</div>
        <div class="card-trend">&nbsp;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value {{ $newUsers == 0 ? 'value-muted' : '' }}">{{ $newUsers == 0 ? '—' : number_format($newUsers) }}</div>
        <div class="kpi-label">{{ __('report.analytics_new_users', [], $lang) }}</div>
        <div class="card-trend">&nbsp;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value {{ $returning == 0 ? 'value-muted' : '' }}">{{ $returning == 0 ? '—' : number_format($returning) }}</div>
        <div class="kpi-label">{{ __('report.analytics_returning_users', [], $lang) }}</div>
        <div class="card-trend">&nbsp;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value {{ $engagement == 0 ? 'value-muted' : '' }}">{{ $engagement == 0 ? '—' : number_format($engagement, 1, $lang === 'ro' ? ',' : '.', '') . '%' }}</div>
        <div class="kpi-label">{{ __('report.analytics_engagement_rate', [], $lang) }}</div>
        <div class="card-trend">&nbsp;</div>
    </div>
</div>
@endif

{{-- Traffic by channel bar chart --}}
@if(($sectionOptions['analytics']['show_traffic_sources'] ?? true) && !empty($a['traffic_bar_chart']['bars'] ?? []))
    <hr class="subsection-divider">
    <div class="chart-container">
        <div class="chart-title">{{ __('report.analytics_traffic_sources', [], $lang) }}</div>
        @include('reports.components.chart-bar', [
            'chartData' => $a['traffic_bar_chart'],
            'primaryColor' => $primaryColor ?? '#7C3AED',
        ])
    </div>
@endif

{{-- Top pages table --}}
@if(($sectionOptions['analytics']['show_top_pages'] ?? true) && isset($a['top_pages']) && count($a['top_pages']) > 0)
    <hr class="subsection-divider">
    <h3>{{ __('report.analytics_top_pages', [], $lang) }}</h3>
    <table class="data-table mb-4">
        <thead>
            <tr>
                <th>{{ __('report.analytics_page_col', [], $lang) }}</th>
                <th>{{ __('report.analytics_views', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($a['top_pages'] as $page)
                @php
                    $pagePath = $page['page'] ?? $page['path'] ?? '—';
                    $pagePath = preg_replace('#^https?://[^/]+#', '', $pagePath);
                    $pagePath = \Illuminate\Support\Str::limit($pagePath, 50);
                @endphp
                <tr>
                    <td class="cell-break">{{ $pagePath }}</td>
                    <td>{{ number_format($page['pageviews'] ?? $page['views'] ?? 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- Devices + Countries side-by-side --}}
@php
    $hasDevices = ($sectionOptions['analytics']['show_devices'] ?? true) && isset($a['devices']) && is_array($a['devices']) && count($a['devices']) > 0;
    $hasCountries = ($sectionOptions['analytics']['show_countries'] ?? true) && isset($a['countries']) && is_array($a['countries']) && count($a['countries']) > 0;
@endphp

@if($hasDevices || $hasCountries)
    <hr class="subsection-divider">
    @if($hasDevices && $hasCountries)
        <div class="two-col">
    @endif

    @if($hasDevices)
        <div>
            <h3 style="margin-top: 0;">{{ __('report.analytics_device_distribution', [], $lang) }}</h3>
            @php
                $totalDeviceUsers = max(1, array_sum(array_column($a['devices'], 'users')));
                $deviceColors = ['primary', 'blue', 'green', 'amber'];
            @endphp
            <div style="display: flex; flex-direction: column; gap: 6px;">
                @foreach($a['devices'] as $idx => $device)
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <span style="font-size: 8.5pt; width: 80px;">{{ ucfirst($device['device'] ?? '—') }}</span>
                        <div style="flex: 1;">
                            <div class="progress-bar" style="height: 10px;">
                                <div class="progress-fill {{ $deviceColors[$idx] ?? 'primary' }}" style="width: {{ min(100, (($device['users'] ?? 0) / $totalDeviceUsers) * 100) }}%; height: 10px;"></div>
                            </div>
                        </div>
                        <span style="font-size: 8.5pt; font-weight: 600; width: 50px; text-align: right; font-feature-settings: 'tnum';">
                            {{ number_format((($device['users'] ?? 0) / $totalDeviceUsers) * 100, 1) }}%
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if($hasCountries)
        <div>
            <h3 style="margin-top: 0;">{{ __('report.analytics_top_countries', [], $lang) }}</h3>
            <table class="data-table" style="margin-bottom: 0;">
                <thead>
                    <tr>
                        <th>{{ __('report.analytics_country', [], $lang) }}</th>
                        <th>{{ __('report.analytics_user_count', [], $lang) }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($a['countries'] as $country)
                        <tr>
                            <td>{{ $country['country'] ?? '—' }}</td>
                            <td>{{ number_format($country['users'] ?? $country['sessions'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($hasDevices && $hasCountries)
        </div>
    @endif
@endif
