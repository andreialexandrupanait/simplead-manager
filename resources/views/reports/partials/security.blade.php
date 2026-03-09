@php
    $sec = $data['security'];
    $lang = $language ?? 'ro';

    $score = $sec['score'];
    $scoreClass = $score >= 80 ? 'score-green' : ($score >= 50 ? 'score-orange' : 'score-red');
@endphp

@include('reports.components.section-header', [
    'title' => __('report.section_security', [], $lang),
])

{{-- Score + severity summary --}}
<table style="width: 100%; border-collapse: separate; border-spacing: 8px; margin-bottom: 14px;">
    <tr>
        <td class="security-score-box" style="width: 30%;">
            <div style="margin-bottom: 4px;">
                @include('reports.components.score-circle', ['score' => $score, 'size' => 90])
            </div>
            <div class="kpi-label">{{ __('report.security_score', [], $lang) }}</div>
            <div class="card-trend">@include('reports.components.trend', ['trend' => $sec['score_trend'] ?? null])</div>
        </td>
        <td style="vertical-align: top; padding: 10px 14px;">
            <div style="font-size: 10pt; font-weight: 600; color: #111827; margin-bottom: 10px;">
                {{ __('report.security_summary', [], $lang) }}
            </div>
            <table class="security-summary-table">
                @if($sec['critical_count'] > 0)
                    <tr>
                        <td class="severity-critical" style="width: 30%;">{{ $sec['critical_count'] }}</td>
                        <td>{{ __('report.security_critical', [], $lang) }}</td>
                    </tr>
                @endif
                @if($sec['high_count'] > 0)
                    <tr>
                        <td class="severity-high" style="width: 30%;">{{ $sec['high_count'] }}</td>
                        <td>{{ __('report.security_high', [], $lang) }}</td>
                    </tr>
                @endif
                @if($sec['medium_count'] > 0)
                    <tr>
                        <td class="severity-medium" style="width: 30%;">{{ $sec['medium_count'] }}</td>
                        <td>{{ __('report.security_medium', [], $lang) }}</td>
                    </tr>
                @endif
                @if($sec['low_count'] > 0)
                    <tr>
                        <td class="severity-low" style="width: 30%;">{{ $sec['low_count'] }}</td>
                        <td>{{ __('report.security_low', [], $lang) }}</td>
                    </tr>
                @endif
                @if($sec['total_issues'] === 0)
                    <tr>
                        <td colspan="2" class="text-success">{{ __('report.security_no_issues', [], $lang) }}</td>
                    </tr>
                @endif
            </table>
            <div class="text-xs text-muted">
                {{ __('report.security_scanned_at', [], $lang) }}: {{ $sec['scanned_at']->format('d/m/Y H:i') }}
            </div>
        </td>
    </tr>
</table>

{{-- Active issues --}}
@if(!empty($sec['active_issues']))
    <h3>{{ __('report.security_active_issues', [], $lang) }}</h3>
    <table class="data-table mb-4">
        <thead>
            <tr>
                <th style="width: 15%;">{{ __('report.security_severity_col', [], $lang) }}</th>
                <th>{{ __('report.security_issue', [], $lang) }}</th>
                <th style="width: 20%;">{{ __('report.security_category', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sec['active_issues'] as $issue)
                <tr>
                    <td>
                        <span class="badge badge-{{ $issue['severity'] === 'critical' ? 'danger' : ($issue['severity'] === 'high' ? 'warning' : 'info') }}">
                            {{ strtoupper($issue['severity']) }}
                        </span>
                    </td>
                    <td>{{ $issue['title'] }}</td>
                    <td class="text-muted">{{ $issue['category'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- Vulnerabilities --}}
@if(!empty($sec['vulnerabilities']))
    <h3>{{ __('report.security_vulnerabilities', [], $lang) }}</h3>
    <table class="data-table mb-4">
        <thead>
            <tr>
                <th style="width: 15%;">{{ __('report.security_severity_col', [], $lang) }}</th>
                <th>{{ __('report.security_vulnerability', [], $lang) }}</th>
                <th style="width: 20%;">{{ __('report.security_fix_version', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sec['vulnerabilities'] as $vuln)
                <tr>
                    <td>
                        <span class="badge badge-{{ $vuln['severity'] === 'critical' ? 'danger' : ($vuln['severity'] === 'high' ? 'warning' : 'info') }}">
                            {{ strtoupper($vuln['severity']) }}
                        </span>
                    </td>
                    <td>
                        {{ $vuln['title'] }}
                        <br><span class="text-xs text-muted">{{ ucfirst($vuln['software_type']) }}: {{ $vuln['software_slug'] }} (v{{ $vuln['installed_version'] ?? '—' }})</span>
                    </td>
                    <td>
                        @if($vuln['fixed_in_version'])
                            <span class="version-new">{{ $vuln['fixed_in_version'] }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- Recommendations --}}
@if(!empty($sec['recommendations']))
    <h3>{{ __('report.security_recommendations', [], $lang) }}</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>{{ __('report.security_recommendation', [], $lang) }}</th>
                <th style="width: 25%;">{{ __('report.security_category', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sec['recommendations'] as $rec)
                <tr>
                    <td>{{ $rec['title'] }}</td>
                    <td class="text-muted">{{ $rec['category'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
