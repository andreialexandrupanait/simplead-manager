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
            @if($seo['seo_plugin'] ?? null)
                &middot; {{ $seo['seo_plugin'] }}
            @endif
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
<div style="margin-bottom: 16px;">
    <div style="font-size: 9pt; font-weight: 600; color: #1e293b; margin-bottom: 8px;">
        {{ $lang === 'ro' ? 'Evolutie Scor' : 'Score Trend' }}
    </div>
    <div style="text-align: center;">
        @include('reports.components.chart-line', [
            'points' => $seo['trend_chart'],
            'primaryColor' => '#8D5CF5',
            'areaColor' => '#ede9fe',
            'yLabels' => $seo['trend_chart_y_labels'] ?? [],
        ])
    </div>
</div>
@endif

{{-- SSL Certificate --}}
@if(($sectionOptions['seo']['show_ssl_status'] ?? true) && $seo['ssl']['valid'] !== null)
<div style="margin-bottom: 16px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
    <div style="font-size: 9pt; font-weight: 600; color: #1e293b; margin-bottom: 8px;">
        {{ $lang === 'ro' ? 'Certificat SSL' : 'SSL Certificate' }}
    </div>
    <table style="width: 100%; font-size: 8.5pt; border-collapse: collapse;">
        <tr>
            <td style="padding: 4px 8px; color: #64748b;">Status</td>
            <td style="padding: 4px 8px; font-weight: 600; color: {{ $seo['ssl']['valid'] ? '#10b981' : '#ef4444' }};">
                {{ $seo['ssl']['valid'] ? ($lang === 'ro' ? 'Valid' : 'Valid') : ($lang === 'ro' ? 'Expirat' : 'Expired') }}
            </td>
        </tr>
        @if($seo['ssl']['issuer'] ?? null)
        <tr>
            <td style="padding: 4px 8px; color: #64748b;">{{ $lang === 'ro' ? 'Emitent' : 'Issuer' }}</td>
            <td style="padding: 4px 8px; color: #1e293b;">{{ $seo['ssl']['issuer'] }}</td>
        </tr>
        @endif
        @if($seo['ssl']['expiry'] ?? null)
        <tr>
            <td style="padding: 4px 8px; color: #64748b;">{{ $lang === 'ro' ? 'Expira' : 'Expires' }}</td>
            <td style="padding: 4px 8px; color: #1e293b;">{{ $seo['ssl']['expiry'] }} ({{ $seo['ssl']['days_left'] }} {{ $lang === 'ro' ? 'zile' : 'days' }})</td>
        </tr>
        @endif
    </table>
</div>
@endif

{{-- Security Headers --}}
@if(($sectionOptions['seo']['show_security_headers'] ?? true) && !empty($seo['security_headers']))
<div style="margin-bottom: 16px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
    <div style="font-size: 9pt; font-weight: 600; color: #1e293b; margin-bottom: 8px;">
        {{ $lang === 'ro' ? 'Headere de Securitate' : 'Security Headers' }}
    </div>
    @php
        $headerLabels = [
            'hsts' => 'HSTS (Strict-Transport-Security)',
            'x_frame_options' => 'X-Frame-Options',
            'x_content_type_options' => 'X-Content-Type-Options',
            'csp' => 'Content-Security-Policy',
        ];
    @endphp
    <table style="width: 100%; font-size: 8.5pt; border-collapse: collapse;">
        @foreach($headerLabels as $key => $label)
            <tr>
                <td style="padding: 4px 8px; color: #475569; border-bottom: 1px solid #f1f5f9;">{{ $label }}</td>
                <td style="padding: 4px 8px; text-align: right; border-bottom: 1px solid #f1f5f9;">
                    @if(!empty($seo['security_headers'][$key]))
                        <span style="color: #10b981; font-weight: 600;">&#10003;</span>
                    @else
                        <span style="color: #ef4444; font-weight: 600;">&#10007;</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>
</div>
@endif

{{-- Broken Links --}}
@if(($sectionOptions['seo']['show_broken_links'] ?? true) && !empty($seo['broken_links_detail']))
<div style="margin-bottom: 16px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
    <div style="font-size: 9pt; font-weight: 600; color: #1e293b; margin-bottom: 8px;">
        {{ $lang === 'ro' ? 'Link-uri Sparte' : 'Broken Links' }}
        <span style="font-weight: 400; color: #94a3b8;">({{ $seo['broken_links'] }} total)</span>
    </div>
    <table style="width: 100%; font-size: 8pt; border-collapse: collapse;">
        <tr style="background: #f8fafc;">
            <td style="padding: 4px 8px; font-weight: 600; color: #64748b; border: 1px solid #e2e8f0;">URL</td>
            <td style="padding: 4px 8px; font-weight: 600; color: #64748b; border: 1px solid #e2e8f0; width: 50px;">Status</td>
            <td style="padding: 4px 8px; font-weight: 600; color: #64748b; border: 1px solid #e2e8f0; width: 60px;">{{ $lang === 'ro' ? 'Tip' : 'Type' }}</td>
            <td style="padding: 4px 8px; font-weight: 600; color: #64748b; border: 1px solid #e2e8f0;">{{ $lang === 'ro' ? 'Gasit pe' : 'Found On' }}</td>
        </tr>
        @foreach($seo['broken_links_detail'] as $bl)
            <tr>
                <td style="padding: 3px 8px; border: 1px solid #e2e8f0; color: #ef4444; word-break: break-all;">{{ Str::limit($bl['url'], 50) }}</td>
                <td style="padding: 3px 8px; border: 1px solid #e2e8f0; text-align: center;">{{ $bl['status'] ?? '—' }}</td>
                <td style="padding: 3px 8px; border: 1px solid #e2e8f0;">{{ ucfirst($bl['type'] ?? '') }}</td>
                <td style="padding: 3px 8px; border: 1px solid #e2e8f0; color: #64748b; word-break: break-all;">{{ Str::limit($bl['found_on'] ?? '—', 40) }}</td>
            </tr>
        @endforeach
    </table>
</div>
@endif

{{-- Sitemap Analysis --}}
@if(($sectionOptions['seo']['show_sitemap'] ?? true))
<div style="margin-bottom: 16px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
    <div style="font-size: 9pt; font-weight: 600; color: #1e293b; margin-bottom: 8px;">
        {{ $lang === 'ro' ? 'Analiza Sitemap' : 'Sitemap Analysis' }}
    </div>
    <table style="width: 100%; font-size: 8.5pt; border-collapse: collapse;">
        <tr>
            <td style="padding: 4px 8px; color: #64748b;">Status</td>
            <td style="padding: 4px 8px; font-weight: 600; color: {{ ($seo['sitemap']['found'] ?? false) ? '#10b981' : '#ef4444' }};">
                {{ ($seo['sitemap']['found'] ?? false) ? ($lang === 'ro' ? 'Gasit' : 'Found') : ($lang === 'ro' ? 'Lipsa' : 'Not Found') }}
            </td>
        </tr>
        @if($seo['sitemap']['found'] ?? false)
            @if($seo['sitemap']['url'] ?? null)
            <tr><td style="padding: 4px 8px; color: #64748b;">URL</td><td style="padding: 4px 8px; color: #1e293b; word-break: break-all;">{{ $seo['sitemap']['url'] }}</td></tr>
            @endif
            <tr><td style="padding: 4px 8px; color: #64748b;">{{ $lang === 'ro' ? 'URL-uri in sitemap' : 'URLs in Sitemap' }}</td><td style="padding: 4px 8px; color: #1e293b; font-weight: 600;">{{ $seo['sitemap']['url_count'] ?? 0 }}</td></tr>
        @endif
    </table>
</div>
@endif

{{-- Robots.txt Analysis --}}
@if(($sectionOptions['seo']['show_robots'] ?? true))
<div style="margin-bottom: 16px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
    <div style="font-size: 9pt; font-weight: 600; color: #1e293b; margin-bottom: 8px;">
        {{ $lang === 'ro' ? 'Analiza Robots.txt' : 'Robots.txt Analysis' }}
    </div>
    <table style="width: 100%; font-size: 8.5pt; border-collapse: collapse;">
        <tr>
            <td style="padding: 4px 8px; color: #64748b;">Status</td>
            <td style="padding: 4px 8px; font-weight: 600; color: {{ ($seo['robots']['exists'] ?? false) ? '#10b981' : '#ef4444' }};">
                {{ ($seo['robots']['exists'] ?? false) ? ($lang === 'ro' ? 'Exista' : 'Found') : ($lang === 'ro' ? 'Lipsa' : 'Missing') }}
            </td>
        </tr>
        @if($seo['robots']['exists'] ?? false)
            <tr>
                <td style="padding: 4px 8px; color: #64748b;">{{ $lang === 'ro' ? 'Permite crawling' : 'Allows Crawling' }}</td>
                <td style="padding: 4px 8px; font-weight: 600; color: {{ ($seo['robots']['allows_crawling'] ?? true) ? '#10b981' : '#ef4444' }};">
                    {{ ($seo['robots']['allows_crawling'] ?? true) ? ($lang === 'ro' ? 'Da' : 'Yes') : ($lang === 'ro' ? 'Blocat' : 'Blocked') }}
                </td>
            </tr>
            <tr>
                <td style="padding: 4px 8px; color: #64748b;">{{ $lang === 'ro' ? 'Directiva Sitemap' : 'Sitemap Directive' }}</td>
                <td style="padding: 4px 8px; font-weight: 600; color: {{ ($seo['robots']['has_sitemap'] ?? false) ? '#10b981' : '#f59e0b' }};">
                    {{ ($seo['robots']['has_sitemap'] ?? false) ? ($lang === 'ro' ? 'Da' : 'Yes') : ($lang === 'ro' ? 'Lipsa' : 'Missing') }}
                </td>
            </tr>
            <tr>
                <td style="padding: 4px 8px; color: #64748b;">{{ $lang === 'ro' ? 'Reguli Disallow' : 'Disallow Rules' }}</td>
                <td style="padding: 4px 8px; color: #1e293b;">{{ $seo['robots']['disallow_count'] ?? 0 }}</td>
            </tr>
        @endif
    </table>
</div>
@endif

{{-- Top Pages Overview --}}
@if(($sectionOptions['seo']['show_top_pages'] ?? true) && !empty($seo['top_pages']))
<div style="margin-bottom: 16px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
    <div style="font-size: 9pt; font-weight: 600; color: #1e293b; margin-bottom: 8px;">
        {{ $lang === 'ro' ? 'Top Pagini' : 'Top Pages Overview' }}
    </div>
    <table style="width: 100%; font-size: 7.5pt; border-collapse: collapse;">
        <tr style="background: #f8fafc;">
            <td style="padding: 4px 6px; font-weight: 600; color: #64748b; border: 1px solid #e2e8f0;">URL</td>
            <td style="padding: 4px 6px; font-weight: 600; color: #64748b; border: 1px solid #e2e8f0; width: 40px;">Title</td>
            <td style="padding: 4px 6px; font-weight: 600; color: #64748b; border: 1px solid #e2e8f0; width: 40px;">{{ $lang === 'ro' ? 'Cuv.' : 'Words' }}</td>
            <td style="padding: 4px 6px; font-weight: 600; color: #64748b; border: 1px solid #e2e8f0; width: 40px;">{{ $lang === 'ro' ? 'Img -alt' : 'Img -alt' }}</td>
            <td style="padding: 4px 6px; font-weight: 600; color: #64748b; border: 1px solid #e2e8f0; width: 45px;">TTFB</td>
        </tr>
        @foreach($seo['top_pages'] as $tp)
            <tr>
                <td style="padding: 3px 6px; border: 1px solid #e2e8f0; color: #475569; word-break: break-all;">{{ Str::limit(parse_url($tp['url'], PHP_URL_PATH) ?: '/', 35) }}</td>
                <td style="padding: 3px 6px; border: 1px solid #e2e8f0; text-align: center; color: {{ ($tp['title_length'] ?? 0) < 30 || ($tp['title_length'] ?? 0) > 60 ? '#ef4444' : '#10b981' }};">{{ $tp['title_length'] ?? '—' }}</td>
                <td style="padding: 3px 6px; border: 1px solid #e2e8f0; text-align: center; color: {{ ($tp['word_count'] ?? 0) < 300 ? '#f59e0b' : '#475569' }};">{{ $tp['word_count'] ?? '—' }}</td>
                <td style="padding: 3px 6px; border: 1px solid #e2e8f0; text-align: center; color: {{ ($tp['images_no_alt'] ?? 0) > 0 ? '#ef4444' : '#10b981' }};">{{ $tp['images_no_alt'] ?? 0 }}</td>
                <td style="padding: 3px 6px; border: 1px solid #e2e8f0; text-align: center;">{{ $tp['ttfb_ms'] ? $tp['ttfb_ms'].'ms' : '—' }}</td>
            </tr>
        @endforeach
    </table>
</div>
@endif

{{-- Structured Data --}}
@if(($sectionOptions['seo']['show_structured_data'] ?? true))
<div style="margin-bottom: 16px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
    <div style="font-size: 9pt; font-weight: 600; color: #1e293b; margin-bottom: 8px;">
        {{ $lang === 'ro' ? 'Date Structurate (Schema.org)' : 'Structured Data (Schema.org)' }}
    </div>
    @php $sd = $seo['structured_data']; $sdColor = $sd['coverage_pct'] >= 50 ? '#10b981' : ($sd['coverage_pct'] >= 20 ? '#f59e0b' : '#ef4444'); @endphp
    <div style="font-size: 8.5pt; margin-bottom: 6px;">
        <span style="color: #64748b;">{{ $lang === 'ro' ? 'Acoperire' : 'Coverage' }}:</span>
        <span style="font-weight: 600; color: {{ $sdColor }};">{{ $sd['coverage_pct'] }}%</span>
        <span style="color: #94a3b8;">({{ $sd['pages_with_schema'] }}/{{ $sd['total_pages'] }} {{ $lang === 'ro' ? 'pagini' : 'pages' }})</span>
    </div>
    <div style="background: #f1f5f9; border-radius: 4px; height: 8px; overflow: hidden;">
        <div style="background: {{ $sdColor }}; width: {{ $sd['coverage_pct'] }}%; height: 100%; border-radius: 4px;"></div>
    </div>
</div>
@endif

{{-- Internal Linking --}}
@if(($sectionOptions['seo']['show_internal_linking'] ?? true))
<div style="margin-bottom: 16px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
    <div style="font-size: 9pt; font-weight: 600; color: #1e293b; margin-bottom: 8px;">
        {{ $lang === 'ro' ? 'Link-uri Interne' : 'Internal Linking' }}
    </div>
    @php $il = $seo['internal_linking']; @endphp
    <table style="width: 100%; font-size: 8.5pt; border-collapse: collapse;">
        <tr>
            <td style="padding: 4px 8px; color: #64748b;">{{ $lang === 'ro' ? 'Media link-uri interne / pagina' : 'Avg Internal Links / Page' }}</td>
            <td style="padding: 4px 8px; font-weight: 600; color: #1e293b;">{{ $il['avg_internal_links'] }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 8px; color: #64748b;">{{ $lang === 'ro' ? 'Pagini orfane (fara link-uri interne)' : 'Orphan Pages (no inbound links)' }}</td>
            <td style="padding: 4px 8px; font-weight: 600; color: {{ $il['orphan_count'] > 0 ? '#f59e0b' : '#10b981' }};">{{ $il['orphan_count'] }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 8px; color: #64748b;">{{ $lang === 'ro' ? 'Pagini adanci (profunzime > 3)' : 'Deep Pages (depth > 3)' }}</td>
            <td style="padding: 4px 8px; font-weight: 600; color: {{ $il['deep_page_count'] > 0 ? '#f59e0b' : '#10b981' }};">{{ $il['deep_page_count'] }}</td>
        </tr>
    </table>
</div>
@endif

{{-- Image Optimization --}}
@if(($sectionOptions['seo']['show_images'] ?? true))
<div style="margin-bottom: 16px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
    <div style="font-size: 9pt; font-weight: 600; color: #1e293b; margin-bottom: 8px;">
        {{ $lang === 'ro' ? 'Optimizare Imagini' : 'Image Optimization' }}
    </div>
    @php $img = $seo['images']; $imgColor = $img['missing_alt_pct'] <= 10 ? '#10b981' : ($img['missing_alt_pct'] <= 30 ? '#f59e0b' : '#ef4444'); @endphp
    <table style="width: 100%; font-size: 8.5pt; border-collapse: collapse;">
        <tr>
            <td style="padding: 4px 8px; color: #64748b;">{{ $lang === 'ro' ? 'Total imagini' : 'Total Images' }}</td>
            <td style="padding: 4px 8px; font-weight: 600; color: #1e293b;">{{ $img['total_images'] }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 8px; color: #64748b;">{{ $lang === 'ro' ? 'Fara text alternativ (alt)' : 'Missing Alt Text' }}</td>
            <td style="padding: 4px 8px; font-weight: 600; color: {{ $imgColor }};">{{ $img['total_missing_alt'] }} ({{ $img['missing_alt_pct'] }}%)</td>
        </tr>
    </table>
</div>
@endif

{{-- Social Meta Coverage --}}
@if(($sectionOptions['seo']['show_social'] ?? true))
<div style="margin-bottom: 16px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
    <div style="font-size: 9pt; font-weight: 600; color: #1e293b; margin-bottom: 8px;">
        {{ $lang === 'ro' ? 'Social Meta (Open Graph)' : 'Social Meta (Open Graph)' }}
    </div>
    @php $soc = $seo['social']; $socColor = $soc['coverage_pct'] >= 80 ? '#10b981' : ($soc['coverage_pct'] >= 40 ? '#f59e0b' : '#ef4444'); @endphp
    <div style="font-size: 8.5pt; margin-bottom: 6px;">
        <span style="color: #64748b;">{{ $lang === 'ro' ? 'Acoperire OG' : 'OG Coverage' }}:</span>
        <span style="font-weight: 600; color: {{ $socColor }};">{{ $soc['coverage_pct'] }}%</span>
        <span style="color: #94a3b8;">({{ $soc['pages_with_og'] }}/{{ $soc['total_pages'] }} {{ $lang === 'ro' ? 'pagini' : 'pages' }})</span>
    </div>
    <div style="background: #f1f5f9; border-radius: 4px; height: 8px; overflow: hidden;">
        <div style="background: {{ $socColor }}; width: {{ $soc['coverage_pct'] }}%; height: 100%; border-radius: 4px;"></div>
    </div>
</div>
@endif
