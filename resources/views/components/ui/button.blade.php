@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
])

@php
// REFERINTA-VIZUALA .btn / .btn-p / .btn-d — subtle outline buttons on tinted
// backgrounds (no solid fills, no shadows): hierarchy from colour + 1px border.
$classes = match($variant) {
    'primary'   => 'bg-accent-50 text-accent-700 border border-accent-500 hover:bg-accent-100 focus-visible:ring-accent-500 dark:bg-accent-500/10 dark:text-accent-300',
    'secondary' => 'bg-transparent text-gray-600 border border-gray-300 hover:bg-gray-50 hover:text-gray-900 focus-visible:ring-gray-400 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-800',
    'danger'    => 'bg-red-50 text-red-700 border border-red-500 hover:bg-red-100 focus-visible:ring-red-500 dark:bg-red-500/10 dark:text-red-300',
    'ghost'     => 'bg-transparent text-gray-600 hover:bg-gray-100 hover:text-gray-900 focus-visible:ring-gray-400 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white',
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
