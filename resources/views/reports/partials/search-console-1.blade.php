@php
    $sc = $data['search_console'];
    $overview = $sc['overview'] ?? [];
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['search_console']['title'] ?? __('report.section_search_console', [], $lang),
    'number' => $sectionNumber ?? null,
])

@if(!empty($sc['data_period_start']) && !empty($sc['data_period_end']))
    <p class="section-description" style="font-size:9px;color:#94a3b8;">
        {{ __('report.data_window', ['start' => $sc['data_period_start'], 'end' => $sc['data_period_end']], $lang) }}@if(!empty($sc['data_is_stale'])) — {{ __('report.data_stale_note', [], $lang) }}@endif
    </p>
@endif

{{-- Dual-line chart: clicks + impressions --}}
@if(($sectionOptions['search_console']['show_performance_chart'] ?? true) && (!empty($sc['dual_line_chart']['line1']['line_points'] ?? '') || !empty($sc['dual_line_chart']['line2']['line_points'] ?? '')))
    <div class="chart-container">
        <div class="chart-title">{{ __('report.search_performance_over_time', [], $lang) }}</div>
        @include('reports.components.chart-dual-line', [
            'line1' => $sc['dual_line_chart']['line1'] ?? [],
            'line2' => $sc['dual_line_chart']['line2'] ?? [],
            'color1' => $primaryColor ?? '#7C3AED',
            'color2' => '#10b981',
            'areaColor1' => '#EDE9FE',
            'areaColor2' => '#d1fae5',
            'color2Light' => '#6ee7b7',
            'legend1' => __('report.search_clicks', [], $lang),
            'legend2' => __('report.search_impressions', [], $lang),
            'yLabels' => $sc['dual_line_y_labels'] ?? [],
            'xLabels' => $sc['dual_line_x_labels'] ?? [],
        ])
    </div>
@elseif(($sectionOptions['search_console']['show_performance_chart'] ?? true) && !empty($overview['chart_points']['line_points'] ?? ''))
    <div class="chart-container">
        <div class="chart-title">{{ __('report.search_performance_over_time', [], $lang) }}</div>
        @include('reports.components.chart-line', [
            'points' => $overview['chart_points'],
            'primaryColor' => $primaryColor ?? '#7C3AED',
            'areaColor' => '#EDE9FE',
            'yLabels' => $overview['chart_y_labels'] ?? [],
            'xLabels' => $overview['chart_x_labels'] ?? [],
            'legendLabel' => __('report.search_clicks', [], $lang),
        ])
    </div>
@endif

{{-- KPI cards (4-column flex row) --}}
@php
    $clicks = $overview['total_clicks'] ?? 0;
    $impressions = $overview['total_impressions'] ?? 0;
    $ctr = $overview['avg_ctr'] ?? 0;
    $ctrDisplay = $ctr == 0 ? '< 0.1%' : number_format($ctr * 100, 2, $lang === 'ro' ? ',' : '.', '') . '%';
    $pos = $overview['avg_position'] ?? 0;
@endphp
<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-value {{ $clicks == 0 ? 'value-muted' : '' }}">{{ $clicks == 0 ? '—' : number_format($clicks) }}</div>
        <div class="kpi-label">{{ __('report.search_total_clicks', [], $lang) }}</div>
        <div class="card-trend">@include('reports.components.trend', ['trend' => $overview['clicks_trend'] ?? null])</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value {{ $impressions == 0 ? 'value-muted' : '' }}">{{ $impressions == 0 ? '—' : number_format($impressions) }}</div>
        <div class="kpi-label">{{ __('report.search_impressions', [], $lang) }}</div>
        <div class="card-trend">@include('reports.components.trend', ['trend' => $overview['impressions_trend'] ?? null])</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value {{ $ctr == 0 ? 'value-muted' : '' }}">{{ $ctr == 0 ? '< 0,1%' : $ctrDisplay }}</div>
        <div class="kpi-label">{{ __('report.search_avg_ctr', [], $lang) }}</div>
        <div class="card-trend">@include('reports.components.trend', ['trend' => $overview['ctr_trend'] ?? null])</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value {{ $pos == 0 ? 'value-muted' : '' }}">{{ $pos == 0 ? '—' : number_format($pos, 1, $lang === 'ro' ? ',' : '.', '') }}</div>
        <div class="kpi-label">{{ __('report.search_avg_position', [], $lang) }}</div>
        <div class="card-trend">@include('reports.components.trend', ['trend' => $overview['position_trend'] ?? null])</div>
    </div>
</div>

{{-- Top queries with color-coded positions --}}
@if(($sectionOptions['search_console']['show_queries_table'] ?? true) && isset($sc['queries']) && is_array($sc['queries']) && count($sc['queries']) > 0)
    <hr class="subsection-divider">
    <h3>{{ __('report.search_top_queries', [], $lang) }}</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>{{ __('report.search_query', [], $lang) }}</th>
                <th>{{ __('report.search_clicks', [], $lang) }}</th>
                <th>{{ __('report.search_impressions', [], $lang) }}</th>
                <th>{{ __('report.search_ctr', [], $lang) }}</th>
                <th>{{ __('report.search_position', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sc['queries'] as $query)
                @php
                    $qPos = $query['position'] ?? 0;
                    $posClass = $qPos > 0 && $qPos <= 10 ? 'position-good' : ($qPos <= 20 ? 'position-moderate' : 'position-poor');
                @endphp
                <tr>
                    <td class="cell-break">{{ $query['query'] ?? $query['keys'][0] ?? '—' }}</td>
                    <td>{{ number_format($query['clicks'] ?? 0) }}</td>
                    <td>{{ number_format($query['impressions'] ?? 0) }}</td>
                    <td>{{ isset($query['ctr']) ? number_format($query['ctr'] * 100, 1) . '%' : '—' }}</td>
                    <td class="{{ $posClass }}">{{ isset($query['position']) ? number_format($query['position'], 1) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- Top Pages table --}}
@if(isset($sc['pages']) && is_array($sc['pages']) && count($sc['pages']) > 0)
    <hr class="subsection-divider">
    <h3>{{ __('report.search_top_pages', [], $lang) }}</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>{{ __('report.search_page', [], $lang) }}</th>
                <th>{{ __('report.search_clicks', [], $lang) }}</th>
                <th>{{ __('report.search_impressions', [], $lang) }}</th>
                <th>{{ __('report.search_ctr', [], $lang) }}</th>
                <th>{{ __('report.search_position', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sc['pages'] as $page)
                @php
                    $pageUrl = $page['page'] ?? $page['keys'][0] ?? '—';
                    $pageUrl = preg_replace('#^https?://[^/]+#', '', $pageUrl);
                    $pageUrl = \Illuminate\Support\Str::limit($pageUrl, 45);
                    $pPos = $page['position'] ?? 0;
                    $pPosClass = $pPos > 0 && $pPos <= 10 ? 'position-good' : ($pPos <= 20 ? 'position-moderate' : 'position-poor');
                @endphp
                <tr>
                    <td class="cell-truncate" title="{{ $page['page'] ?? '' }}">{{ $pageUrl }}</td>
                    <td>{{ number_format($page['clicks'] ?? 0) }}</td>
                    <td>{{ number_format($page['impressions'] ?? 0) }}</td>
                    <td>{{ isset($page['ctr']) ? number_format($page['ctr'] * 100, 1) . '%' : '—' }}</td>
                    <td class="{{ $pPosClass }}">{{ isset($page['position']) ? number_format($page['position'], 1) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- Countries + Devices side-by-side --}}
@php
    $hasCountries = isset($sc['countries']) && is_array($sc['countries']) && count($sc['countries']) > 0;
    $hasDevices = isset($sc['devices']) && is_array($sc['devices']) && count($sc['devices']) > 0;
@endphp

@if($hasCountries || $hasDevices)
    <hr class="subsection-divider">
    @if($hasCountries && $hasDevices)
        <div class="two-col">
    @endif

    @if($hasCountries)
        <div>
            <h3 style="margin-top: 0;">{{ __('report.search_top_countries', [], $lang) }}</h3>
            <table class="data-table" style="margin-bottom: 0;">
                <thead>
                    <tr>
                        <th>{{ __('report.search_country', [], $lang) }}</th>
                        <th>{{ __('report.search_clicks', [], $lang) }}</th>
                        <th>{{ __('report.search_impressions', [], $lang) }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sc['countries'] as $country)
                        <tr>
                            <td>{{ $country['country'] ?? $country['keys'][0] ?? '—' }}</td>
                            <td>{{ number_format($country['clicks'] ?? 0) }}</td>
                            <td>{{ number_format($country['impressions'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($hasDevices)
        <div>
            <h3 style="margin-top: 0;">{{ __('report.search_top_devices', [], $lang) }}</h3>
            <table class="data-table" style="margin-bottom: 0;">
                <thead>
                    <tr>
                        <th>{{ __('report.search_device', [], $lang) }}</th>
                        <th>{{ __('report.search_clicks', [], $lang) }}</th>
                        <th>{{ __('report.search_impressions', [], $lang) }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sc['devices'] as $device)
                        <tr>
                            <td>{{ ucfirst($device['device'] ?? $device['keys'][0] ?? '—') }}</td>
                            <td>{{ number_format($device['clicks'] ?? 0) }}</td>
                            <td>{{ number_format($device['impressions'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($hasCountries && $hasDevices)
        </div>
    @endif
@endif
