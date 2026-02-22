@props(['score' => null, 'label' => '', 'size' => 'lg'])

@php
$dimensions = match($size) {
    'sm' => ['size' => 64, 'stroke' => 6, 'text' => 'text-sm', 'label' => 'text-xs'],
    'md' => ['size' => 96, 'stroke' => 8, 'text' => 'text-lg', 'label' => 'text-xs'],
    'lg' => ['size' => 128, 'stroke' => 10, 'text' => 'text-2xl', 'label' => 'text-sm'],
    default => ['size' => 96, 'stroke' => 8, 'text' => 'text-lg', 'label' => 'text-xs'],
};

$radius = ($dimensions['size'] - $dimensions['stroke']) / 2;
$circumference = 2 * M_PI * $radius;
$percent = $score !== null ? min(100, max(0, $score)) : 0;
$offset = $circumference - ($percent / 100) * $circumference;
$center = $dimensions['size'] / 2;

if ($score === null) {
    $strokeColor = '#D1D5DB';
    $textColor = 'text-gray-400';
} elseif ($score >= 90) {
    $strokeColor = '#22C55E';
    $textColor = 'text-green-600';
} elseif ($score >= 50) {
    $strokeColor = '#F59E0B';
    $textColor = 'text-yellow-600';
} else {
    $strokeColor = '#EF4444';
    $textColor = 'text-red-600';
}
@endphp

<div class="inline-flex flex-col items-center gap-2">
    <div class="relative inline-flex items-center justify-center">
        <svg width="{{ $dimensions['size'] }}" height="{{ $dimensions['size'] }}" class="-rotate-90">
            {{-- Background ring --}}
            <circle
                cx="{{ $center }}"
                cy="{{ $center }}"
                r="{{ $radius }}"
                fill="none"
                stroke="#E5E7EB"
                stroke-width="{{ $dimensions['stroke'] }}"
            />
            {{-- Progress ring --}}
            @if($score !== null)
                <circle
                    cx="{{ $center }}"
                    cy="{{ $center }}"
                    r="{{ $radius }}"
                    fill="none"
                    stroke="{{ $strokeColor }}"
                    stroke-width="{{ $dimensions['stroke'] }}"
                    stroke-dasharray="{{ $circumference }}"
                    stroke-dashoffset="{{ $offset }}"
                    stroke-linecap="round"
                />
            @endif
        </svg>
        <span class="absolute font-bold {{ $dimensions['text'] }} {{ $textColor }}">
            {{ $score ?? '—' }}
        </span>
    </div>
    @if($label)
        <span class="font-medium text-gray-600 {{ $dimensions['label'] }}">{{ $label }}</span>
    @endif
</div>
