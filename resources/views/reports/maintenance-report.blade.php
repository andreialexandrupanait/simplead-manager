<!DOCTYPE html>
<html lang="{{ $language ?? 'ro' }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    @include('reports.styles')
</head>
<body>
    @php
        $primaryColor = $branding['primary_color'] ?? '#3b82f6';
        $companyLogo = $branding['company_logo'] ?? null;
        $companyName = $branding['company_name'] ?? 'SimpleAd';
        $clientLogo = $branding['client_logo'] ?? null;
        $lang = $language ?? 'ro';
    @endphp

    <div class="page">

        {{-- Executive Snapshot (first page, no header bar) --}}
        @if(in_array('overview', $sections) && isset($data['executive_snapshot']))
            <div class="section-block">
                @include('reports.partials.executive-snapshot')
            </div>
        @endif

        {{-- Technical Stability --}}
        @if(in_array('uptime', $sections) && isset($data['uptime']))
            <div class="section-block section-break">
                @include('reports.partials.page-header-bar')
                @include('reports.partials.technical-stability')
            </div>
        @endif

        {{-- Infrastructure (SSL, Domain, Email) — flows after Technical Stability --}}
        @php
            $hasVisibleInfra = (($data['ssl'] ?? null) && ($sectionOptions['infrastructure']['show_ssl'] ?? true))
                || (($data['domain'] ?? null) && ($sectionOptions['infrastructure']['show_domain'] ?? true))
                || (($data['email'] ?? null) && ($sectionOptions['infrastructure']['show_email'] ?? true));
        @endphp
        @if($hasVisibleInfra)
            <div class="section-block">
                @include('reports.partials.infrastructure')
            </div>
        @endif

        {{-- Updates --}}
        @if(in_array('updates', $sections) && isset($data['updates']))
            <div class="section-block section-break">
                @include('reports.partials.page-header-bar')
                @include('reports.partials.updates')
            </div>
        @endif

        {{-- Backups --}}
        @if(in_array('backups', $sections) && isset($data['backups']))
            <div class="section-block section-break">
                @include('reports.partials.page-header-bar')
                @include('reports.partials.backups')
            </div>
        @endif

        {{-- Analytics --}}
        @if(in_array('analytics', $sections) && isset($data['analytics']) && $data['analytics'])
            <div class="section-block section-break">
                @include('reports.partials.page-header-bar')
                @include('reports.partials.analytics-1')
            </div>
        @endif

        {{-- Search Console --}}
        @if(in_array('search_console', $sections) && isset($data['search_console']) && $data['search_console'])
            <div class="section-block section-break">
                @include('reports.partials.page-header-bar')
                @include('reports.partials.search-console-1')
            </div>
        @endif

        {{-- Performance --}}
        @if(in_array('performance', $sections) && isset($data['performance']) && $data['performance'])
            <div class="section-block section-break">
                @include('reports.partials.page-header-bar')
                @include('reports.partials.performance')
            </div>
        @endif

        {{-- Recommendations --}}
        @if(isset($data['recommendations']) && (
            count($data['recommendations']['technical'] ?? []) > 0 ||
            count($data['recommendations']['performance'] ?? []) > 0 ||
            count($data['recommendations']['seo'] ?? []) > 0
        ))
            <div class="section-block section-break">
                @include('reports.partials.page-header-bar')
                @include('reports.partials.recommendations')
            </div>
        @endif

    </div>
</body>
</html>
