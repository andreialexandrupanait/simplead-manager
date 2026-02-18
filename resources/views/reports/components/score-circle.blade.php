{{-- SVG circular gauge. Receives: $score (0-100 or null), $size (60|90, default 90) --}}
@php
    $score = $score ?? null;
    $size = $size ?? 90;

    if ($score !== null) {
        $colorKey = $score >= 90 ? 'green' : ($score >= 50 ? 'orange' : 'red');
    } else {
        $colorKey = 'na';
    }

    $palette = [
        'green'  => ['stroke' => '#10b981', 'text' => '#059669', 'track' => '#d1fae5'],
        'orange' => ['stroke' => '#f59e0b', 'text' => '#d97706', 'track' => '#fef3c7'],
        'red'    => ['stroke' => '#ef4444', 'text' => '#dc2626', 'track' => '#fee2e2'],
        'na'     => ['stroke' => '#94a3b8', 'text' => '#94a3b8', 'track' => '#e2e8f0'],
    ];
    $c = $palette[$colorKey];
    $pct = $score !== null ? min($score, 100) : 0;

    $strokeWidth = $size >= 80 ? 7 : 5;
    $radius = ($size / 2) - $strokeWidth - 2;
    $cx = $size / 2;
    $cy = $size / 2;
    $circumference = 2 * M_PI * $radius;
    $dashOffset = $circumference - ($pct / 100) * $circumference;

    $fontSize = $size >= 80 ? '22pt' : '16pt';
    $subSize = $size >= 80 ? '7pt' : '6pt';
@endphp
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 {{ $size }} {{ $size }}" style="display: inline-block;">
    {{-- Track ring --}}
    <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ round($radius, 1) }}"
        fill="none" stroke="{{ $c['track'] }}" stroke-width="{{ $strokeWidth }}"/>
    {{-- Value arc --}}
    <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ round($radius, 1) }}"
        fill="none" stroke="{{ $c['stroke'] }}" stroke-width="{{ $strokeWidth }}"
        stroke-linecap="round"
        stroke-dasharray="{{ round($circumference, 2) }}"
        stroke-dashoffset="{{ round($dashOffset, 2) }}"
        transform="rotate(-90 {{ $cx }} {{ $cy }})"/>
    {{-- Score text --}}
    <text x="{{ $cx }}" y="{{ $cy - 2 }}" text-anchor="middle" dominant-baseline="central"
        font-size="{{ $fontSize }}" font-weight="700" fill="{{ $c['text'] }}" font-family="Inter, sans-serif">
        {{ $score !== null ? $score : '—' }}
    </text>
    {{-- /100 label --}}
    <text x="{{ $cx }}" y="{{ $cy + ($size >= 80 ? 16 : 12) }}" text-anchor="middle"
        font-size="{{ $subSize }}" fill="#94a3b8" font-family="Inter, sans-serif">
        / 100
    </text>
</svg>
