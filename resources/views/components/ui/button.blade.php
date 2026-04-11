@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
])

@php
$classes = match($variant) {
    'primary'   => 'bg-accent text-white hover:bg-accent-hover focus:ring-accent',
    'secondary' => 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 focus:ring-gray-500 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-gray-600',
    'danger'    => 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
    'ghost'     => 'bg-transparent text-gray-600 hover:bg-gray-100 focus:ring-gray-500 dark:text-gray-400 dark:hover:bg-gray-700',
};

$sizes = match($size) {
    'xs' => 'px-2 py-1 text-xs',
    'sm' => 'px-3 py-1.5 text-xs',
    'md' => 'px-4 py-2 text-sm',
    'lg' => 'px-6 py-3 text-base',
};

$baseClasses = "inline-flex items-center justify-center gap-2 rounded-lg font-medium transition
                focus:outline-none focus:ring-2 focus:ring-offset-2
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
