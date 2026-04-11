<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    @include('reports.styles', ['primaryColor' => '#7C3AED'])
    <style>
        .seo-score-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 700;
            color: var(--white);
        }
        .seo-score-green { background: var(--green-500); }
        .seo-score-amber { background: var(--amber-500); }
        .seo-score-red { background: var(--red-500); }
        .severity-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: var(--radius-pill);
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .severity-critical { background: var(--red-50); color: var(--red-500); }
        .severity-high { background: #fff7ed; color: #ea580c; }
        .severity-medium { background: var(--amber-50); color: var(--amber-500); }
        .severity-low { background: var(--blue-100); color: var(--blue-600); }
        .cwv-metric { text-align: center; padding: 12px; }
        .cwv-value { font-size: 24px; font-weight: 700; }
        .cwv-label { font-size: 12px; color: var(--slate-500); margin-top: 4px; }
    </style>
</head>
<body>
    <div class="section-top-bar" style="background: var(--primary); color: white; padding: 16px 24px; margin: -14px -12px 24px -12px;">
        <h1 style="margin: 0; font-size: 22px; color: white;">SEO Report — {{ $site->name }}</h1>
        <p style="margin: 4px 0 0; font-size: 13px; opacity: 0.8;">
            {{ $period_start->format('M d, Y') }} — {{ $period_end->format('M d, Y') }}
            &middot; Generated {{ $generated_at->format('M d, Y H:i') }}
        </p>
    </div>

    {{-- Score Overview --}}
    @if($audit)
    <div class="card" style="display: flex; align-items: center; gap: 24px; margin-bottom: 20px;">
        <div class="seo-score-circle seo-score-{{ $audit['score'] >= 80 ? 'green' : ($audit['score'] >= 50 ? 'amber' : 'red') }}">
            {{ $audit['score'] }}
        </div>
        <div>
            <h2 style="margin: 0; font-size: 18px;">{{ $audit['score_label'] }}</h2>
            <p style="margin: 4px 0 0; color: var(--slate-500); font-size: 13px;">
                {{ $audit['total_issues'] }} issue(s) found &middot;
                {{ $audit['critical_count'] }} critical, {{ $audit['high_count'] }} high
            </p>
        </div>
    </div>
    @endif

    {{-- Core Web Vitals --}}
    @if($cwv)
    <div class="card" style="margin-bottom: 20px;">
        <h3 style="margin: 0 0 12px; font-size: 15px;">Core Web Vitals (Mobile)</h3>
        <div style="display: flex; gap: 16px;">
            @if($cwv['lcp'] !== null)
            <div class="cwv-metric" style="flex: 1; background: var(--slate-50); border-radius: var(--radius);">
                <div class="cwv-value" style="color: {{ $cwv['lcp'] <= 2.5 ? 'var(--green-500)' : ($cwv['lcp'] <= 4.0 ? 'var(--amber-500)' : 'var(--red-500)') }}">{{ round($cwv['lcp'], 1) }}s</div>
                <div class="cwv-label">LCP</div>
            </div>
            @endif
            @if($cwv['cls'] !== null)
            <div class="cwv-metric" style="flex: 1; background: var(--slate-50); border-radius: var(--radius);">
                <div class="cwv-value" style="color: {{ $cwv['cls'] <= 0.1 ? 'var(--green-500)' : ($cwv['cls'] <= 0.25 ? 'var(--amber-500)' : 'var(--red-500)') }}">{{ number_format($cwv['cls'], 3) }}</div>
                <div class="cwv-label">CLS</div>
            </div>
            @endif
            @if($cwv['inp'] !== null)
            <div class="cwv-metric" style="flex: 1; background: var(--slate-50); border-radius: var(--radius);">
                <div class="cwv-value" style="color: {{ $cwv['inp'] <= 200 ? 'var(--green-500)' : ($cwv['inp'] <= 500 ? 'var(--amber-500)' : 'var(--red-500)') }}">{{ round($cwv['inp']) }}ms</div>
                <div class="cwv-label">INP</div>
            </div>
            @endif
            @if($cwv['performance_score'] !== null)
            <div class="cwv-metric" style="flex: 1; background: var(--slate-50); border-radius: var(--radius);">
                <div class="cwv-value" style="color: {{ $cwv['performance_score'] >= 90 ? 'var(--green-500)' : ($cwv['performance_score'] >= 50 ? 'var(--amber-500)' : 'var(--red-500)') }}">{{ $cwv['performance_score'] }}</div>
                <div class="cwv-label">Perf Score</div>
            </div>
            @endif
        </div>
    </div>
    @endif

    {{-- Top Issues --}}
    @if(!empty($issues))
    <div class="card" style="margin-bottom: 20px;">
        <h3 style="margin: 0 0 12px; font-size: 15px;">Top Issues</h3>
        <table class="data-table" style="width: 100%; font-size: 12px;">
            <thead>
                <tr>
                    <th style="width: 80px;">Severity</th>
                    <th>Issue</th>
                    <th style="width: 120px;">Category</th>
                </tr>
            </thead>
            <tbody>
                @foreach(array_slice($issues, 0, 15) as $issue)
                <tr>
                    <td><span class="severity-badge severity-{{ $issue['severity'] }}">{{ $issue['severity'] }}</span></td>
                    <td>{{ $issue['title'] }}</td>
                    <td>{{ $issue['category'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- Keywords --}}
    @if(!empty($keywords))
    <div class="card" style="margin-bottom: 20px;">
        <h3 style="margin: 0 0 12px; font-size: 15px;">Top Keywords ({{ count($keywords) }})</h3>
        <table class="data-table" style="width: 100%; font-size: 12px;">
            <thead>
                <tr>
                    <th>Keyword</th>
                    <th style="text-align: right; width: 70px;">Position</th>
                    <th style="text-align: right; width: 60px;">Clicks</th>
                    <th style="text-align: right; width: 80px;">Impressions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($keywords as $kw)
                <tr>
                    <td>{{ $kw['keyword'] }}@if($kw['is_brand']) <span style="color: var(--primary); font-size: 10px;">BRAND</span>@endif</td>
                    <td style="text-align: right;">{{ $kw['position'] ?? '—' }}</td>
                    <td style="text-align: right;">{{ number_format($kw['clicks']) }}</td>
                    <td style="text-align: right;">{{ number_format($kw['impressions']) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- Backlinks --}}
    @if($backlinks)
    <div class="card" style="margin-bottom: 20px;">
        <h3 style="margin: 0 0 12px; font-size: 15px;">Backlink Profile</h3>
        <div style="display: flex; gap: 16px;">
            <div style="flex: 1; text-align: center; padding: 12px; background: var(--slate-50); border-radius: var(--radius);">
                <div style="font-size: 24px; font-weight: 700;">{{ number_format($backlinks['total']) }}</div>
                <div style="font-size: 12px; color: var(--slate-500);">Total Backlinks</div>
            </div>
            <div style="flex: 1; text-align: center; padding: 12px; background: var(--slate-50); border-radius: var(--radius);">
                <div style="font-size: 24px; font-weight: 700;">{{ number_format($backlinks['referring_domains']) }}</div>
                <div style="font-size: 12px; color: var(--slate-500);">Referring Domains</div>
            </div>
            <div style="flex: 1; text-align: center; padding: 12px; background: var(--green-50); border-radius: var(--radius);">
                <div style="font-size: 24px; font-weight: 700; color: var(--green-500);">+{{ number_format($backlinks['new']) }}</div>
                <div style="font-size: 12px; color: var(--slate-500);">New</div>
            </div>
            <div style="flex: 1; text-align: center; padding: 12px; background: var(--red-50); border-radius: var(--radius);">
                <div style="font-size: 24px; font-weight: 700; color: var(--red-500);">-{{ number_format($backlinks['lost']) }}</div>
                <div style="font-size: 12px; color: var(--slate-500);">Lost</div>
            </div>
        </div>
    </div>
    @endif

    {{-- Content Health --}}
    <div class="card" style="margin-bottom: 20px;">
        <h3 style="margin: 0 0 12px; font-size: 15px;">Content Health</h3>
        <div style="display: flex; gap: 16px;">
            <div style="flex: 1; padding: 12px; background: var(--slate-50); border-radius: var(--radius); text-align: center;">
                <div style="font-size: 24px; font-weight: 700;">{{ $cannibalization_count }}</div>
                <div style="font-size: 12px; color: var(--slate-500);">Cannibalized Keywords</div>
            </div>
            <div style="flex: 1; padding: 12px; background: var(--slate-50); border-radius: var(--radius); text-align: center;">
                <div style="font-size: 24px; font-weight: 700;">{{ $content_gaps_count }}</div>
                <div style="font-size: 12px; color: var(--slate-500);">Content Gap Opportunities</div>
            </div>
            <div style="flex: 1; padding: 12px; background: var(--slate-50); border-radius: var(--radius); text-align: center;">
                <div style="font-size: 24px; font-weight: 700;">{{ $zero_traffic_pages_count }}</div>
                <div style="font-size: 12px; color: var(--slate-500);">Zero Traffic Pages</div>
            </div>
        </div>
    </div>

</body>
</html>
