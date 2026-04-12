@props([
    'label',
    'value',
    'icon' => null,
    'color' => 'purple',
])

@php
$bgColor = match($color) {
    'purple' => 'bg-accent-50',
    'green' => 'bg-green-50',
    'red' => 'bg-red-50',
    'yellow' => 'bg-yellow-50',
    'orange' => 'bg-orange-50',
    'blue' => 'bg-blue-50',
    'gray' => 'bg-gray-50',
    default => 'bg-accent-50',
};
$iconColor = match($color) {
    'purple' => 'text-accent-600',
    'green' => 'text-green-600',
    'red' => 'text-red-600',
    'yellow' => 'text-yellow-600',
    'orange' => 'text-orange-600',
    'blue' => 'text-blue-600',
    'gray' => 'text-gray-600',
    default => 'text-accent-600',
};
@endphp

<x-ui.card class="!p-4" {{ $attributes }}>
    <div class="flex items-center gap-3">
        @if($icon)
            <div class="flex h-10 w-10 items-center justify-center rounded-lg {{ $bgColor }}">
                <x-dynamic-component :component="'icons.' . $icon" class="h-5 w-5 {{ $iconColor }}" />
            </div>
        @endif
        <div>
            <p class="text-2xl font-bold text-gray-900">{{ $value }}</p>
            <p class="text-xs text-gray-500">{{ $label }}</p>
        </div>
    </div>
</x-ui.card>
