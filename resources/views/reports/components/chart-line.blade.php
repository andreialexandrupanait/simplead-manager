{{--
    SVG line chart. Receives:
    $points - array from ReportChartService: ['line_points', 'area_points', 'y_max']
    $primaryColor - line/fill color
    $areaColor - solid fill for area (default #dbeafe)
    $yLabels - array of Y-axis label strings (top to bottom)
    $xLabels - optional array of ['index' => int, 'label' => string] for X-axis
    $legendLabel - optional string label for chart legend
    $width (default 500), $height (default 220)
--}}
@php
    $w = $width ?? 500;
    $h = $height ?? 220;
    $pl = 40; // padding left
    $pb = 30; // padding bottom
    $chartH = $h - $pb - 10;
    $chartW = $w - $pl - 10;
    $linePoints = $points['line_points'] ?? '';
    $areaPoints = $points['area_points'] ?? '';
    $smoothLinePath = $points['smooth_line_path'] ?? '';
    $smoothAreaPath = $points['smooth_area_path'] ?? '';
    $areaFill = $areaColor ?? '#dbeafe';
@endphp
@if(!empty($linePoints))
{{-- Legend --}}
@if(isset($legendLabel) && $legendLabel)
    <div style="font-size: 8pt; color: #6b7280; margin-bottom: 4px;">
        <span style="display: inline-block; width: 12px; height: 3px; background: {{ $primaryColor }}; border-radius: 2px; vertical-align: middle; margin-right: 4px;"></span>
        {{ $legendLabel }}
    </div>
@endif
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

    {{-- Area fill --}}
    <polygon points="{{ $areaPoints }}" fill="{{ $areaFill }}"/>

    {{-- Data line --}}
    <polyline points="{{ $linePoints }}" fill="none" stroke="{{ $primaryColor }}" stroke-width="2"/>

    {{-- Data point dots --}}
    @php
        $pointPairs = array_filter(explode(' ', $linePoints));
        $dotInterval = count($pointPairs) > 15 ? 2 : 1;
    @endphp
    @foreach($pointPairs as $idx => $pair)
        @if($idx % $dotInterval === 0 || $idx === count($pointPairs) - 1)
            @php
                $coords = explode(',', $pair);
                $cx = $coords[0] ?? 0;
                $cy = $coords[1] ?? 0;
            @endphp
            <circle cx="{{ $cx }}" cy="{{ $cy }}" r="3" fill="{{ $primaryColor }}" stroke="#ffffff" stroke-width="1"/>
        @endif
    @endforeach

    {{-- X-axis labels --}}
    @if(isset($xLabels) && is_array($xLabels) && count($xLabels) > 0)
        @php
            $dataCount = count($pointPairs);
            $xStep = $dataCount > 1 ? $chartW / ($dataCount - 1) : $chartW;
        @endphp
        @foreach($xLabels as $xLabel)
            @php
                $xPos = $pl + ($xLabel['index'] * $xStep);
            @endphp
            <text x="{{ $xPos }}" y="{{ $h - 4 }}" font-size="8" fill="#9ca3af" text-anchor="middle">{{ $xLabel['label'] }}</text>
        @endforeach
    @endif
</svg>
@endif
