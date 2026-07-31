@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
])

@php
// REFERINTA-VIZUALA .btn / .btn-p / .btn-d — outline buttons on tinted
// backgrounds, hierarchy from colour + 1px border.
//
// `primary` is the exception, and deliberately so. It was bg-accent-50 (#e8f1f9,
// almost white) with accent text: on a #f0f1f3 page it read as one more piece of
// furniture, and the action you were meant to take was the easiest thing to miss.
// It carries a solid fill now. The other three stay quiet — a page where
// everything is loud has no hierarchy at all, which is what the reference was
// protecting against.
$classes = match($variant) {
    'primary'   => 'bg-accent-600 text-white border border-accent-600 shadow-sm hover:bg-accent-700 hover:border-accent-700 focus-visible:ring-accent-500',
    'secondary' => 'bg-transparent text-gray-600 border border-gray-300 hover:bg-gray-50 hover:text-gray-900 focus-visible:ring-gray-400 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-800',
    'danger'    => 'bg-red-50 text-red-700 border border-red-500 hover:bg-red-100 focus-visible:ring-red-500 dark:bg-red-500/10 dark:text-red-300',
    'ghost'     => 'bg-transparent text-gray-600 hover:bg-gray-100 hover:text-gray-900 focus-visible:ring-gray-400 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white',
    // An unknown variant used to be an UnhandledMatchError — a 500 on a page
    // that was only ever going to be slightly wrong.
    default     => 'bg-transparent text-gray-600 border border-gray-300 hover:bg-gray-50 hover:text-gray-900 focus-visible:ring-gray-400 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-800',
};

$sizes = match($size) {
    'xs' => 'px-2 py-1 text-xs',
    'sm' => 'px-3 py-1.5 text-xs',
    'lg' => 'px-6 py-3 text-base',
    default => 'px-4 py-2 text-sm', // 'md'
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
