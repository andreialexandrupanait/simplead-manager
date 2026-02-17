{{--
    SVG donut/ring chart. Receives:
    $chartData - array from ReportChartService::generateDonutData()
    $centerText - large center text (e.g. total number)
    $centerSubtext - small subtext below center
    $size (default from chartData), $strokeWidth (default from chartData)
--}}
@php
    $size = $chartData['size'] ?? 150;
    $sw = $chartData['stroke_width'] ?? 20;
    $segments = $chartData['segments'] ?? [];
    $radius = $chartData['radius'] ?? 0;
    $cx = $chartData['cx'] ?? 0;
    $cy = $chartData['cy'] ?? 0;
    $circumference = $chartData['circumference'] ?? 0;
@endphp
@if(!empty($segments))
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 {{ $size }} {{ $size }}">
    {{-- Background ring --}}
    <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $radius }}" fill="none" stroke="#e5e7eb" stroke-width="{{ $sw }}"/>

    {{-- Segment arcs --}}
    @foreach($segments as $seg)
        <circle
            cx="{{ $cx }}"
            cy="{{ $cy }}"
            r="{{ $radius }}"
            fill="none"
            stroke="{{ $seg['color'] ?? '#3b82f6' }}"
            stroke-width="{{ $sw }}"
            stroke-dasharray="{{ $seg['dash_array'] }}"
            stroke-dashoffset="{{ $seg['dash_offset'] }}"
            transform="rotate(-90 {{ $cx }} {{ $cy }})"
        />
    @endforeach

    {{-- Center text --}}
    @if(isset($centerText))
        <text x="{{ $cx }}" y="{{ $cy + (isset($centerSubtext) ? -2 : 5) }}" text-anchor="middle" font-size="20" font-weight="bold" fill="#111827">{{ $centerText }}</text>
    @endif
    @if(isset($centerSubtext))
        <text x="{{ $cx }}" y="{{ $cy + 14 }}" text-anchor="middle" font-size="8" fill="#6b7280">{{ $centerSubtext }}</text>
    @endif
</svg>
@endif
