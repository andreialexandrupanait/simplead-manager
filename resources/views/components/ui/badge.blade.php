@props([
    'variant' => 'gray',
])

@php
$classes = match($variant) {
    'green'  => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
    'yellow' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
    'orange' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
    'red'    => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400',
    'blue'   => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
    'gray'   => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400',
    'purple' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-400',
    default  => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400',
};
@endphp

<span {{ $attributes->merge([
    'class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {$classes}"
]) }}>
    {{ $slot }}
</span>
