@php
    $u = $data['updates'];
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['updates']['title'] ?? __('report.section_updates', [], $lang),
    'number' => $sectionNumber ?? null,
])

{{-- WP Core status card (always shown) --}}
@php
    $wpVersion = $u['wp_version'] ?? $site->wp_version ?? null;
    $coreUpdateAvailable = $site->core_update_version && $site->core_update_version !== $wpVersion;
@endphp
@if($wpVersion)
    <div class="wp-core-card" style="{{ $coreUpdateAvailable ? 'background: #fffbeb; border-color: #fde68a;' : '' }}">
        @if($coreUpdateAvailable)
            <span style="color: #f59e0b; font-weight: 700;">&#9888;</span>
            {{ __('report.updates_wp_outdated', [], $lang) }} (v{{ $wpVersion }} &rarr; v{{ $site->core_update_version }})
        @else
            <span class="check-success">&#10003;</span>
            {{ __('report.updates_wp_latest', [], $lang) }} (v{{ $wpVersion }})
        @endif
    </div>
@endif

{{-- Summary KPI cards --}}
<div class="kpi-row mb-4">
    <div class="kpi-card">
        <div class="kpi-value {{ $u['total_count'] == 0 ? 'value-muted' : '' }}">{{ $u['total_count'] }}</div>
        <div class="kpi-label">{{ __('report.overview_total', [], $lang) }}</div>
        <div class="card-trend">@include('reports.components.trend', ['trend' => $u['total_trend'] ?? null])</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value {{ $u['plugin_count'] == 0 ? 'value-muted' : '' }}">{{ $u['plugin_count'] }}</div>
        <div class="kpi-label">{{ __('report.updates_plugins', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value {{ $u['theme_count'] == 0 ? 'value-muted' : '' }}">{{ $u['theme_count'] }}</div>
        <div class="kpi-label">{{ __('report.updates_themes', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value {{ $u['core_count'] == 0 ? 'value-muted' : '' }}">{{ $u['core_count'] }}</div>
        <div class="kpi-label">{{ __('report.updates_core', [], $lang) }}</div>
    </div>
</div>

@if($u['total_count'] === 0)
    <p style="color: #94a3b8; font-size: 8.5pt;">{{ __('report.updates_no_updates', [], $lang) }}</p>
@else
    {{-- Summary text line --}}
    <p style="font-size: 8.5pt; color: #64748b; margin-bottom: 14px;">
        {{ __('report.updates_summary_line', [
            'total' => $u['total_count'],
            'plugins' => $u['plugin_count'],
            'themes' => $u['theme_count'],
            'core' => $u['core_count'],
        ], $lang) }}
    </p>

    {{-- Horizontal bar chart for update breakdown --}}
    @if(($sectionOptions['updates']['show_breakdown_chart'] ?? true) && !empty($u['horizontal_bar_chart']['bars'] ?? []))
        <div class="chart-container mb-4">
            <div class="chart-title">{{ __('report.updates_breakdown', [], $lang) }}</div>
            @include('reports.components.chart-horizontal-bar', [
                'chartData' => $u['horizontal_bar_chart'],
            ])
        </div>
    @endif

    {{-- Consolidated update table --}}
    @php
        $consolidated = $u['consolidated_updates'] ?? [];
        $typeOrder = ['plugin' => 0, 'theme' => 1, 'core' => 2];
        usort($consolidated, function ($a, $b) use ($typeOrder) {
            return ($typeOrder[$a['type'] ?? ''] ?? 9) <=> ($typeOrder[$b['type'] ?? ''] ?? 9);
        });
        $totalConsolidated = count($consolidated);
        $displayUpdates = array_slice($consolidated, 0, 15);
    @endphp

    @if(($sectionOptions['updates']['show_log_table'] ?? true) && count($displayUpdates) > 0)
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('report.updates_name', [], $lang) }}</th>
                    <th>{{ __('report.updates_type', [], $lang) }}</th>
                    <th>{{ __('report.updates_version', [], $lang) }}</th>
                    <th>{{ __('report.updates_date', [], $lang) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($displayUpdates as $update)
                    <tr>
                        <td>{{ $update['name'] ?? '—' }}</td>
                        <td>
                            @php
                                $typeBadge = match($update['type'] ?? '') {
                                    'plugin' => 'badge-info',
                                    'theme' => 'badge-warning',
                                    'core' => 'badge-success',
                                    default => 'badge-info',
                                };
                            @endphp
                            <span class="badge {{ $typeBadge }}">{{ ucfirst($update['type'] ?? '—') }}</span>
                        </td>
                        <td>
                            {{ $update['from_version'] ?? '—' }}
                            <span class="version-arrow">&rarr;</span>
                            <span class="version-new">{{ $update['to_version'] ?? '—' }}</span>
                        </td>
                        <td>{{ isset($update['performed_at']) ? \Carbon\Carbon::parse($update['performed_at'])->format('d/m/Y') : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($totalConsolidated > 15)
            <div class="table-footnote">{{ __('report.showing_of', ['shown' => 15, 'total' => $totalConsolidated], $lang) }}</div>
        @endif
    @endif
@endif

{{-- Pending updates info --}}
@if(($site->pending_updates_count ?? 0) > 0)
    <hr class="subsection-divider">
    <div class="highlight-box">
        <div style="font-size: 9pt; font-weight: 600; color: #d97706; margin-bottom: 4px;">&#9888; {{ __('report.updates_pending', ['count' => $site->pending_updates_count], $lang) }}</div>
        <div style="font-size: 8pt; color: #64748b;">{{ __('report.updates_pending_desc', [], $lang) }}</div>
    </div>
@endif
