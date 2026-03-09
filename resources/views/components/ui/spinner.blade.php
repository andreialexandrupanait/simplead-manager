@props([
    'size' => 'sm',
])

@php
$sizeClass = match($size) {
    'xs' => 'h-3 w-3',
    'sm' => 'h-4 w-4',
    'md' => 'h-5 w-5',
    'lg' => 'h-6 w-6',
    default => 'h-4 w-4',
};
@endphp

<svg {{ $attributes->merge(['class' => "animate-spin {$sizeClass}"]) }} fill="none" viewBox="0 0 24 24">
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
</svg>
