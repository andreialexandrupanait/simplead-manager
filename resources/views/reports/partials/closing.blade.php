@php
    $companyLogo = $branding['company_logo'] ?? null;
    $companyName = $branding['company_name'] ?? 'SimpleAd';
    $lang = $language ?? 'ro';
@endphp

<div class="closing-page">
    <div class="closing-content">
        <div class="closing-title">
            {{ __('report.closing_thanks', [], $lang) }}
        </div>

        <div class="closing-divider"></div>

        <div class="closing-text">
            {{ __('report.closing_text', [], $lang) }}
        </div>

        @if(!empty($logoBase64White))
            {{-- Show logo (use filter to make it dark for white background) --}}
            <img src="{{ $logoBase64White }}" class="closing-logo" alt="" style="filter: brightness(0); opacity: 0.6;">
        @else
            <div class="closing-company">{{ $companyName }}</div>
        @endif

        @if($branding['company_website'] ?? null)
            <div class="closing-website">
                {{ $branding['company_website'] }}
            </div>
        @endif
    </div>

    <div class="closing-copyright">
        &copy; {{ date('Y') }} {{ $companyName }}. {{ __('report.closing_copyright', [], $lang) }}
    </div>
</div>
