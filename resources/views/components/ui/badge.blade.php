@props([
    'variant' => 'gray',
])

@php
// REFERINTA-VIZUALA .badge — flat colour pills (no ring/border), 11px, tight padding.
$classes = match($variant) {
    'green'  => 'bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-400',
    'yellow' => 'bg-yellow-50 text-yellow-800 dark:bg-yellow-500/10 dark:text-yellow-400',
    'orange' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-400',
    'red'    => 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400',
    'blue'   => 'bg-accent-50 text-accent-700 dark:bg-accent-500/10 dark:text-accent-400',
    'gray'   => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
    'purple' => 'bg-accent-50 text-accent-700 dark:bg-accent-500/10 dark:text-accent-400',
    default  => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
};
@endphp

<span {{ $attributes->merge([
    'class' => "inline-flex items-center rounded-full px-[7px] py-px text-[11px] font-medium {$classes}"
]) }}>
    {{ $slot }}
</span>
