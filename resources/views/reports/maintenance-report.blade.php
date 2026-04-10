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
        $primaryColor = $branding['primary_color'] ?? '#7C3AED';
        $companyLogo = $branding['company_logo'] ?? null;
        $companyName = $branding['company_name'] ?? 'SimpleAd';
        $clientLogo = $branding['client_logo'] ?? null;
        $lang = $language ?? 'ro';
    @endphp

    @php $sectionNumber = 0; @endphp

    {{-- Executive Summary (always first when overview is enabled) --}}
    @if(in_array('overview', $sections) && isset($data['executive_snapshot']))
        @php $sectionNumber++; @endphp
        <div class="report-section">
            @include('reports.partials.overview')
        </div>
    @endif

    {{-- Technical Stability --}}
    @if(in_array('uptime', $sections) && isset($data['uptime']))
        @php $sectionNumber++; @endphp
        <div class="report-section">
            @include('reports.partials.technical-stability')
        </div>
    @endif

    {{-- Infrastructure (SSL, Domain, Email) --}}
    @php
        $hasVisibleInfra = (($data['ssl'] ?? null) && ($sectionOptions['infrastructure']['show_ssl'] ?? true))
            || (($data['domain'] ?? null) && ($sectionOptions['infrastructure']['show_domain'] ?? true))
            || (($data['email'] ?? null) && ($sectionOptions['infrastructure']['show_email'] ?? true));
    @endphp
    @if($hasVisibleInfra && in_array('infrastructure', $sections))
        @php $sectionNumber++; @endphp
        <div class="report-section">
            @include('reports.partials.infrastructure')
        </div>
    @endif

    {{-- Updates --}}
    @if(in_array('updates', $sections) && isset($data['updates']))
        @php $sectionNumber++; @endphp
        <div class="report-section">
            @include('reports.partials.updates')
        </div>
    @endif

    {{-- Backups --}}
    @if(in_array('backups', $sections) && isset($data['backups']))
        @php $sectionNumber++; @endphp
        <div class="report-section">
            @include('reports.partials.backups')
        </div>
    @endif

    {{-- Analytics --}}
    @if(in_array('analytics', $sections) && isset($data['analytics']) && $data['analytics'])
        @php $sectionNumber++; @endphp
        <div class="report-section">
            @include('reports.partials.analytics-1')
        </div>
    @endif

    {{-- Search Console --}}
    @if(in_array('search_console', $sections) && isset($data['search_console']) && $data['search_console'])
        @php $sectionNumber++; @endphp
        <div class="report-section">
            @include('reports.partials.search-console-1')
        </div>
    @endif

    {{-- SEO --}}
    @if(in_array('seo', $sections) && isset($data['seo']) && $data['seo'])
        @php $sectionNumber++; @endphp
        <div class="report-section">
            @include('reports.partials.seo')
        </div>
    @endif

    {{-- Performance --}}
    @if(in_array('performance', $sections) && isset($data['performance']) && $data['performance'])
        @php $sectionNumber++; @endphp
        <div class="report-section">
            @include('reports.partials.performance')
        </div>
    @endif

    {{-- Plugin & Theme Inventory --}}
    @if(in_array('plugin_inventory', $sections) && isset($data['plugin_inventory']))
        @php $sectionNumber++; @endphp
        <div class="report-section">
            @include('reports.partials.plugin-inventory')
        </div>
    @endif

    {{-- Database Health --}}
    @if(in_array('database_health', $sections) && isset($data['database_health']) && $data['database_health'])
        @php $sectionNumber++; @endphp
        <div class="report-section">
            @include('reports.partials.database-health')
        </div>
    @endif

    {{-- Cloudflare / CDN --}}
    @if(in_array('cloudflare', $sections) && isset($data['cloudflare']) && $data['cloudflare'])
        @php $sectionNumber++; @endphp
        <div class="report-section">
            @include('reports.partials.cloudflare')
        </div>
    @endif

    {{-- WordPress Users --}}
    @if(in_array('wp_users', $sections) && isset($data['wp_users']) && $data['wp_users'])
        @php $sectionNumber++; @endphp
        <div class="report-section">
            @include('reports.partials.wp-users')
        </div>
    @endif

    {{-- Security Checks --}}
    @if(in_array('security_checks', $sections) && isset($data['security_checks']) && $data['security_checks'])
        @php $sectionNumber++; @endphp
        <div class="report-section">
            @include('reports.partials.security-checks')
        </div>
    @endif

    {{-- Recommendations --}}
    @if(in_array('recommendations', $sections))
        @php $sectionNumber++; @endphp
        <div class="report-section">
            @include('reports.partials.recommendations')
        </div>
    @endif

</body>
</html>
