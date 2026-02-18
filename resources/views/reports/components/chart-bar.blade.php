{{--
    SVG vertical bar chart. Receives:
    $chartData - array from ReportChartService::generateBarChartData()
    $primaryColor - fallback bar color
    $width (default from chartData), $height (default from chartData)
--}}
@php
    $w = $chartData['svg_width'] ?? 500;
    $h = $chartData['svg_height'] ?? 180;
    $bars = $chartData['bars'] ?? [];
    $yLabels = $chartData['y_labels'] ?? [];
    $area = $chartData['chart_area'] ?? [];
@endphp
@if(!empty($bars))
<svg width="100%" height="{{ $h }}" viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="xMidYMid meet" style="margin: 8px 0;">
    {{-- Y-axis labels + grid lines --}}
    @if(!empty($yLabels) && !empty($area))
        @php $labelCount = count($yLabels); @endphp
        @foreach($yLabels as $i => $yLabel)
            @php
                $yPos = $labelCount > 1
                    ? $area['y'] + ($i / ($labelCount - 1)) * $area['height']
                    : $area['y'] + $area['height'] / 2;
            @endphp
            <text x="0" y="{{ $yPos + 3 }}" font-size="8" fill="#9ca3af" font-family="Inter, sans-serif">{{ $yLabel }}</text>
            <line x1="{{ $area['x'] }}" y1="{{ $yPos }}" x2="{{ $w - 10 }}" y2="{{ $yPos }}" stroke="#e5e7eb" stroke-width="0.5"/>
        @endforeach
    @endif

    {{-- Bars --}}
    @foreach($bars as $bar)
        <rect
            x="{{ $bar['x'] }}"
            y="{{ $bar['y'] }}"
            width="{{ $bar['bar_width'] }}"
            height="{{ $bar['bar_height'] }}"
            rx="2"
            fill="{{ $bar['color'] ?? $primaryColor }}"
        />
        {{-- Value above bar --}}
        <text
            x="{{ $bar['x'] + $bar['bar_width'] / 2 }}"
            y="{{ $bar['y'] - 4 }}"
            font-size="8"
            font-weight="bold"
            fill="#374151"
            text-anchor="middle"
        >{{ $bar['value'] > 0 ? round($bar['value']) : '' }}</text>
        {{-- X-axis label below --}}
        @if(!empty($area))
            <text
                x="{{ $bar['x'] + $bar['bar_width'] / 2 }}"
                y="{{ $area['bottom'] + 14 }}"
                font-size="7"
                fill="#9ca3af"
                text-anchor="middle"
            >{{ $bar['label'] ?? '' }}</text>
        @endif
    @endforeach

    {{-- Baseline --}}
    @if(!empty($area))
        <line x1="{{ $area['x'] }}" y1="{{ $area['bottom'] }}" x2="{{ $w - 10 }}" y2="{{ $area['bottom'] }}" stroke="#d1d5db" stroke-width="0.5"/>
    @endif
</svg>
@endif
