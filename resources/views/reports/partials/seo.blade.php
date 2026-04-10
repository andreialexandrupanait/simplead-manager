@php
    $seo  = $data['seo'];
    $lang = $language ?? 'ro';

    $score      = $seo['score'];
    $scoreColor = $seo['score_color']; // green | yellow | red
    $scoreLabel = $seo['score_label'];

    $issuesSummary = $seo['issues_summary'];
    $technical     = $seo['technical'];
    $topIssues     = $seo['top_issues'] ?? [];
    $keywords      = $seo['keywords'] ?? [];

    $severityClasses = [
        'critical' => 'badge-danger',
        'high'     => 'badge-warning',
        'medium'   => 'badge-info',
        'low'      => 'badge-secondary',
        'info'     => 'badge-secondary',
    ];
@endphp

@include('reports.components.section-header', [
    'title'  => __('report.section_seo', [], $lang),
    'number' => $sectionNumber ?? null,
])

{{-- Score + Issues Summary ------------------------------------------------}}
<table style="width: 100%; border-collapse: separate; border-spacing: 8px; margin-bottom: 14px;">
    <tr>
        {{-- Score circle --}}
        <td class="security-score-box" style="width: 30%; text-align: center; vertical-align: top;">
            <div style="margin-bottom: 4px;">
                @include('reports.components.score-circle', ['score' => $score, 'size' => 90])
            </div>
            <div class="kpi-label">{{ __('report.seo_score', [], $lang) }}</div>
            <div style="font-size: 8.5pt; color: #6b7280; margin-top: 2px;">{{ $scoreLabel }}</div>
            <div class="card-trend" style="margin-top: 4px;">
                @include('reports.components.trend', ['trend' => $seo['score_trend'] ?? null])
            </div>
        </td>

        {{-- Issues summary --}}
        <td style="vertical-align: top; padding: 10px 14px;">
            <div style="font-size: 10pt; font-weight: 600; color: #111827; margin-bottom: 10px;">
                {{ __('report.seo_issues_summary', [], $lang) }}
            </div>
            <table class="security-summary-table">
                @if($issuesSummary['critical_count'] > 0)
                    <tr>
                        <td class="severity-critical" style="width: 30%;">{{ $issuesSummary['critical_count'] }}</td>
                        <td>{{ __('report.seo_severity_critical', [], $lang) }}</td>
                    </tr>
                @endif
                @if($issuesSummary['high_count'] > 0)
                    <tr>
                        <td class="severity-high" style="width: 30%;">{{ $issuesSummary['high_count'] }}</td>
                        <td>{{ __('report.seo_severity_high', [], $lang) }}</td>
                    </tr>
                @endif
                @if($issuesSummary['medium_count'] > 0)
                    <tr>
                        <td class="severity-medium" style="width: 30%;">{{ $issuesSummary['medium_count'] }}</td>
                        <td>{{ __('report.seo_severity_medium', [], $lang) }}</td>
                    </tr>
                @endif
                @if($issuesSummary['low_count'] > 0)
                    <tr>
                        <td class="severity-low" style="width: 30%;">{{ $issuesSummary['low_count'] }}</td>
                        <td>{{ __('report.seo_severity_low', [], $lang) }}</td>
                    </tr>
                @endif
                @if($issuesSummary['info_count'] > 0)
                    <tr>
                        <td class="severity-info" style="width: 30%;">{{ $issuesSummary['info_count'] }}</td>
                        <td>{{ __('report.seo_severity_info', [], $lang) }}</td>
                    </tr>
                @endif
                @if($issuesSummary['total'] === 0)
                    <tr>
                        <td colspan="2" class="text-success">{{ __('report.seo_no_issues', [], $lang) }}</td>
                    </tr>
                @endif
            </table>

            {{-- SEO Plugin --}}
            @if(!empty($seo['seo_plugin']))
                <div class="text-xs text-muted" style="margin-top: 10px;">
                    {{ __('report.seo_plugin_label', [], $lang) }}: <strong>{{ $seo['seo_plugin'] }}</strong>
                </div>
            @endif
        </td>
    </tr>
</table>

{{-- Technical SEO ----------------------------------------------------------}}
<h3>{{ __('report.seo_technical_title', [], $lang) }}</h3>
<table class="data-table mb-4">
    <tbody>
        <tr>
            <td style="width: 50%;">{{ __('report.seo_tech_robots', [], $lang) }}</td>
            <td>
                @if($technical['robots_ok'])
                    <span class="text-success">&#10003; {{ __('report.seo_ok', [], $lang) }}</span>
                @else
                    <span class="text-danger">&#10007; {{ __('report.seo_not_ok', [], $lang) }}</span>
                @endif
            </td>
        </tr>
        <tr>
            <td>{{ __('report.seo_tech_sitemap', [], $lang) }}</td>
            <td>
                @if($technical['sitemap_ok'])
                    <span class="text-success">&#10003; {{ __('report.seo_ok', [], $lang) }}</span>
                @else
                    <span class="text-danger">&#10007; {{ __('report.seo_not_ok', [], $lang) }}</span>
                @endif
            </td>
        </tr>
        <tr>
            <td>{{ __('report.seo_tech_structured_data', [], $lang) }}</td>
            <td>
                @if($technical['structured_data_found'])
                    <span class="text-success">&#10003; {{ __('report.seo_detected', [], $lang) }}</span>
                @else
                    <span class="text-muted">{{ __('report.seo_not_detected', [], $lang) }}</span>
                @endif
            </td>
        </tr>
        <tr>
            <td>{{ __('report.seo_tech_search_visible', [], $lang) }}</td>
            <td>
                @if($technical['search_visible'])
                    <span class="text-success">&#10003; {{ __('report.seo_visible', [], $lang) }}</span>
                @else
                    <span class="text-danger">&#10007; {{ __('report.seo_hidden', [], $lang) }}</span>
                @endif
            </td>
        </tr>
    </tbody>
</table>

{{-- Top Issues ------------------------------------------------------------}}
@if(!empty($topIssues))
    <h3>{{ __('report.seo_top_issues_title', [], $lang) }}</h3>
    <table class="data-table mb-4">
        <thead>
            <tr>
                <th style="width: 15%;">{{ __('report.seo_col_severity', [], $lang) }}</th>
                <th>{{ __('report.seo_col_issue', [], $lang) }}</th>
                <th style="width: 20%;">{{ __('report.seo_col_category', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($topIssues as $issue)
                <tr>
                    <td>
                        <span class="badge {{ $severityClasses[$issue['severity']] ?? 'badge-secondary' }}">
                            {{ strtoupper($issue['severity']) }}
                        </span>
                    </td>
                    <td>
                        {{ $issue['title'] }}
                        @if(!empty($issue['url']))
                            <br><span class="text-xs text-muted">{{ $issue['url'] }}</span>
                        @endif
                    </td>
                    <td class="text-muted">{{ $issue['category'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- Tracked Keywords -------------------------------------------------------}}
@if(!empty($keywords))
    <h3>{{ __('report.seo_keywords_title', [], $lang) }}</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>{{ __('report.seo_col_keyword', [], $lang) }}</th>
                <th style="width: 15%; text-align: right;">{{ __('report.seo_col_position', [], $lang) }}</th>
                <th style="width: 15%; text-align: right;">{{ __('report.seo_col_clicks', [], $lang) }}</th>
                <th style="width: 18%; text-align: right;">{{ __('report.seo_col_impressions', [], $lang) }}</th>
                <th style="width: 12%; text-align: center;">{{ __('report.seo_col_trend', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($keywords as $kw)
                <tr>
                    <td>{{ $kw['keyword'] }}</td>
                    <td style="text-align: right; font-weight: 600;">
                        {{ $kw['position'] !== null ? '#' . $kw['position'] : '—' }}
                    </td>
                    <td style="text-align: right;">{{ number_format($kw['clicks']) }}</td>
                    <td style="text-align: right;">{{ number_format($kw['impressions']) }}</td>
                    <td style="text-align: center;">
                        @if($kw['trend'] === 'up')
                            <span style="color: #10b981; font-weight: 700;">&#8599;</span>
                        @elseif($kw['trend'] === 'down')
                            <span style="color: #ef4444; font-weight: 700;">&#8600;</span>
                        @else
                            <span style="color: #9ca3af;">&#8212;</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
