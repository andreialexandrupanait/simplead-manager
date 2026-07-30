@props([
    'icon',
    'status' => 'na',
    'label' => null,
])

@php
    $colorMap = [
        'ok' => 'text-green-500',
        'warning' => 'text-amber-500',
        'critical' => 'text-red-500',
        'na' => 'text-gray-400 dark:text-gray-600',
    ];

    $color = $colorMap[$status] ?? $colorMap['na'];
@endphp

@if (filled($label))
    <x-ui.tooltip :text="$label">
        <span {{ $attributes->merge(['class' => 'inline-flex']) }} role="img" aria-label="{{ $label }}">
            <x-dynamic-component
                :component="'icons.' . $icon"
                class="!h-4 !w-4 {{ $color }}"
                aria-hidden="true"
            />
        </span>
    </x-ui.tooltip>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex']) }} aria-hidden="true">
        <x-dynamic-component
            :component="'icons.' . $icon"
            class="h-4 w-4 {{ $color }}"
            aria-hidden="true"
        />
    </span>
@endif
