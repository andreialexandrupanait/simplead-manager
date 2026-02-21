@php
    $o = $data['overview'];
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => __('report.section_overview', [], $lang),
    'number' => $sectionNumber ?? null,
])

<div class="overview-grid">
    {{-- Updates --}}
    @if(in_array('updates', $sections))
        <div class="overview-card">
            <div class="overview-value">{{ $o['updates']['count'] ?? 0 }}</div>
            <div class="overview-label">{{ __('report.overview_updates', [], $lang) }}</div>
            <div class="overview-detail">{{ __('report.overview_total', [], $lang) }}</div>
        </div>
    @endif

    {{-- Uptime --}}
    @if(in_array('uptime', $sections) && isset($o['uptime']['percentage']))
        @php
            $uptimePct = $o['uptime']['percentage'];
            $uptimeCardClass = $uptimePct >= 99.5 ? 'card-good' : ($uptimePct >= 99 ? 'card-warning' : 'card-danger');
        @endphp
        <div class="overview-card {{ $uptimeCardClass }}">
            <div class="overview-value">{{ number_format($uptimePct, 2, $lang === 'ro' ? ',' : '.', '') }}%</div>
            <div class="overview-label">Uptime</div>
            <div class="overview-detail">{{ __('report.overview_incidents', ['count' => $o['uptime']['incidents'] ?? 0], $lang) }}</div>
        </div>
    @endif

    {{-- Backups --}}
    @if(in_array('backups', $sections))
        <div class="overview-card">
            <div class="overview-value">{{ $o['backups']['successful'] ?? 0 }}<span class="overview-value-small"> / {{ $o['backups']['total'] ?? 0 }}</span></div>
            <div class="overview-label">{{ __('report.overview_backups', [], $lang) }}</div>
            <div class="overview-detail">&nbsp;</div>
        </div>
    @endif

    {{-- Performance --}}
    @if(in_array('performance', $sections) && ($o['performance']['mobile'] !== null || $o['performance']['desktop'] !== null))
        @php
            $mScore = $o['performance']['mobile'];
            $dScore = $o['performance']['desktop'];
            $mClass = $mScore === null ? '' : ($mScore >= 90 ? 'score-good' : ($mScore >= 50 ? 'score-warning' : 'score-danger'));
            $dClass = $dScore === null ? '' : ($dScore >= 90 ? 'score-good' : ($dScore >= 50 ? 'score-warning' : 'score-danger'));
        @endphp
        <div class="overview-card">
            <div class="overview-value">
                <span class="perf-score {{ $mClass }}">{{ $mScore !== null ? round($mScore) : '—' }}</span>
                <span class="perf-separator">/</span>
                <span class="perf-score {{ $dClass }}">{{ $dScore !== null ? round($dScore) : '—' }}</span>
            </div>
            <div class="overview-label">{{ __('report.overview_performance', [], $lang) }}</div>
            <div class="overview-detail">{{ __('report.overview_mobile', [], $lang) }} / {{ __('report.overview_desktop', [], $lang) }}</div>
        </div>
    @endif

    {{-- Analytics (only if data exists) --}}
    @if(in_array('analytics', $sections) && ($o['analytics']['pageviews'] ?? null) !== null)
        <div class="overview-card">
            <div class="overview-value">{{ number_format($o['analytics']['users'] ?? 0) }}</div>
            <div class="overview-label">{{ __('report.overview_analytics', [], $lang) }}</div>
            <div class="overview-detail">{{ number_format($o['analytics']['pageviews'] ?? 0) }} {{ __('report.overview_pageviews', [], $lang) }}</div>
        </div>
    @endif

    {{-- Search Console (only if data exists) --}}
    @if(in_array('search_console', $sections) && ($o['search_console']['impressions'] ?? null) !== null)
        <div class="overview-card">
            <div class="overview-value">{{ number_format($o['search_console']['impressions']) }}</div>
            <div class="overview-label">{{ __('report.overview_search_console', [], $lang) }}</div>
            <div class="overview-detail">{{ number_format($o['search_console']['clicks'] ?? 0) }} {{ __('report.overview_clicks', [], $lang) }}</div>
        </div>
    @endif

    {{-- Database (only if cleaned) --}}
    @if($o['database']['was_cleaned'] ?? false)
        <div class="overview-card">
            <div class="overview-value">{{ \App\Helpers\FormatHelper::bytes($o['database']['space_saved'] ?? 0) }}</div>
            <div class="overview-label">{{ __('report.overview_database', [], $lang) }}</div>
            <div class="overview-detail">{{ __('report.overview_cleaned', [], $lang) }}</div>
        </div>
    @endif
</div>

{{-- Executive Snapshot cards --}}
@if(isset($data['executive_snapshot']) && count($data['executive_snapshot']) > 0)
    <hr class="subsection-divider">
    <h3>{{ __('report.executive_summary', [], $lang) }}</h3>
    <p class="section-description" style="margin-bottom: 12px;">{{ __('report.executive_summary_description', [], $lang) }}</p>

    <div class="snapshot-grid" style="flex-wrap: wrap;">
        @foreach($data['executive_snapshot'] as $snap)
            @php
                $statusClass = match($snap['status'] ?? 'neutral') {
                    'good' => 'snapshot-status-good',
                    'warning' => 'snapshot-status-warning',
                    'danger' => 'snapshot-status-danger',
                    default => 'snapshot-status-neutral',
                };
            @endphp
            <div class="snapshot-card {{ $statusClass }}">
                <div class="snapshot-value">{{ $snap['value'] }}</div>
                <div class="snapshot-label">{{ $snap['label'] }}</div>
                @if(!empty($snap['note']))
                    <div class="snapshot-note">{{ $snap['note'] }}</div>
                @endif
            </div>
        @endforeach
    </div>
@endif

{{-- Site environment info --}}
<hr class="subsection-divider">
<h3>{{ __('report.overview_environment', [], $lang) }}</h3>
<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-value" style="font-size: 14pt;">{{ $site->wp_version ?? '—' }}</div>
        <div class="kpi-label">WordPress</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="font-size: 14pt;">{{ $site->php_version ?? '—' }}</div>
        <div class="kpi-label">PHP</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="font-size: 14pt;">{{ $site->server_software ?? '—' }}</div>
        <div class="kpi-label">{{ __('report.overview_server', [], $lang) }}</div>
    </div>
</div>
