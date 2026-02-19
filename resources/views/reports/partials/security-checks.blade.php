@php
    $sc = $data['security_checks'];
    $lang = $language ?? 'ro';
    $primaryColor = $branding['primary_color'] ?? '#7C3AED';
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['security_checks']['title'] ?? __('report.section_security_checks', [], $lang),
    'number' => $sectionNumber ?? null,
])

<p class="section-description">
    {{ $sectionOverrides['security_checks']['description'] ?? __('report.security_checks_description', [], $lang) }}
</p>

{{-- KPI Row --}}
<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-value">{{ $sc['overall_score'] }}%</div>
        <div class="kpi-label">{{ __('report.sec_overall_score', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="color: var(--green-500);">{{ $sc['passed'] }}</div>
        <div class="kpi-label">{{ __('report.sec_passed', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="color: var(--red-500);">{{ $sc['failed'] }}</div>
        <div class="kpi-label">{{ __('report.sec_failed', [], $lang) }}</div>
    </div>
</div>

{{-- Score bar --}}
<div class="progress-bar mb-6" style="height: 10px;">
    <div class="progress-fill {{ $sc['overall_score'] >= 80 ? 'green' : ($sc['overall_score'] >= 50 ? 'amber' : '') }}"
         style="width: {{ $sc['overall_score'] }}%; {{ $sc['overall_score'] < 50 ? 'background: var(--red-500);' : '' }}">
    </div>
</div>

{{-- Per-category sections --}}
@foreach($sc['categories'] as $catKey => $cat)
    @if($cat['total'] > 0)
        <div class="no-break">
            <h3 style="margin-top: 14px;">{{ __('report.sec_cat_' . $catKey, [], $lang) }}
                <span class="text-xs text-muted" style="font-weight: 400; margin-left: 8px;">
                    {{ $cat['passed'] }}/{{ $cat['total'] }} {{ __('report.sec_passed', [], $lang) }}
                </span>
            </h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 70%;">{{ __('report.sec_check', [], $lang) }}</th>
                        <th class="text-center">{{ __('report.sec_status', [], $lang) }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($cat['checks'] as $check)
                        <tr>
                            <td>{{ $check['title'] }}</td>
                            <td class="text-center">
                                @if($check['status'] === 'passed')
                                    <span class="check-passed">✓ {{ __('report.sec_status_passed', [], $lang) }}</span>
                                @elseif($check['status'] === 'failed')
                                    <span class="check-failed">✗ {{ __('report.sec_status_failed', [], $lang) }}</span>
                                @else
                                    <span class="check-unchecked">— {{ __('report.sec_status_unchecked', [], $lang) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endforeach
