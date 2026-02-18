@php
    $lang = $language ?? 'ro';
@endphp

<div class="cover-page">
    <div class="cover-content">
        {{-- Company logo (white version for dark background) --}}
        @if(!empty($logoBase64White))
            <img src="{{ $logoBase64White }}" class="cover-client-logo" alt="">
        @else
            <div class="cover-site-name">{{ $branding['company_name'] ?? 'SimpleAd' }}</div>
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

        {{-- Section list as pill badges --}}
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
</div>
