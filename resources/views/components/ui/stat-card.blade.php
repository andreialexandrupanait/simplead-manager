@props([
    'label',
    'value',
    'sublabel' => null,
    'icon' => null,
    // 'purple' remains as an alias because call sites use it, but it has rendered accent blue
    // since the July retheme — a variant named after a colour it does not draw is a small lie the
    // next person has to discover.
    'color' => 'accent',
])

{{-- WHICH ONE: use this for a standalone figure — icon, tinted background, label and value. For a
     dense row inside an x-ui.module-card on the per-site overview, use x-ui.stat-box. --}}
@php
$bgColor = match($color) {
    'accent', 'purple' => 'bg-accent-50 dark:bg-accent-500/10',
    'green' => 'bg-green-50 dark:bg-green-500/10',
    'red' => 'bg-red-50 dark:bg-red-500/10',
    'yellow' => 'bg-yellow-50 dark:bg-yellow-500/10',
    'orange' => 'bg-orange-50 dark:bg-orange-500/10',
    'blue' => 'bg-blue-50 dark:bg-blue-500/10',
    'gray' => 'bg-gray-100 dark:bg-gray-700',
    default => 'bg-accent-50 dark:bg-accent-500/10',
};
$iconColor = match($color) {
    'accent', 'purple' => 'text-accent-600 dark:text-accent-400',
    'green' => 'text-green-600 dark:text-green-400',
    'red' => 'text-red-600 dark:text-red-400',
    'yellow' => 'text-yellow-600 dark:text-yellow-400',
    'orange' => 'text-orange-600 dark:text-orange-400',
    'blue' => 'text-blue-600 dark:text-blue-400',
    'gray' => 'text-gray-600 dark:text-gray-300',
    default => 'text-accent-600 dark:text-accent-400',
};
@endphp

<x-ui.card class="!p-5" {{ $attributes }}>
    @if($icon)
        <div class="flex h-10 w-10 items-center justify-center rounded-full {{ $bgColor }} mb-4">
            <x-dynamic-component :component="'icons.' . $icon" class="h-5 w-5 {{ $iconColor }}" aria-hidden="true" />
        </div>
    @endif
    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ $label }}</p>
    <p class="mt-1 text-3xl font-semibold text-gray-900 dark:text-white tracking-tight">{{ $value }}</p>
    @if($sublabel)
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $sublabel }}</p>
    @endif
</x-ui.card>
