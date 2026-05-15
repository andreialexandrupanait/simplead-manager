@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
])

@php
$classes = match($variant) {
    'primary'   => 'bg-accent-500 text-white hover:bg-accent-600 focus-visible:ring-accent-500 shadow-sm',
    'secondary' => 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 hover:border-gray-400 focus-visible:ring-gray-400 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-gray-600',
    'danger'    => 'bg-red-600 text-white hover:bg-red-700 focus-visible:ring-red-500 shadow-sm',
    'ghost'     => 'bg-transparent text-gray-600 hover:bg-gray-100 hover:text-gray-900 focus-visible:ring-gray-400 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white',
};

$sizes = match($size) {
    'xs' => 'px-2 py-1 text-xs',
    'sm' => 'px-3 py-1.5 text-xs',
    'md' => 'px-4 py-2 text-sm',
    'lg' => 'px-6 py-3 text-base',
};

$baseClasses = "inline-flex items-center justify-center gap-2 rounded-lg font-medium transition
                focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900
                disabled:opacity-50 disabled:cursor-not-allowed
                {$classes} {$sizes}";
@endphp

@if($href)
<a href="{{ $href }}" {{ $attributes->merge(['class' => $baseClasses]) }}>
    {{ $slot }}
</a>
@else
<button {{ $attributes->merge(['class' => $baseClasses]) }}>
    {{ $slot }}
</button>
@endif
