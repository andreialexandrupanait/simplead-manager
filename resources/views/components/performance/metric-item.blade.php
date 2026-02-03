@props(['label' => '', 'value' => '—', 'color' => 'gray'])

@php
$dotColor = match($color) {
    'green' => 'bg-green-500',
    'orange' => 'bg-yellow-500',
    'red' => 'bg-red-500',
    default => 'bg-gray-400',
};
$textColor = match($color) {
    'green' => 'text-green-700',
    'orange' => 'text-yellow-700',
    'red' => 'text-red-700',
    default => 'text-gray-500',
};
@endphp

<div class="flex items-center justify-between py-1.5">
    <div class="flex items-center gap-2">
        <span class="h-2 w-2 rounded-full {{ $dotColor }}"></span>
        <span class="text-sm text-gray-600">{{ $label }}</span>
    </div>
    <span class="text-sm font-medium {{ $textColor }}">{{ $value }}</span>
</div>
