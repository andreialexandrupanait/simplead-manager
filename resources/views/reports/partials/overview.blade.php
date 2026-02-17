@php
    $o = $data['overview'];
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => __('report.section_overview', [], $lang),
])

{{-- Group 1: Monitoring — Updates, Uptime, Backups --}}
<div class="overview-group-label">{{ __('report.overview_group_monitoring', [], $lang) }}</div>
<table class="overview-grid">
    <tr>
        @include('reports.components.metric-card', [
            'label' => __('report.overview_updates', [], $lang),
            'value' => $o['updates']['count'] ?? 0,
            'sublabel' => __('report.overview_total', [], $lang),
            'trend' => $o['updates']['trend'] ?? null,
            'width' => '33%',
        ])
        @include('reports.components.metric-card', [
            'label' => __('report.overview_uptime', [], $lang),
            'value' => isset($o['uptime']['percentage']) ? number_format($o['uptime']['percentage'], 2, $lang === 'ro' ? ',' : '.', '') . '%' : __('report.not_available', [], $lang),
            'sublabel' => __('report.overview_incidents', ['count' => $o['uptime']['incidents'] ?? 0], $lang),
            'trend' => $o['uptime']['trend'] ?? null,
            'width' => '33%',
        ])
        @include('reports.components.metric-card', [
            'label' => __('report.overview_backups', [], $lang),
            'value' => ($o['backups']['successful'] ?? 0) . ' / ' . ($o['backups']['total'] ?? 0),
            'trend' => $o['backups']['trend'] ?? null,
            'width' => '33%',
        ])
    </tr>
</table>

{{-- Divider --}}
<div class="overview-divider"></div>

{{-- Group 2: Performance --}}
<div class="overview-group-label">{{ __('report.overview_group_performance', [], $lang) }}</div>
<table class="overview-grid">
    <tr>
        <td class="overview-card" style="width: 50%;">
            <div class="card-label">{{ __('report.overview_performance', [], $lang) }}</div>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="text-align: center; padding: 4px; width: 50%;">
                        @php
                            $mScore = $o['performance']['mobile'] ?? null;
                            $mClass = $mScore === null ? 'score-na' : ($mScore >= 90 ? 'score-green' : ($mScore >= 50 ? 'score-orange' : 'score-red'));
                        @endphp
                        <div class="score-circle {{ $mClass }}">{{ $mScore !== null ? round($mScore) : '—' }}</div>
                        <div class="text-xs text-muted" style="margin-top: 6px;">{{ __('report.overview_mobile', [], $lang) }}</div>
                        <div class="card-trend">@include('reports.components.trend', ['trend' => $o['performance']['mobile_trend'] ?? null])</div>
                    </td>
                    <td style="text-align: center; padding: 4px; width: 50%;">
                        @php
                            $dScore = $o['performance']['desktop'] ?? null;
                            $dClass = $dScore === null ? 'score-na' : ($dScore >= 90 ? 'score-green' : ($dScore >= 50 ? 'score-orange' : 'score-red'));
                        @endphp
                        <div class="score-circle {{ $dClass }}">{{ $dScore !== null ? round($dScore) : '—' }}</div>
                        <div class="text-xs text-muted" style="margin-top: 6px;">{{ __('report.overview_desktop', [], $lang) }}</div>
                        <div class="card-trend">@include('reports.components.trend', ['trend' => $o['performance']['desktop_trend'] ?? null])</div>
                    </td>
                </tr>
            </table>
        </td>
        {{-- Security score in overview (if available) --}}
        @if(isset($o['security']) && $o['security']['score'] !== null)
            <td class="overview-card" style="width: 50%;">
                <div class="card-label">{{ __('report.overview_security', [], $lang) }}</div>
                @php
                    $secScore = $o['security']['score'];
                    $secClass = $secScore >= 80 ? 'score-green' : ($secScore >= 50 ? 'score-orange' : 'score-red');
                @endphp
                <div style="text-align: center; padding: 4px;">
                    <div class="score-circle {{ $secClass }}">{{ round($secScore) }}</div>
                    <div class="text-xs text-muted" style="margin-top: 6px;">{{ __('report.security_score', [], $lang) }}</div>
                    <div class="card-trend">@include('reports.components.trend', ['trend' => $o['security']['trend'] ?? null])</div>
                </div>
            </td>
        @else
            @if($o['database']['was_cleaned'] ?? false)
                @include('reports.components.metric-card', [
                    'label' => __('report.overview_database', [], $lang),
                    'value' => __('report.overview_cleaned', [], $lang),
                    'sublabel' => __('report.overview_saved', ['size' => \App\Helpers\FormatHelper::bytes($o['database']['space_saved'] ?? 0)], $lang),
                    'width' => '50%',
                ])
            @else
                <td class="overview-card" style="width: 50%;">
                    <div class="card-label">{{ __('report.overview_database', [], $lang) }}</div>
                    <div class="card-value-sm" style="color: #9ca3af;">—</div>
                </td>
            @endif
        @endif
    </tr>
</table>

{{-- Divider --}}
<div class="overview-divider"></div>

{{-- Group 3: Traffic & SEO --}}
<div class="overview-group-label">{{ __('report.overview_group_traffic', [], $lang) }}</div>
<table class="overview-grid">
    <tr>
        <td class="overview-card" style="width: 50%;">
            <div class="card-label">{{ __('report.overview_analytics', [], $lang) }}</div>
            @if($o['analytics']['pageviews'] ?? null)
                <div class="card-value-sm">{{ number_format($o['analytics']['pageviews']) }}</div>
                <div class="card-sublabel">{{ __('report.overview_pageviews', [], $lang) }}</div>
                <div class="card-trend">@include('reports.components.trend', ['trend' => $o['analytics']['pageviews_trend'] ?? null])</div>
                <div style="margin-top: 4px;">
                    <span style="font-size: 9pt; font-weight: 600; color: #111827;">{{ number_format($o['analytics']['users'] ?? 0) }}</span>
                    <span class="text-xs text-muted">{{ __('report.overview_users', [], $lang) }}</span>
                </div>
            @else
                <div class="card-value-sm" style="color: #9ca3af;">{{ __('report.not_available', [], $lang) }}</div>
            @endif
        </td>
        <td class="overview-card" style="width: 50%;">
            <div class="card-label">{{ __('report.overview_search_console', [], $lang) }}</div>
            @if($o['search_console']['clicks'] ?? null)
                <div class="card-value-sm">{{ number_format($o['search_console']['clicks']) }}</div>
                <div class="card-sublabel">{{ __('report.overview_clicks', [], $lang) }}</div>
                <div class="card-trend">@include('reports.components.trend', ['trend' => $o['search_console']['clicks_trend'] ?? null])</div>
                <div style="margin-top: 4px;">
                    <span style="font-size: 9pt; font-weight: 600; color: #111827;">{{ number_format($o['search_console']['impressions'] ?? 0) }}</span>
                    <span class="text-xs text-muted">{{ __('report.overview_impressions', [], $lang) }}</span>
                </div>
            @else
                <div class="card-value-sm" style="color: #9ca3af;">{{ __('report.not_available', [], $lang) }}</div>
            @endif
        </td>
    </tr>
</table>

{{-- Database row (only if we have security above and database was cleaned) --}}
@if(isset($o['security']) && $o['security']['score'] !== null && ($o['database']['was_cleaned'] ?? false))
    <table class="overview-grid" style="margin-top: 4px;">
        <tr>
            @include('reports.components.metric-card', [
                'label' => __('report.overview_database', [], $lang),
                'value' => __('report.overview_cleaned', [], $lang),
                'sublabel' => __('report.overview_saved', ['size' => \App\Helpers\FormatHelper::bytes($o['database']['space_saved'] ?? 0)], $lang),
                'width' => '100%',
            ])
        </tr>
    </table>
@endif
