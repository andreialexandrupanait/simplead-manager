@php
    $db = $data['database_health'];
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['database_health']['title'] ?? __('report.section_database_health', [], $lang),
    'number' => $sectionNumber ?? null,
])

<p class="section-description">
    {{ $sectionOverrides['database_health']['description'] ?? __('report.database_health_description', [], $lang) }}
</p>

{{-- KPI Row --}}
<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-value" style="font-size: 14pt;">{{ $db['total_size'] }}</div>
        <div class="kpi-label">{{ __('report.db_total_size', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value">{{ $db['total_tables'] }}</div>
        <div class="kpi-label">{{ __('report.db_total_tables', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="font-size: 14pt;">{{ $db['autoload_size'] }}</div>
        <div class="kpi-label">{{ __('report.db_autoload_size', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="font-size: 14pt;">
            <span class="db-status-{{ $db['status'] }}" style="padding: 4px 12px; border-radius: 4px;">
                {{ __('report.db_status_' . $db['status'], [], $lang) }}
            </span>
        </div>
        <div class="kpi-label">{{ __('report.db_status', [], $lang) }}</div>
    </div>
</div>

{{-- Issues --}}
@if(!empty($db['issues']))
    <h3>{{ __('report.db_issues_found', [], $lang) }}</h3>
    @foreach($db['issues'] as $issue)
        <div class="issue-box">⚠ {{ $issue }}</div>
    @endforeach
@endif

{{-- MyISAM warning --}}
@if(($db['myisam_count'] ?? 0) > 0)
    <div class="issue-box">⚠ {{ __('report.db_myisam_warning', ['count' => $db['myisam_count']], $lang) }}</div>
@endif

{{-- Largest tables --}}
@if(!empty($db['largest_tables']))
    <h3>{{ __('report.db_largest_tables', [], $lang) }}</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>{{ __('report.db_table_name', [], $lang) }}</th>
                <th class="text-center">{{ __('report.db_table_rows', [], $lang) }}</th>
                <th class="text-center">{{ __('report.db_table_size', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($db['largest_tables'] as $table)
                <tr>
                    <td class="cell-truncate">{{ $table['name'] }}</td>
                    <td class="text-center">{{ number_format($table['rows']) }}</td>
                    <td class="text-center">{{ \App\Helpers\FormatHelper::bytes(($table['data_size'] ?? 0) + ($table['index_size'] ?? 0)) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- Tables with overhead --}}
@if(!empty($db['tables_with_overhead']))
    <h3>{{ __('report.db_tables_overhead', [], $lang) }}</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>{{ __('report.db_table_name', [], $lang) }}</th>
                <th class="text-center">{{ __('report.db_overhead_size', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($db['tables_with_overhead'] as $table)
                <tr>
                    <td class="cell-truncate">{{ $table['name'] }}</td>
                    <td class="text-center">{{ \App\Helpers\FormatHelper::bytes($table['overhead'] ?? 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<p class="table-footnote">{{ __('report.security_scanned_at', [], $lang) }}: {{ $db['checked_at'] }}</p>
