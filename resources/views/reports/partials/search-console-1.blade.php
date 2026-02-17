@php
    $sc = $data['search_console'];
    $overview = $sc['overview'] ?? [];
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => __('report.section_search_console', [], $lang),
])

{{-- Dual-line chart: clicks + impressions --}}
@if(!empty($sc['dual_line_chart']['line1']['line_points'] ?? '') || !empty($sc['dual_line_chart']['line2']['line_points'] ?? ''))
    <div class="chart-container">
        <div class="chart-title">{{ __('report.search_performance_over_time', [], $lang) }}</div>
        @include('reports.components.chart-dual-line', [
            'line1' => $sc['dual_line_chart']['line1'] ?? [],
            'line2' => $sc['dual_line_chart']['line2'] ?? [],
            'color1' => '#2563eb',
            'color2' => '#10b981',
            'areaColor1' => '#dbeafe',
            'areaColor2' => '#d1fae5',
            'color2Light' => '#6ee7b7',
            'legend1' => __('report.search_clicks', [], $lang),
            'legend2' => __('report.search_impressions', [], $lang),
            'yLabels' => $sc['dual_line_y_labels'] ?? [],
            'xLabels' => $sc['dual_line_x_labels'] ?? [],
        ])
    </div>
@elseif(!empty($overview['chart_points']['line_points'] ?? ''))
    <div class="chart-container">
        <div class="chart-title">{{ __('report.search_performance_over_time', [], $lang) }}</div>
        @include('reports.components.chart-line', [
            'points' => $overview['chart_points'],
            'primaryColor' => '#2563eb',
            'areaColor' => '#dbeafe',
            'yLabels' => $overview['chart_y_labels'] ?? [],
            'xLabels' => $overview['chart_x_labels'] ?? [],
            'legendLabel' => __('report.search_clicks', [], $lang),
        ])
    </div>
@endif

{{-- KPI cards (4-column single row) --}}
<table class="kpi-grid mb-4">
    <tr>
        <td class="kpi-card" style="width: 25%;">
            @php $clicks = $overview['total_clicks'] ?? 0; @endphp
            <div class="kpi-value {{ $clicks == 0 ? 'value-muted' : '' }}">{{ $clicks == 0 ? '—' : number_format($clicks) }}</div>
            <div class="kpi-label">{{ __('report.search_total_clicks', [], $lang) }}</div>
            <div class="card-trend">@include('reports.components.trend', ['trend' => $overview['clicks_trend'] ?? null])</div>
        </td>
        <td class="kpi-card" style="width: 25%;">
            @php $impressions = $overview['total_impressions'] ?? 0; @endphp
            <div class="kpi-value {{ $impressions == 0 ? 'value-muted' : '' }}">{{ $impressions == 0 ? '—' : number_format($impressions) }}</div>
            <div class="kpi-label">{{ __('report.search_impressions', [], $lang) }}</div>
            <div class="card-trend">@include('reports.components.trend', ['trend' => $overview['impressions_trend'] ?? null])</div>
        </td>
        <td class="kpi-card" style="width: 25%;">
            @php
                $ctr = $overview['avg_ctr'] ?? 0;
                $ctrDisplay = $ctr == 0 ? '< 0.1%' : number_format($ctr * 100, 2, $lang === 'ro' ? ',' : '.', '') . '%';
            @endphp
            <div class="kpi-value {{ $ctr == 0 ? 'value-muted' : '' }}">{{ $ctr == 0 ? '< 0,1%' : $ctrDisplay }}</div>
            <div class="kpi-label">{{ __('report.search_avg_ctr', [], $lang) }}</div>
            <div class="card-trend">@include('reports.components.trend', ['trend' => $overview['ctr_trend'] ?? null])</div>
        </td>
        <td class="kpi-card" style="width: 25%;">
            @php $pos = $overview['avg_position'] ?? 0; @endphp
            <div class="kpi-value {{ $pos == 0 ? 'value-muted' : '' }}">{{ $pos == 0 ? '—' : number_format($pos, 1, $lang === 'ro' ? ',' : '.', '') }}</div>
            <div class="kpi-label">{{ __('report.search_avg_position', [], $lang) }}</div>
            <div class="card-trend">@include('reports.components.trend', ['trend' => $overview['position_trend'] ?? null])</div>
        </td>
    </tr>
</table>

{{-- Top queries (capped at 10 in service) --}}
@if(isset($sc['queries']) && is_array($sc['queries']) && count($sc['queries']) > 0)
    <h3 class="mt-4">{{ __('report.search_top_queries', [], $lang) }}</h3>
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
                <tr>
                    <td>{{ $query['query'] ?? $query['keys'][0] ?? '—' }}</td>
                    <td>{{ number_format($query['clicks'] ?? 0) }}</td>
                    <td>{{ number_format($query['impressions'] ?? 0) }}</td>
                    <td>{{ isset($query['ctr']) ? number_format($query['ctr'] * 100, 1) . '%' : '—' }}</td>
                    <td>{{ isset($query['position']) ? number_format($query['position'], 1) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
