{{--
    SVG dual-line overlay chart. Receives:
    $line1, $line2 - arrays from generateLineChartPoints (each has line_points, area_points)
    $color1, $color2 - line colors
    $areaColor1 - solid fill for line1 area (default #dbeafe)
    $areaColor2 - solid fill for line2 area (default #d1fae5)
    $legend1, $legend2 - legend labels
    $yLabels - Y-axis labels (top to bottom)
    $xLabels - optional X-axis labels
    $width (default 500), $height (default 220)
--}}
@php
    $w = $width ?? 500;
    $h = $height ?? 220;
    $pl = 40;
    $pb = 30;
    $chartH = $h - $pb - 10;
    $chartW = $w - $pl - 10;
    $line1Points = $line1['line_points'] ?? '';
    $line1Area = $line1['area_points'] ?? '';
    $line2Points = $line2['line_points'] ?? '';
    $line2Area = $line2['area_points'] ?? '';
    $area1Fill = $areaColor1 ?? '#dbeafe';
    $area2Fill = $areaColor2 ?? '#d1fae5';
    $line2StrokeColor = $color2Light ?? '#6ee7b7';
@endphp
@if(!empty($line1Points) || !empty($line2Points))
{{-- Legend --}}
<div style="font-size: 8pt; color: #6b7280; margin-bottom: 4px;">
    @if(isset($legend1))
        <span style="display: inline-block; width: 12px; height: 3px; background: {{ $color1 ?? '#3b82f6' }}; border-radius: 2px; vertical-align: middle; margin-right: 4px;"></span>
        {{ $legend1 }}
    @endif
    @if(isset($legend2))
        <span style="display: inline-block; width: 12px; height: 3px; background: {{ $color2 ?? '#10b981' }}; border-radius: 2px; vertical-align: middle; margin-left: 12px; margin-right: 4px;"></span>
        {{ $legend2 }}
    @endif
</div>
<svg width="{{ $w }}" height="{{ $h }}" viewBox="0 0 {{ $w }} {{ $h }}" style="margin: 8px 0;">
    {{-- Y-axis labels + grid lines --}}
    @if(isset($yLabels) && is_array($yLabels))
        @php $labelCount = count($yLabels); @endphp
        @foreach($yLabels as $i => $yLabel)
            @php
                $yPos = $labelCount > 1
                    ? 5 + ($i / ($labelCount - 1)) * $chartH
                    : 5 + $chartH / 2;
            @endphp
            <text x="0" y="{{ $yPos + 3 }}" font-size="8" fill="#9ca3af">{{ $yLabel }}</text>
            <line x1="{{ $pl }}" y1="{{ $yPos }}" x2="{{ $w - 10 }}" y2="{{ $yPos }}" stroke="#d1d5db" stroke-width="0.5"/>
        @endforeach
    @endif

    {{-- Line 2 area fill (behind) --}}
    @if(!empty($line2Area))
        <polygon points="{{ $line2Area }}" fill="{{ $area2Fill }}"/>
    @endif

    {{-- Line 1 area fill --}}
    @if(!empty($line1Area))
        <polygon points="{{ $line1Area }}" fill="{{ $area1Fill }}"/>
    @endif

    {{-- Line 2 --}}
    @if(!empty($line2Points))
        <polyline points="{{ $line2Points }}" fill="none" stroke="{{ $line2StrokeColor }}" stroke-width="1.5"/>
    @endif

    {{-- Line 1 --}}
    @if(!empty($line1Points))
        <polyline points="{{ $line1Points }}" fill="none" stroke="{{ $color1 ?? '#3b82f6' }}" stroke-width="2"/>
    @endif

    {{-- X-axis labels --}}
    @if(isset($xLabels) && is_array($xLabels) && count($xLabels) > 0)
        @php
            $pointPairs = array_filter(explode(' ', $line1Points ?: $line2Points));
            $dataCount = count($pointPairs);
            $xStep = $dataCount > 1 ? $chartW / ($dataCount - 1) : $chartW;
        @endphp
        @foreach($xLabels as $xLabel)
            @php $xPos = $pl + ($xLabel['index'] * $xStep); @endphp
            <text x="{{ $xPos }}" y="{{ $h - 4 }}" font-size="8" fill="#9ca3af" text-anchor="middle">{{ $xLabel['label'] }}</text>
        @endforeach
    @endif
</svg>
@endif
