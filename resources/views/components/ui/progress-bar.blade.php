@props([
    'percent' => null,
    'color' => 'purple',
    'indeterminate' => false,
    'size' => 'md',
])

@php
$bgColor = match($color) {
    'purple' => 'bg-accent-500',
    'green' => 'bg-green-500',
    'red' => 'bg-red-500',
    'yellow' => 'bg-yellow-500',
    'blue' => 'bg-blue-500',
    default => 'bg-accent-500',
};
$trackColor = match($color) {
    'purple' => 'bg-accent-100',
    'green' => 'bg-green-100',
    'red' => 'bg-red-100',
    'yellow' => 'bg-yellow-100',
    'blue' => 'bg-blue-100',
    default => 'bg-accent-100',
};
$height = match($size) {
    'sm' => 'h-1.5',
    'md' => 'h-2',
    'lg' => 'h-3',
    default => 'h-2',
};
@endphp

<div {{ $attributes->merge(['class' => "w-full overflow-hidden rounded-full {$trackColor} {$height}"]) }}>
    @if($indeterminate)
        <div class="{{ $bgColor }} {{ $height }} w-1/3 animate-[progress-indeterminate_1.5s_infinite_ease-in-out]"></div>
    @else
        <div class="{{ $bgColor }} {{ $height }} rounded-full transition-all duration-300" style="width: {{ min(100, max(0, $percent ?? 0)) }}%"></div>
    @endif
</div>

@once
    @push('styles')
    <style>
        @keyframes progress-indeterminate {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(400%); }
        }
    </style>
    @endpush
@endonce
