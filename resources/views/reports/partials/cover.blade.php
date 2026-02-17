@php
    $companyLogo = $branding['company_logo'] ?? null;
    $clientLogo = $branding['client_logo'] ?? null;
    $lang = $language ?? 'ro';
@endphp

<div class="cover-page">
    <div class="cover-content">
        {{-- Client logo or site name --}}
        @if($clientLogo && file_exists($clientLogo))
            <img src="{{ $clientLogo }}" class="cover-client-logo" alt="">
        @else
            <div class="cover-site-name">{{ $site->name }}</div>
        @endif

        {{-- Accent line --}}
        <div class="cover-accent-line"></div>

        {{-- Report title --}}
        <div class="cover-title">{{ __('report.cover_title', [], $lang) }}</div>

        {{-- Site URL --}}
        <div class="cover-url">{{ $site->url }}</div>

        {{-- Date range --}}
        <div class="cover-date">
            {{ $periodStart->format('d/m/Y') }} &mdash; {{ $periodEnd->format('d/m/Y') }}
        </div>

        {{-- Intro text --}}
        @if(!empty($introText))
            <div class="cover-intro">
                {{ $introText }}
            </div>
        @endif

        {{-- Section list --}}
        @if(isset($sections) && count($sections) > 0)
            <div class="cover-sections">
                @foreach($sections as $section)
                    @php $sectionLabel = __('report.section_label_' . $section, [], $lang); @endphp
                    @if($sectionLabel !== 'report.section_label_' . $section)
                        <div class="cover-sections-item">&#10003; {{ $sectionLabel }}</div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>

    {{-- Company logo at bottom --}}
    @if($companyLogo && file_exists($companyLogo))
        <img src="{{ $companyLogo }}" class="cover-company-logo" alt="">
    @endif
</div>
