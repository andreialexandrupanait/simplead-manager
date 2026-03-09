@php
    $p = $data['performance'];
    $lang = $language ?? 'ro';

    $vitals = [
        'fcp' => __('report.performance_fcp', [], $lang),
        'si' => __('report.performance_si', [], $lang),
        'lcp' => __('report.performance_lcp', [], $lang),
        'tbt' => __('report.performance_tbt', [], $lang),
        'cls' => __('report.performance_cls', [], $lang),
    ];
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['performance']['title'] ?? __('report.section_performance', [], $lang),
    'number' => $sectionNumber ?? null,
])

{{-- Two-column flex: Mobile | Desktop --}}
<div class="two-col">
    {{-- Mobile Card --}}
    @if($sectionOptions['performance']['show_mobile'] ?? true)
    <div class="perf-card">
        <div class="perf-card-title">{{ __('report.performance_mobile', [], $lang) }}</div>
        @if(isset($p['mobile']))
            <div class="perf-card-subtitle">
                {{ __('report.performance_updated', [], $lang) }}:
                {{ \Carbon\Carbon::parse($p['mobile']['tested_at'])->format('d/m/Y') }}
            </div>

            <div style="text-align: center; margin-bottom: 10px;">
                @include('reports.components.score-circle', ['score' => $p['mobile_score'] ?? null, 'size' => 90])
                @if(isset($p['mobile_trend']))
                    <div class="card-trend" style="margin-top: 4px;">
                        @include('reports.components.trend', ['trend' => $p['mobile_trend']])
                    </div>
                @endif
            </div>

            <table class="perf-metric-row">
                @foreach($vitals as $key => $label)
                    <tr>
                        <td>
                            @php $color = $p['mobile'][$key . '_color'] ?? 'gray'; @endphp
                            @if($color === 'green')
                                <span class="perf-indicator good"></span>
                            @elseif($color === 'orange')
                                <span class="perf-indicator moderate"></span>
                            @else
                                <span class="perf-indicator poor"></span>
                            @endif
                            {{ $label }}
                        </td>
                        <td style="text-align: right; font-weight: 600; font-feature-settings: 'tnum';">
                            {{ $p['mobile'][$key] ?? '—' }}
                        </td>
                    </tr>
                @endforeach
            </table>
        @else
            <div style="padding: 10px 0; color: #94a3b8; font-size: 8.5pt;">
                {{ __('report.not_tested', [], $lang) }}
            </div>
        @endif
    </div>
    @endif

    {{-- Desktop Card --}}
    @if($sectionOptions['performance']['show_desktop'] ?? true)
    <div class="perf-card">
        <div class="perf-card-title">{{ __('report.performance_desktop', [], $lang) }}</div>
        @if(isset($p['desktop']))
            <div class="perf-card-subtitle">
                {{ __('report.performance_updated', [], $lang) }}:
                {{ \Carbon\Carbon::parse($p['desktop']['tested_at'])->format('d/m/Y') }}
            </div>

            <div style="text-align: center; margin-bottom: 10px;">
                @include('reports.components.score-circle', ['score' => $p['desktop_score'] ?? null, 'size' => 90])
                @if(isset($p['desktop_trend']))
                    <div class="card-trend" style="margin-top: 4px;">
                        @include('reports.components.trend', ['trend' => $p['desktop_trend']])
                    </div>
                @endif
            </div>

            <table class="perf-metric-row">
                @foreach($vitals as $key => $label)
                    <tr>
                        <td>
                            @php $color = $p['desktop'][$key . '_color'] ?? 'gray'; @endphp
                            @if($color === 'green')
                                <span class="perf-indicator good"></span>
                            @elseif($color === 'orange')
                                <span class="perf-indicator moderate"></span>
                            @else
                                <span class="perf-indicator poor"></span>
                            @endif
                            {{ $label }}
                        </td>
                        <td style="text-align: right; font-weight: 600; font-feature-settings: 'tnum';">
                            {{ $p['desktop'][$key] ?? '—' }}
                        </td>
                    </tr>
                @endforeach
            </table>
        @else
            <div style="padding: 10px 0; color: #94a3b8; font-size: 8.5pt;">
                {{ __('report.not_tested', [], $lang) }}
            </div>
        @endif
    </div>
    @endif
</div>

{{-- Legend --}}
<div class="perf-legend">
    {{ __('report.performance_legend', [], $lang) }}:
    &nbsp;&nbsp;
    <span class="perf-indicator poor" style="display: inline-block; vertical-align: middle;"></span> 0–49
    &nbsp;&nbsp;&nbsp;
    <span class="perf-indicator moderate" style="display: inline-block; vertical-align: middle;"></span> 50–89
    &nbsp;&nbsp;&nbsp;
    <span class="perf-indicator good" style="display: inline-block; vertical-align: middle;"></span> 90–100
</div>
