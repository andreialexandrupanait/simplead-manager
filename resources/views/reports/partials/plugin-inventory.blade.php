@php
    $pi = $data['plugin_inventory'];
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['plugin_inventory']['title'] ?? __('report.section_plugin_inventory', [], $lang),
    'number' => $sectionNumber ?? null,
])

<p class="section-description">
    {{ $sectionOverrides['plugin_inventory']['description'] ?? __('report.plugin_inventory_description', [], $lang) }}
</p>

{{-- KPI Row --}}
<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-value">{{ $pi['total_plugins'] }}</div>
        <div class="kpi-label">{{ __('report.plugins_total', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value">{{ $pi['active_plugins'] }}</div>
        <div class="kpi-label">{{ __('report.plugins_active', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value">{{ $pi['with_updates'] }}</div>
        <div class="kpi-label">{{ __('report.plugins_with_updates', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value">{{ $pi['abandoned_or_closed'] }}</div>
        <div class="kpi-label">{{ __('report.plugins_abandoned', [], $lang) }}</div>
    </div>
</div>

{{-- Horizontal bar chart --}}
@if(!empty($pi['horizontal_bar_chart']))
    <div class="chart-container">
        @include('reports.components.chart-horizontal-bar', ['chartData' => $pi['horizontal_bar_chart'], 'primaryColor' => $branding['primary_color'] ?? '#7C3AED'])
    </div>
@endif

{{-- Plugin table --}}
@if(count($pi['plugins']) > 0)
    <table class="data-table">
        <thead>
            <tr>
                <th>{{ __('report.plugin_name', [], $lang) }}</th>
                <th>{{ __('report.plugin_version', [], $lang) }}</th>
                <th>{{ __('report.plugin_status', [], $lang) }}</th>
                <th>{{ __('report.plugin_auto_update', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($pi['plugins'] as $plugin)
                <tr>
                    <td class="cell-break">
                        {{ $plugin['name'] }}
                        @if($plugin['is_abandoned'])
                            <span class="badge badge-abandoned">{{ __('report.plugins_abandoned', [], $lang) }}</span>
                        @endif
                    </td>
                    <td>
                        {{ $plugin['version'] }}
                        @if($plugin['has_update'])
                            <br><span class="text-xs" style="color: #d97706;">→ {{ $plugin['update_version'] }}</span>
                        @endif
                    </td>
                    <td>
                        @if($plugin['is_active'])
                            <span class="badge badge-active">{{ __('report.plugin_status_active', [], $lang) }}</span>
                        @else
                            <span class="badge badge-inactive">{{ __('report.plugin_status_inactive', [], $lang) }}</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if($plugin['auto_update'])
                            <span class="check-passed">✓</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- Themes subsection --}}
<hr class="subsection-divider">
<h3>{{ __('report.themes_title', [], $lang) }}</h3>

<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-value">{{ $pi['total_themes'] }}</div>
        <div class="kpi-label">{{ __('report.themes_total', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="font-size: 12pt;">{{ $pi['active_theme'] ?? '—' }}</div>
        <div class="kpi-label">{{ __('report.themes_active_theme', [], $lang) }}</div>
        @if($pi['active_theme_is_child'] && $pi['active_theme_parent'])
            <div class="text-xs text-muted mt-2">{{ __('report.theme_child_of', ['parent' => $pi['active_theme_parent']], $lang) }}</div>
        @endif
    </div>
</div>

@if(count($pi['themes']) > 1)
    <table class="data-table">
        <thead>
            <tr>
                <th>{{ __('report.theme_name', [], $lang) }}</th>
                <th>{{ __('report.theme_version', [], $lang) }}</th>
                <th>{{ __('report.theme_status', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($pi['themes'] as $theme)
                <tr>
                    <td>
                        {{ $theme['name'] }}
                        @if($theme['is_child_theme'] && $theme['parent_theme'])
                            <br><span class="text-xs text-muted">{{ __('report.theme_child_of', ['parent' => $theme['parent_theme']], $lang) }}</span>
                        @endif
                    </td>
                    <td>
                        {{ $theme['version'] }}
                        @if($theme['has_update'])
                            <br><span class="text-xs" style="color: #d97706;">→ {{ $theme['update_version'] }}</span>
                        @endif
                    </td>
                    <td>
                        @if($theme['is_active'])
                            <span class="badge badge-active">{{ __('report.plugin_status_active', [], $lang) }}</span>
                        @else
                            <span class="badge badge-inactive">{{ __('report.plugin_status_inactive', [], $lang) }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
