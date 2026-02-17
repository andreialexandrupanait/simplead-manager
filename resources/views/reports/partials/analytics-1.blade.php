@php
    $a = $data['analytics'];
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => __('report.section_analytics', [], $lang),
])

<p class="section-description">{{ __('report.analytics_description', [], $lang) }}</p>

{{-- Daily users SVG chart --}}
@if(!empty($a['chart_points']['line_points'] ?? ''))
    <div class="chart-container">
        <div class="chart-title">{{ __('report.analytics_daily_users', [], $lang) }}</div>
        @include('reports.components.chart-line', [
            'points' => $a['chart_points'],
            'primaryColor' => '#2563eb',
            'areaColor' => '#dbeafe',
            'yLabels' => $a['chart_y_labels'] ?? [],
            'xLabels' => $a['chart_x_labels'] ?? [],
            'legendLabel' => __('report.analytics_users', [], $lang),
        ])
    </div>
@endif

{{-- KPI cards (4 columns) --}}
@php
    $pv = $a['total_pageviews'] ?? 0;
    $users = $a['total_users'] ?? 0;
    $br = $a['bounce_rate'] ?? 0;
    $dur = $a['avg_session_duration'] ?? 0;
@endphp
<table style="width: 100%; border-collapse: collapse; margin-bottom: 12px;">
    <tr>
        <td style="width: 25%; padding: 0 5px 0 0; vertical-align: top;">
            <div class="kpi-card">
                <div class="kpi-value {{ $pv == 0 ? 'value-muted' : '' }}">{{ $pv == 0 ? '—' : number_format($pv) }}</div>
                <div class="kpi-label">{{ __('report.analytics_pageviews', [], $lang) }}</div>
                <div class="card-trend">@include('reports.components.trend', ['trend' => $a['pageviews_trend'] ?? null])</div>
            </div>
        </td>
        <td style="width: 25%; padding: 0 5px; vertical-align: top;">
            <div class="kpi-card">
                <div class="kpi-value {{ $users == 0 ? 'value-muted' : '' }}">{{ $users == 0 ? '—' : number_format($users) }}</div>
                <div class="kpi-label">{{ __('report.analytics_users', [], $lang) }}</div>
                <div class="card-trend">@include('reports.components.trend', ['trend' => $a['users_trend'] ?? null])</div>
            </div>
        </td>
        <td style="width: 25%; padding: 0 5px; vertical-align: top;">
            <div class="kpi-card">
                <div class="kpi-value {{ $br == 0 ? 'value-muted' : '' }}">{{ $br == 0 ? '—' : number_format($br, 1, $lang === 'ro' ? ',' : '.', '') . '%' }}</div>
                <div class="kpi-label">{{ __('report.analytics_bounce_rate', [], $lang) }}</div>
                <div class="card-trend">@include('reports.components.trend', ['trend' => $a['bounce_rate_trend'] ?? null])</div>
            </div>
        </td>
        <td style="width: 25%; padding: 0 0 0 5px; vertical-align: top;">
            <div class="kpi-card">
                <div class="kpi-value {{ $dur == 0 ? 'value-muted' : '' }}">{{ $dur == 0 ? '—' : gmdate('i:s', (int) $dur) }}</div>
                <div class="kpi-label">{{ __('report.analytics_session_duration', [], $lang) }}</div>
                <div class="card-trend">@include('reports.components.trend', ['trend' => $a['duration_trend'] ?? null])</div>
            </div>
        </td>
    </tr>
</table>

{{-- Traffic by channel bar chart --}}
@if(!empty($a['traffic_bar_chart']['bars'] ?? []))
    <div class="chart-container">
        <div class="chart-title">{{ __('report.analytics_traffic_sources', [], $lang) }}</div>
        @include('reports.components.chart-bar', [
            'chartData' => $a['traffic_bar_chart'],
            'primaryColor' => '#2563eb',
        ])
    </div>
@endif

{{-- Top pages table (capped at 10, no 0-view pages) --}}
@if(isset($a['top_pages']) && count($a['top_pages']) > 0)
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
                    <td style="word-break: break-all;">{{ $pagePath }}</td>
                    <td>{{ number_format($page['pageviews'] ?? $page['views'] ?? 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- Device distribution --}}
<h3>{{ __('report.analytics_device_distribution', [], $lang) }}</h3>
@if(isset($a['devices']) && is_array($a['devices']) && count($a['devices']) > 0)
    @php
        $totalDeviceUsers = max(1, array_sum(array_column($a['devices'], 'users')));
        $deviceColors = ['primary', 'blue', 'green', 'amber'];
    @endphp
    <table style="width: 100%; border-collapse: collapse;">
        @foreach($a['devices'] as $idx => $device)
            <tr>
                <td style="font-size: 8.5pt; padding: 3px 0; width: 30%;">{{ ucfirst($device['device'] ?? '—') }}</td>
                <td style="padding: 3px 0; width: 50%;">
                    <div class="progress-bar">
                        <div class="progress-fill {{ $deviceColors[$idx] ?? 'primary' }}" style="width: {{ min(100, (($device['users'] ?? 0) / $totalDeviceUsers) * 100) }}%;"></div>
                    </div>
                </td>
                <td style="font-size: 8.5pt; font-weight: 600; text-align: right; padding: 3px 0; width: 20%;">
                    {{ number_format((($device['users'] ?? 0) / $totalDeviceUsers) * 100, 1) }}%
                </td>
            </tr>
        @endforeach
    </table>
@else
    <p style="color: #94a3b8; font-size: 8.5pt;">{{ __('report.no_data', [], $lang) }}</p>
@endif

{{-- Countries table (compact 5-row, capped in service) --}}
@if(isset($a['countries']) && is_array($a['countries']) && count($a['countries']) > 0)
    <h3 class="mt-6">{{ __('report.analytics_top_countries', [], $lang) }}</h3>
    <table class="data-table mb-4">
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
@endif
