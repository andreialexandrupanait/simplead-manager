@php
    $cf = $data['cloudflare'];
    $lang = $language ?? 'ro';
    $primaryColor = $branding['primary_color'] ?? '#7C3AED';
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['cloudflare']['title'] ?? __('report.section_cloudflare', [], $lang),
    'number' => $sectionNumber ?? null,
])

<p class="section-description">
    {{ $sectionOverrides['cloudflare']['description'] ?? __('report.cloudflare_description', [], $lang) }}
</p>

{{-- KPI Row --}}
<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-value" style="font-size: 14pt;">{{ $cf['total_requests_formatted'] }}</div>
        <div class="kpi-label">{{ __('report.cf_total_requests', [], $lang) }}</div>
        @if($cf['requests_trend']['display'] ?? false)
            <div class="card-trend" style="color: {{ $cf['requests_trend']['color'] }};">
                {{ $cf['requests_trend']['display'] }} {{ __('report.vs_previous', [], $lang) }}
            </div>
        @endif
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="font-size: 14pt;">{{ $cf['bandwidth_formatted'] }}</div>
        <div class="kpi-label">{{ __('report.cf_bandwidth', [], $lang) }}</div>
        @if($cf['bandwidth_trend']['display'] ?? false)
            <div class="card-trend" style="color: {{ $cf['bandwidth_trend']['color'] }};">
                {{ $cf['bandwidth_trend']['display'] }} {{ __('report.vs_previous', [], $lang) }}
            </div>
        @endif
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="font-size: 14pt;">{{ $cf['cache_hit_ratio_formatted'] }}</div>
        <div class="kpi-label">{{ __('report.cf_cache_ratio', [], $lang) }}</div>
        @if($cf['cache_ratio_trend']['display'] ?? false)
            <div class="card-trend" style="color: {{ $cf['cache_ratio_trend']['color'] }};">
                {{ $cf['cache_ratio_trend']['display'] }} {{ __('report.vs_previous', [], $lang) }}
            </div>
        @endif
    </div>
</div>

{{-- Configuration & Zone info --}}
<div class="two-col">
    <div class="subcard">
        <div class="subcard-title">{{ __('report.cf_config', [], $lang) }}</div>
        <table class="info-row">
            <tr>
                <td class="info-label">{{ __('report.cf_plan', [], $lang) }}</td>
                <td class="info-value">{{ $cf['plan_type'] }}</td>
            </tr>
            <tr>
                <td class="info-label">{{ __('report.cf_ssl_mode', [], $lang) }}</td>
                <td class="info-value">{{ $cf['ssl_mode'] }}</td>
            </tr>
            <tr>
                <td class="info-label">{{ __('report.cf_cache_level', [], $lang) }}</td>
                <td class="info-value">{{ $cf['cache_level'] }}</td>
            </tr>
        </table>
    </div>
    <div class="subcard">
        <div class="subcard-title">{{ __('report.cf_zone', [], $lang) }}</div>
        <table class="info-row">
            <tr>
                <td class="info-label">{{ __('report.cf_zone', [], $lang) }}</td>
                <td class="info-value">{{ $cf['zone_name'] }}</td>
            </tr>
            <tr>
                <td class="info-label">{{ __('report.cf_status', [], $lang) }}</td>
                <td class="info-value">
                    <span class="badge badge-success">{{ ucfirst($cf['status']) }}</span>
                </td>
            </tr>
        </table>
    </div>
</div>
