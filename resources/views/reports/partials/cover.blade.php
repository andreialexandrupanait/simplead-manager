@php
    $lang = $language ?? 'ro';
    $primaryColor = $branding['primary_color'] ?? '#7C3AED';
@endphp

<div class="cover-page">
    {{-- Left panel: white background, company logo centered --}}
    <div class="cover-left">
        @if(!empty($logoBase64Original))
            <img src="{{ $logoBase64Original }}" class="cover-client-logo" alt="{{ $branding['company_name'] ?? 'SimpleAd' }}">
        @else
            <div class="cover-site-name">{{ $branding['company_name'] ?? 'SimpleAd' }}</div>
        @endif

        {{-- Client logo small at bottom-left --}}
        @if(!empty($clientLogoBase64))
            <div style="position: absolute; bottom: 12mm; left: 14mm;">
                <img src="{{ $clientLogoBase64 }}" style="max-height: 20mm; max-width: 40mm; object-fit: contain;" alt="">
            </div>
        @endif
    </div>

    {{-- Right panel: primary color background --}}
    <div class="cover-right" style="background: {{ $primaryColor }};">
        {{-- Generated date --}}
        <div class="cover-generated-label">
            {{ strtoupper(__('report.generated_at', [], $lang)) }}:
            {{ now()->format('d/m/Y') }}
        </div>

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

        {{-- Section list as pill badges --}}
        @if(isset($sections) && count($sections) > 0)
            <div class="cover-sections">
                @foreach($sections as $section)
                    @php $sectionLabel = __('report.section_label_' . $section, [], $lang); @endphp
                    @if($sectionLabel !== 'report.section_label_' . $section)
                        <div class="cover-sections-item">{{ $sectionLabel }}</div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</div>
