@php
    $seo = $data['seo'];
    $lang = $language ?? 'ro';
    $cats = $seo['categories'];
    $issues = $seo['issues'];

    $catLabels = [
        'technical' => $lang === 'ro' ? 'SEO Tehnic' : 'Technical SEO',
        'on_page' => $lang === 'ro' ? 'On-Page' : 'On-Page',
        'performance' => $lang === 'ro' ? 'Performanta' : 'Performance',
        'other' => $lang === 'ro' ? 'Altele' : 'Other',
    ];

    $sevLabels = [
        'critical' => $lang === 'ro' ? 'Critice' : 'Critical',
        'high' => $lang === 'ro' ? 'Ridicate' : 'High',
        'medium' => $lang === 'ro' ? 'Medii' : 'Medium',
        'low' => $lang === 'ro' ? 'Scazute' : 'Low',
        'info' => 'Info',
    ];

    $sevColors = [
        'critical' => '#ef4444',
        'high' => '#f97316',
        'medium' => '#eab308',
        'low' => '#3b82f6',
        'info' => '#94a3b8',
    ];
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['seo']['title'] ?? ($lang === 'ro' ? 'Audit SEO' : 'SEO Audit'),
    'number' => $sectionNumber ?? null,
])

{{-- Score + Categories --}}
<div class="two-col" style="margin-bottom: 16px;">
    {{-- Overall Score --}}
    <div style="text-align: center; flex: 0 0 140px;">
        @include('reports.components.score-circle', ['score' => $seo['score'], 'size' => 90])
        <div style="margin-top: 6px; font-size: 8pt; color: #64748b;">
            {{ $lang === 'ro' ? 'Scor General' : 'Overall Score' }}
        </div>
        @if($seo['score_trend'])
            <div class="card-trend" style="margin-top: 2px;">
                @include('reports.components.trend', ['trend' => $seo['score_trend']])
            </div>
        @endif
        <div style="margin-top: 4px; font-size: 7pt; color: #94a3b8;">
            {{ $seo['pages_crawled'] }} {{ $lang === 'ro' ? 'pagini scanate' : 'pages crawled' }}
            &middot; {{ $seo['scanned_at'] }}
        </div>
    </div>

    {{-- Category Scores --}}
    <div style="flex: 1;">
        <table style="width: 100%; font-size: 9pt; border-collapse: collapse;">
            @foreach($cats as $key => $score)
                @php
                    $color = $score >= 80 ? '#10b981' : ($score >= 50 ? '#f59e0b' : '#ef4444');
                    $bg = $score >= 80 ? '#d1fae5' : ($score >= 50 ? '#fef3c7' : '#fee2e2');
                @endphp
                <tr>
                    <td style="padding: 5px 8px; color: #475569;">{{ $catLabels[$key] ?? $key }}</td>
                    <td style="padding: 5px 8px; width: 50%; position: relative;">
                        <div style="background: #f1f5f9; border-radius: 4px; height: 10px; overflow: hidden;">
                            <div style="background: {{ $color }}; width: {{ $score }}%; height: 100%; border-radius: 4px;"></div>
                        </div>
                    </td>
                    <td style="padding: 5px 8px; text-align: right; font-weight: 600; color: {{ $color }}; font-feature-settings: 'tnum'; min-width: 35px;">{{ $score }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

{{-- Issue Summary --}}
@if(($sectionOptions['seo']['show_issues'] ?? true))
<div style="margin-bottom: 16px;">
    <div style="font-size: 9pt; font-weight: 600; color: #1e293b; margin-bottom: 8px;">
        {{ $lang === 'ro' ? 'Probleme Gasite' : 'Issues Found' }}
        <span style="font-weight: 400; color: #94a3b8;">({{ $issues['total'] }} {{ $lang === 'ro' ? 'total' : 'total' }})</span>
    </div>
    <table style="width: 100%; font-size: 8.5pt; border-collapse: collapse;">
        <tr style="background: #f8fafc;">
            @foreach(['critical', 'high', 'medium', 'low', 'info'] as $sev)
                <td style="padding: 6px 10px; text-align: center; border: 1px solid #e2e8f0;">
                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: {{ $sevColors[$sev] }}; margin-right: 4px; vertical-align: middle;"></span>
                    {{ $sevLabels[$sev] }}
                </td>
            @endforeach
        </tr>
        <tr>
            @foreach(['critical', 'high', 'medium', 'low', 'info'] as $sev)
                <td style="padding: 8px 10px; text-align: center; border: 1px solid #e2e8f0; font-weight: 700; font-size: 12pt; color: {{ $issues[$sev] > 0 ? $sevColors[$sev] : '#cbd5e1' }};">
                    {{ $issues[$sev] }}
                </td>
            @endforeach
        </tr>
    </table>

    @if($seo['broken_links'] > 0)
        <div style="margin-top: 6px; font-size: 8pt; color: #ef4444;">
            {{ $seo['broken_links'] }} {{ $lang === 'ro' ? 'link-uri sparte detectate' : 'broken links detected' }}
        </div>
    @endif
</div>
@endif

{{-- Top Recommendations --}}
@if(($sectionOptions['seo']['show_recommendations'] ?? true) && !empty($seo['top_issues']))
<div style="margin-bottom: 16px;">
    <div style="font-size: 9pt; font-weight: 600; color: #1e293b; margin-bottom: 8px;">
        {{ $lang === 'ro' ? 'Recomandari Prioritare' : 'Priority Recommendations' }}
    </div>
    <table style="width: 100%; font-size: 8.5pt; border-collapse: collapse;">
        @foreach($seo['top_issues'] as $issue)
            <tr>
                <td style="padding: 5px 8px; border-bottom: 1px solid #f1f5f9; width: 12px; vertical-align: top;">
                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: {{ $sevColors[$issue['severity']] ?? '#94a3b8' }}; margin-top: 3px;"></span>
                </td>
                <td style="padding: 5px 8px; border-bottom: 1px solid #f1f5f9;">
                    <div style="font-weight: 600; color: #1e293b;">{{ $issue['title'] }}</div>
                    @if($issue['recommendation'])
                        <div style="color: #64748b; margin-top: 2px;">{{ $issue['recommendation'] }}</div>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>
</div>
@endif

{{-- Score Trend --}}
@if(($sectionOptions['seo']['show_score_trend'] ?? true) && $seo['trend_chart'])
<div>
    <div style="font-size: 9pt; font-weight: 600; color: #1e293b; margin-bottom: 8px;">
        {{ $lang === 'ro' ? 'Evolutie Scor' : 'Score Trend' }}
    </div>
    <div style="text-align: center;">
        {!! $seo['trend_chart'] !!}
    </div>
</div>
@endif

{{-- SSL Status --}}
@if($seo['ssl']['valid'] !== null)
<div style="margin-top: 12px; font-size: 8pt; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 8px;">
    SSL:
    @if($seo['ssl']['valid'])
        <span style="color: #10b981; font-weight: 600;">{{ $lang === 'ro' ? 'Valid' : 'Valid' }}</span>
        — {{ $lang === 'ro' ? 'expira' : 'expires' }} {{ $seo['ssl']['expiry'] }}
        ({{ $seo['ssl']['days_left'] }} {{ $lang === 'ro' ? 'zile' : 'days' }})
    @else
        <span style="color: #ef4444; font-weight: 600;">{{ $lang === 'ro' ? 'Expirat' : 'Expired' }}</span>
    @endif
</div>
@endif
