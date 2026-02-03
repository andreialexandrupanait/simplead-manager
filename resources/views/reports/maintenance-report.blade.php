<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    @include('reports.styles')
</head>
<body>
    @php
        $sections = $template->sections ?? [];
    @endphp

    {{-- Running Header (appears on pages 2+) --}}
    <div class="running-header">
        <table>
            <tr>
                <td style="text-align: left;">
                    @if($template->company_logo_path && file_exists(storage_path('app/' . $template->company_logo_path)))
                        <img src="{{ storage_path('app/' . $template->company_logo_path) }}" class="header-logo" alt="">
                    @else
                        <span style="font-weight: 600; color: #1e1b4b;">{{ $template->company_name ?? 'SimpleAd' }}</span>
                    @endif
                </td>
                <td class="header-title">
                    Raport {{ $site->name }} &bull; {{ $periodStart->format('M Y') }}
                </td>
            </tr>
        </table>
    </div>

    {{-- Running Footer (appears on pages 2+) --}}
    <div class="running-footer">
        {{ $template->company_name ?? 'SimpleAd' }}
    </div>

    {{-- Cover Page --}}
    <div class="page">
        @include('reports.partials.cover')
    </div>

    {{-- Intro Page --}}
    @if($template->intro_text)
        <div class="page">
            @include('reports.partials.intro')
        </div>
    @endif

    {{-- Overview --}}
    @if(in_array('overview', $sections) && isset($data['overview']))
        <div class="page">
            @include('reports.partials.overview')
        </div>
    @endif

    {{-- Updates --}}
    @if(in_array('updates', $sections) && isset($data['updates']))
        <div class="page">
            @include('reports.partials.updates')
        </div>
    @endif

    {{-- Uptime --}}
    @if(in_array('uptime', $sections) && isset($data['uptime']) && ($data['uptime']['available'] ?? false))
        <div class="page">
            @include('reports.partials.uptime')
        </div>
    @endif

    {{-- Backups --}}
    @if(in_array('backups', $sections) && isset($data['backups']))
        <div class="page">
            @include('reports.partials.backups')
        </div>
    @endif

    {{-- Analytics --}}
    @if(in_array('analytics', $sections) && isset($data['analytics']) && $data['analytics'])
        <div class="page">
            @include('reports.partials.analytics-1')
        </div>
        <div class="page">
            @include('reports.partials.analytics-2')
        </div>
    @endif

    {{-- Search Console --}}
    @if(in_array('search_console', $sections) && isset($data['search_console']) && $data['search_console'])
        <div class="page">
            @include('reports.partials.search-console-1')
        </div>
        <div class="page">
            @include('reports.partials.search-console-2')
        </div>
    @endif

    {{-- Performance --}}
    @if(in_array('performance', $sections) && isset($data['performance']) && $data['performance'])
        <div class="page">
            @include('reports.partials.performance')
        </div>
    @endif

    {{-- Links --}}
    @if(in_array('links', $sections) && isset($data['links']) && $data['links'])
        <div class="page">
            @include('reports.partials.links')
        </div>
    @endif

    {{-- Thank You Page --}}
    <div class="page">
        <div class="thankyou-page">
            <div class="thankyou-title">Mulțumim pentru<br>colaborare!</div>
            <div class="thankyou-divider"></div>
            <div class="thankyou-text">
                @if($template->closing_text)
                    {!! nl2br(e($template->closing_text)) !!}
                @else
                    Vă mulțumim pentru încrederea acordată. Dacă aveți întrebări despre acest raport sau doriți să discutăm despre oportunități de optimizare, nu ezitați să ne contactați.
                @endif
            </div>
            @if($template->company_name)
                <div class="thankyou-company">{{ $template->company_name }}</div>
            @endif
            @if($template->company_website)
                <div class="thankyou-website">{{ $template->company_website }}</div>
            @endif
        </div>
    </div>

    {{-- Final Branding Page --}}
    <div class="page">
        <div class="final-page">
            @if($template->company_logo_path && file_exists(storage_path('app/' . $template->company_logo_path)))
                <img src="{{ storage_path('app/' . $template->company_logo_path) }}" class="final-logo" alt="">
            @else
                <div class="final-company">{{ $template->company_name ?? 'SimpleAd' }}</div>
            @endif
            <div class="final-subtitle">GRAFICĂ & MARKETING DIGITAL</div>
        </div>
    </div>
</body>
</html>
