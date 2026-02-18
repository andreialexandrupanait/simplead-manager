@php
    $companyLogo = $branding['company_logo'] ?? null;
    $companyName = $branding['company_name'] ?? 'SimpleAd';
    $lang = $language ?? 'ro';
@endphp

<div class="closing-page">
    <div class="closing-content">
        <div class="closing-title">
            {!! nl2br(e(__('report.closing_title', [], $lang))) !!}
        </div>

        <div class="closing-divider"></div>

        <div class="closing-text">
            {!! nl2br(e($closingText)) !!}
        </div>

        @if(!empty($logoBase64White))
            <img src="{{ $logoBase64White }}" class="closing-logo" alt="">
        @elseif($companyLogo && file_exists($companyLogo))
            <img src="{{ $companyLogo }}" class="closing-logo" alt="">
        @else
            <div class="closing-company">{{ $companyName }}</div>
        @endif

        @if($branding['company_website'] ?? null)
            <div class="closing-website">
                {{ $branding['company_website'] }}
            </div>
        @endif
    </div>
</div>
