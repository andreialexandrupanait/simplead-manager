@props([
    'variant' => 'gray',
])

@php
$classes = match($variant) {
    'green'  => 'bg-green-100 text-green-700',
    'yellow' => 'bg-yellow-100 text-yellow-700',
    'orange' => 'bg-orange-100 text-orange-700',
    'red'    => 'bg-red-100 text-red-700',
    'blue'   => 'bg-blue-100 text-blue-700',
    'gray'   => 'bg-gray-100 text-gray-700',
    'purple' => 'bg-purple-100 text-purple-700',
    default  => 'bg-gray-100 text-gray-700',
};
@endphp

<span {{ $attributes->merge([
    'class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {$classes}"
]) }}>
    {{ $slot }}
</span>
