@props([
    'variant' => 'gray',
])

@php
$classes = match($variant) {
    'green'  => 'bg-green-50 text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/30',
    'yellow' => 'bg-yellow-50 text-yellow-800 ring-1 ring-inset ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/30',
    'orange' => 'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-400 dark:ring-orange-500/30',
    'red'    => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/30',
    'blue'   => 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/30',
    'gray'   => 'bg-gray-50 text-gray-700 ring-1 ring-inset ring-gray-500/20 dark:bg-gray-500/10 dark:text-gray-300 dark:ring-gray-500/30',
    'purple' => 'bg-accent-50 text-accent-700 ring-1 ring-inset ring-accent-600/20 dark:bg-accent-500/10 dark:text-accent-400 dark:ring-accent-500/30',
    default  => 'bg-gray-50 text-gray-700 ring-1 ring-inset ring-gray-500/20 dark:bg-gray-500/10 dark:text-gray-300 dark:ring-gray-500/30',
};
@endphp

<span {{ $attributes->merge([
    'class' => "inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {$classes}"
]) }}>
    {{ $slot }}
</span>
