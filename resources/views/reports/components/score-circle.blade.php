{{-- Score gauge widget. Receives: $score (0-100 or null), $size (60|90, default 90) --}}
@php
    $score = $score ?? null;
    $size = $size ?? 90;
    $isLg = $size >= 80;

    if ($score !== null) {
        $colorKey = $score >= 90 ? 'green' : ($score >= 50 ? 'orange' : 'red');
    } else {
        $colorKey = 'na';
    }

    $palette = [
        'green'  => ['fill' => '#10b981', 'text' => '#059669', 'bg' => '#ecfdf5'],
        'orange' => ['fill' => '#f59e0b', 'text' => '#d97706', 'bg' => '#fffbeb'],
        'red'    => ['fill' => '#ef4444', 'text' => '#dc2626', 'bg' => '#fef2f2'],
        'na'     => ['fill' => '#94a3b8', 'text' => '#94a3b8', 'bg' => '#f8fafc'],
    ];
    $c = $palette[$colorKey];
    $pct = $score !== null ? min($score, 100) : 0;

    $numSize   = $isLg ? '28pt' : '20pt';
    $labelSize = $isLg ? '8pt'  : '7pt';
    $barH      = $isLg ? '6px'  : '4px';
    $barW      = $isLg ? '80%' : '60px';
    $panelPad  = $isLg ? '14px 20px 12px' : '10px 14px 8px';
@endphp
<div style="text-align: center; background: {{ $c['bg'] }}; border: 1px solid {{ $c['fill'] }}20; padding: {{ $panelPad }}; display: inline-block;">
    <div style="font-size: {{ $numSize }}; font-weight: 700; color: {{ $c['text'] }}; line-height: 1.1;">
        {{ $score !== null ? $score : '—' }}
    </div>
    <div style="font-size: {{ $labelSize }}; color: #94a3b8; margin-bottom: 6px;">/ 100</div>
    <div style="background: #e2e8f0; height: {{ $barH }}; width: {{ $barW }}; margin: 0 auto;">
        <div style="background: {{ $c['fill'] }}; height: {{ $barH }}; width: {{ $pct }}%;"></div>
    </div>
</div>
