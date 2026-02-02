@php
$dimensions = match($size) {
    'sm' => ['size' => 64, 'stroke' => 6, 'text' => 'text-sm'],
    'lg' => ['size' => 128, 'stroke' => 10, 'text' => 'text-2xl'],
    default => ['size' => 96, 'stroke' => 8, 'text' => 'text-lg'],
};

$radius = ($dimensions['size'] - $dimensions['stroke']) / 2;
$circumference = 2 * M_PI * $radius;
$offset = $circumference - ($score / 100) * $circumference;
$center = $dimensions['size'] / 2;

$colorClasses = match($this->color) {
    'green' => ['stroke' => 'text-green-500', 'number' => 'text-green-600'],
    'yellow' => ['stroke' => 'text-yellow-500', 'number' => 'text-yellow-600'],
    'red' => ['stroke' => 'text-red-500', 'number' => 'text-red-600'],
};
@endphp

<div class="inline-flex items-center justify-center">
    <svg width="{{ $dimensions['size'] }}" height="{{ $dimensions['size'] }}" class="-rotate-90">
        {{-- Background ring --}}
        <circle
            cx="{{ $center }}"
            cy="{{ $center }}"
            r="{{ $radius }}"
            fill="none"
            stroke="currentColor"
            stroke-width="{{ $dimensions['stroke'] }}"
            class="text-gray-200"
        />
        {{-- Progress ring --}}
        <circle
            cx="{{ $center }}"
            cy="{{ $center }}"
            r="{{ $radius }}"
            fill="none"
            stroke="currentColor"
            stroke-width="{{ $dimensions['stroke'] }}"
            stroke-dasharray="{{ $circumference }}"
            stroke-dashoffset="{{ $offset }}"
            stroke-linecap="round"
            class="{{ $colorClasses['stroke'] }}"
        />
    </svg>
    <span class="absolute font-semibold {{ $dimensions['text'] }} {{ $colorClasses['number'] }}">
        {{ $score }}
    </span>
</div>
