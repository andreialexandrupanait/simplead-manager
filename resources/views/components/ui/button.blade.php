@props([
    'variant' => 'primary',
    'size' => 'md',
])

@php
$classes = match($variant) {
    'primary'   => 'bg-purple-600 text-white hover:bg-purple-700 focus:ring-purple-500',
    'secondary' => 'bg-gray-100 text-gray-700 hover:bg-gray-200 focus:ring-gray-500',
    'danger'    => 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
    'ghost'     => 'bg-transparent text-gray-600 hover:bg-gray-100 focus:ring-gray-500',
};

$sizes = match($size) {
    'sm' => 'px-3 py-1.5 text-sm',
    'md' => 'px-4 py-2 text-sm',
    'lg' => 'px-6 py-3 text-base',
};
@endphp

<button {{ $attributes->merge([
    'class' => "inline-flex items-center justify-center gap-2 rounded-lg font-medium transition
                focus:outline-none focus:ring-2 focus:ring-offset-2
                disabled:opacity-50 disabled:cursor-not-allowed
                {$classes} {$sizes}"
]) }}>
    {{ $slot }}
</button>
