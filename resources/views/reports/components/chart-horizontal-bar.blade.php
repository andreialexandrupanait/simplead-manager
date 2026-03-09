{{--
    SVG horizontal bar chart. Receives:
    $chartData - array from ReportChartService::generateHorizontalBarData()
    $primaryColor - fallback bar color
--}}
@php
    $w = $chartData['svg_width'] ?? 500;
    $h = $chartData['svg_height'] ?? 100;
    $bars = $chartData['bars'] ?? [];
    $labelW = $chartData['label_width'] ?? 120;
@endphp
@if(!empty($bars))
<svg width="100%" viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="xMinYMin meet" style="margin: 8px 0;">
    @foreach($bars as $bar)
        {{-- Label text (right-aligned to label area) --}}
        <text
            x="{{ $labelW - 8 }}"
            y="{{ $bar['text_y'] }}"
            font-size="9"
            fill="#374151"
            text-anchor="end"
            font-family="Inter, sans-serif"
        >{{ $bar['label'] ?? '' }}</text>

        {{-- Horizontal bar --}}
        <rect
            x="{{ $bar['x'] }}"
            y="{{ $bar['y'] }}"
            width="{{ $bar['bar_width'] }}"
            height="{{ $bar['bar_height'] }}"
            rx="3"
            fill="{{ $bar['color'] ?? $primaryColor }}"
            fill-opacity="0.85"
        />

        {{-- Value removed for cleaner layout --}}
    @endforeach
</svg>
@endif
