<!DOCTYPE html>
<html lang="{{ $language ?? 'ro' }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
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

    {{-- Cover Page (full bleed, NO header/footer, includes intro text) --}}
    @include('reports.partials.cover')

    {{-- All content sections flow naturally in a single .page wrapper --}}
    <div class="page page-first">

        {{-- Executive Snapshot (replaces overview) --}}
        @if(in_array('overview', $sections) && isset($data['executive_snapshot']))
            <div class="section-block">
                <div class="header-spacer"></div>
                @include('reports.partials.executive-snapshot')
            </div>
        @endif

        {{-- Technical Stability (replaces uptime, absorbs security + database) --}}
        @if(in_array('uptime', $sections) && isset($data['uptime']))
            <div class="section-block section-break">
                <div class="header-spacer"></div>
                @include('reports.partials.technical-stability')
            </div>
        @endif

        {{-- Infrastructure (SSL, Domain, Email) --}}
        @if(($data['ssl'] ?? null) || ($data['domain'] ?? null) || ($data['email'] ?? null))
            <div class="section-block section-break">
                <div class="header-spacer"></div>
                @include('reports.partials.infrastructure')
            </div>
        @endif

        {{-- Updates --}}
        @if(in_array('updates', $sections) && isset($data['updates']))
            <div class="section-block section-break">
                <div class="header-spacer"></div>
                @include('reports.partials.updates')
            </div>
        @endif

        {{-- Backups --}}
        @if(in_array('backups', $sections) && isset($data['backups']))
            <div class="section-block section-break">
                <div class="header-spacer"></div>
                @include('reports.partials.backups')
            </div>
        @endif

        {{-- Analytics (single section, no analytics-2) --}}
        @if(in_array('analytics', $sections) && isset($data['analytics']) && $data['analytics'])
            <div class="section-block section-break">
                <div class="header-spacer"></div>
                @include('reports.partials.analytics-1')
            </div>
        @endif

        {{-- Search Console (single section, no search-console-2) --}}
        @if(in_array('search_console', $sections) && isset($data['search_console']) && $data['search_console'])
            <div class="section-block section-break">
                <div class="header-spacer"></div>
                @include('reports.partials.search-console-1')
            </div>
        @endif

        {{-- Performance --}}
        @if(in_array('performance', $sections) && isset($data['performance']) && $data['performance'])
            <div class="section-block section-break">
                <div class="header-spacer"></div>
                @include('reports.partials.performance')
            </div>
        @endif

        {{-- Recommendations (always shown when data exists) --}}
        @if(isset($data['recommendations']) && (
            count($data['recommendations']['technical'] ?? []) > 0 ||
            count($data['recommendations']['performance'] ?? []) > 0 ||
            count($data['recommendations']['seo'] ?? []) > 0
        ))
            <div class="section-block section-break">
                <div class="header-spacer"></div>
                @include('reports.partials.recommendations')
            </div>
        @endif

        {{-- Closing --}}
        <div class="section-block section-break">
            <div class="header-spacer"></div>
            @include('reports.partials.closing')
        </div>

        @include('reports.components.page-footer')
    </div>
</body>
</html>
